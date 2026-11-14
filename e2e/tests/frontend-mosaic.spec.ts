import { expect, test } from '@playwright/test';
import type { APIRequestContext, Browser, BrowserContext, Page } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { seriousViolations } from '../support/axe';
import { authFile, PLUGIN_PAGE, SCREENSHOT_DIR } from '../support/env';
import { GalleryFixtures, tinyPngSized, uniquePrefix } from '../support/galleries';

// The mosaic layout (treemap LayoutPlan) as a visitor sees it: the tiles fill the container, areas follow the
// emphasis, DOM and Tab order stay the stored order, the lightbox opens from a tile, RTL mirrors the plan, nothing
// shifts, axe is clean and the focal point reaches object-position. Fixtures are created through the REST API.

test.use({ storageState: authFile('administrator') });

const prefix = uniquePrefix('mosaic');
const GAP = 6;

/** Eleven pictures of VERY different aspect ratios: wide, tall, square, panorama, portrait. */
const SHAPES: Array<[number, number]> = [
  [900, 600],
  [300, 600],
  [800, 800],
  [1200, 400],
  [400, 700],
  [900, 600],
  [600, 800],
  [1000, 500],
  [500, 500],
  [320, 640],
  [800, 600],
];
/** Emphasis per picture (index = DOM position): item 1 and 8 extra large, 5 and 10 large. */
const EMPHASIS = [3, 1, 1, 1, 2, 1, 1, 3, 1, 2, 1];
/** Item 4 (index 3) keeps the left, lower part of its panorama. */
const FOCAL_INDEX = 3;
const COLORS: Array<[number, number, number]> = [
  [200, 60, 60],
  [60, 160, 90],
  [60, 100, 200],
  [190, 140, 40],
  [140, 70, 180],
  [40, 160, 170],
  [210, 100, 150],
  [110, 120, 130],
  [230, 120, 40],
  [90, 90, 200],
  [60, 140, 60],
];
/** Seven pictures where four are extra large: a deliberately hard composition. */
const EXTREME_EMPHASIS = [3, 3, 1, 3, 1, 3, 1];

interface Site {
  fixtures: GalleryFixtures;
  request: APIRequestContext;
  nonce: string;
  restRoot: string;
}

let site: Site;
let adminContext: BrowserContext;
const postIds: number[] = [];
const urls: Record<string, string> = {};
let mainId = 0;

async function createPost(title: string, content: string): Promise<string> {
  const response = await site.request.post(site.restRoot.replace('swg/v1', 'wp/v2') + 'posts', {
    headers: { 'X-WP-Nonce': site.nonce },
    data: { title: `${prefix} ${title}`, status: 'publish', content },
  });
  expect(response.status(), await response.text()).toBe(201);
  const body = (await response.json()) as { id: number; link: string };
  postIds.push(body.id);
  return body.link;
}

async function setAlt(attachmentId: number, alt: string): Promise<void> {
  const response = await site.request.post(
    site.restRoot.replace('swg/v1', 'wp/v2') + `media/${attachmentId}`,
    { headers: { 'X-WP-Nonce': site.nonce }, data: { alt_text: alt } },
  );
  expect(response.status(), await response.text()).toBe(200);
}

async function visitor(browser: Browser, options: Record<string, unknown> = {}) {
  return browser.newContext({ storageState: { cookies: [], origins: [] }, ...options });
}

async function loadAll(page: Page): Promise<void> {
  await page.evaluate(async () => {
    const imgs = Array.from(document.querySelectorAll<HTMLImageElement>('.swg-link img'));
    for (const img of imgs) img.loading = 'eager';
    await Promise.all(imgs.map((img) => img.decode().catch(() => undefined)));
    const deadline = Date.now() + 8000;
    while (Date.now() < deadline && !imgs.every((img) => img.complete && img.naturalWidth > 0)) {
      await new Promise((resolveWait) => setTimeout(resolveWait, 50));
    }
    await document.fonts.ready;
  });
}

async function shot(page: Page, name: string): Promise<void> {
  await mkdir(SCREENSHOT_DIR, { recursive: true });
  await loadAll(page);
  await page
    .locator('.swg-gallery')
    .first()
    .screenshot({ path: resolve(SCREENSHOT_DIR, `${name}.png`) });
}

/** The theme column is narrow (about 650px): let the gallery use the whole viewport so the wide variant can show. */
async function widen(page: Page): Promise<void> {
  await page.addStyleTag({
    content:
      '.swg-shortcode > *, .entry-content > .swg-gallery, .swg-gallery { max-width: none !important; width: 100% !important; margin-inline: 0 !important; } .swg-shortcode, .entry-content { max-width: none !important; }',
  });
}

interface Box {
  left: number;
  top: number;
  right: number;
  bottom: number;
}

interface Plan {
  list: Box;
  cells: Box[];
  links: Box[];
  gap: number;
  ar: string;
  position: string;
  overflow: boolean;
}

/** Cells are the `li` boxes (the plan rectangles including half a gap), links the visible tiles. */
async function plan(page: Page, gallery = 0): Promise<Plan> {
  return page
    .locator('.swg-gallery')
    .nth(gallery)
    .evaluate((root) => {
      const list = root.querySelector('.swg-items') as HTMLElement;
      const box = (el: Element): Box => {
        const r = el.getBoundingClientRect();
        return { left: r.left, top: r.top, right: r.right, bottom: r.bottom };
      };
      const style = getComputedStyle(list);
      return {
        list: box(list),
        cells: Array.from(root.querySelectorAll('.swg-item')).map(box),
        links: Array.from(root.querySelectorAll('.swg-link')).map(box),
        gap: parseFloat(getComputedStyle(root).getPropertyValue('--swg-gap')) || 0,
        ar: style.getPropertyValue('--swg-ar').trim(),
        position: getComputedStyle(root.querySelector('.swg-item') as Element).position,
        overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
      };
    });
}

const area = (b: Box): number => (b.right - b.left) * (b.bottom - b.top);

function overlap(a: Box, b: Box): number {
  const w = Math.min(a.right, b.right) - Math.max(a.left, b.left);
  const h = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
  return w > 0 && h > 0 ? Math.min(w, h) : 0;
}

async function hrefs(page: Page): Promise<string[]> {
  return page
    .locator('.swg-gallery a.swg-link')
    .evaluateAll((links) => links.map((a) => a.getAttribute('href') ?? ''));
}

function fileStem(href: string): string {
  return (href.split('/').pop() ?? '').replace(/\.[a-z0-9]+$/i, '');
}

async function createGallery(
  title: string,
  count: number,
  emphasis: number[],
  settings: Record<string, unknown> = {},
): Promise<number> {
  const ids: number[] = [];
  for (let index = 0; index < count; index += 1) {
    const [width, height] = SHAPES[index % SHAPES.length] as [number, number];
    const image = await site.fixtures.uploadImage(
      `${prefix} ${title} ${index + 1}`,
      tinyPngSized(COLORS[index % COLORS.length] as [number, number, number], width, height),
    );
    await setAlt(image.id, `${title} photo ${index + 1}`);
    ids.push(image.id);
  }
  const gallery = await site.fixtures.create({
    title: `${prefix} ${title}`,
    status: 'publish',
    layout: 'mosaic',
    settings: { gap: GAP, ...settings },
  });
  await site.fixtures.assign(gallery.id, ids);
  const items = await site.fixtures.items(gallery.id);
  for (const [index, item] of items.entries()) {
    const level = emphasis[index % emphasis.length] ?? 1;
    const settingsMap: Record<string, unknown> = {};
    if (level !== 1) settingsMap.emphasis = level;
    if (title === 'main' && index === FOCAL_INDEX) settingsMap.focal = { x: 10, y: 90 };
    if (Object.keys(settingsMap).length > 0) {
      await site.fixtures.setItemSettings(gallery.id, item.id, settingsMap);
    }
  }
  return gallery.id;
}

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ browser }) => {
  test.setTimeout(240_000);
  adminContext = await browser.newContext({ storageState: authFile('administrator') });
  const page = await adminContext.newPage();
  const fixtures = await GalleryFixtures.forPage(page);
  const bootstrap = await page.evaluate(() => {
    const value = (window as unknown as { swgAdmin: { restRoot: string; nonce: string } }).swgAdmin;
    return { restRoot: value.restRoot, nonce: value.nonce };
  });
  site = { fixtures, request: page.request, nonce: bootstrap.nonce, restRoot: bootstrap.restRoot };

  mainId = await createGallery('main', 11, EMPHASIS);
  const extreme = await createGallery('extreme', 7, EXTREME_EMPHASIS);
  const thirteen = await createGallery('thirteen', 13, [1, 2, 1, 1, 3, 1, 1, 2, 1, 1, 1, 3, 1], {
    gap: 12,
    radius: 10,
    ratio: '3:2',
  });
  urls.main = await createPost('main', `[sw_gallery id="${mainId}"]`);
  urls.block = await createPost('block', `<!-- wp:sw-gallery/gallery {"galleryId":${mainId}} /-->`);
  urls.extreme = await createPost('extreme', `[sw_gallery id="${extreme}"]`);
  urls.thirteen = await createPost('thirteen', `[sw_gallery id="${thirteen}"]`);
});

test.afterAll(async () => {
  for (const id of postIds) {
    await site.request.delete(site.restRoot.replace('swg/v1', 'wp/v2') + `posts/${id}?force=true`, {
      headers: { 'X-WP-Nonce': site.nonce },
    });
  }
  await site.fixtures.purge(prefix);
  await site.fixtures.purgeMedia(prefix);
  await adminContext.close();
});

/** Variant the stylesheet must have chosen for a container width (container queries 480 / 720 / 1024). */
function expectedRatio(width: number): string | null {
  if (width < 480) return null;
  if (width < 720) return '0.8';
  if (width < 1024) return '1';
  return '1.6';
}

for (const width of [1280, 768, 375]) {
  test(`the tiles fill the container without overlaps at ${width}px (375 = grid fallback)`, async ({
    browser,
  }) => {
    const context = await visitor(browser, { viewport: { width, height: 900 } });
    const page = await context.newPage();
    await page.goto(urls.main as string);
    await loadAll(page);
    const result = await plan(page);
    expect(result.overflow, 'no horizontal overflow').toBe(false);
    expect(result.cells).toHaveLength(SHAPES.length);
    const container = result.list.right - result.list.left - result.gap;
    const expected = expectedRatio(container);

    if (expected === null) {
      expect(result.position, 'below 480px the list is a plain grid').not.toBe('absolute');
      await shot(page, `mosaic-${width}`);
      await context.close();
      return;
    }
    expect(result.position).toBe('absolute');
    expect(result.ar, 'the variant follows the container width').toBe(expected);
    // The cells tile the (gap-widened) list box exactly: union = list, no overlaps beyond 1px.
    const sum = result.cells.reduce((total, box) => total + area(box), 0);
    expect(
      Math.abs(sum - area(result.list)) / area(result.list),
      'cells cover the box',
    ).toBeLessThan(0.004);
    for (const box of result.cells) {
      expect(box.left).toBeGreaterThanOrEqual(result.list.left - 1);
      expect(box.right).toBeLessThanOrEqual(result.list.right + 1);
      expect(box.top).toBeGreaterThanOrEqual(result.list.top - 1);
      expect(box.bottom).toBeLessThanOrEqual(result.list.bottom + 1);
    }
    for (const [i, a] of result.cells.entries()) {
      for (const b of result.cells.slice(i + 1)) expect(overlap(a, b)).toBeLessThanOrEqual(1);
    }
    // The visible tiles are inset by half a gap: the outer edge is flush with the container.
    const lefts = result.links.map((box) => box.left);
    const rights = result.links.map((box) => box.right);
    expect(Math.abs(Math.min(...lefts) - (result.list.left + result.gap / 2))).toBeLessThan(1);
    expect(Math.abs(Math.max(...rights) - (result.list.right - result.gap / 2))).toBeLessThan(1);
    // No sliver: every tile is at least 60px on both sides at these widths.
    for (const box of result.links) {
      expect(box.right - box.left).toBeGreaterThan(60);
      expect(box.bottom - box.top).toBeGreaterThan(60);
    }
    await shot(page, `mosaic-${width}`);
    await context.close();
  });
}

for (const [viewport, ratio] of [
  [1440, '1.6'],
  [900, '1'],
] as const) {
  test(`a wide container picks the ${ratio === '1.6' ? 'wide 16:10' : 'medium 1:1'} variant (viewport ${viewport}px)`, async ({
    browser,
  }) => {
    const context = await visitor(browser, { viewport: { width: viewport, height: 900 } });
    const page = await context.newPage();
    await page.goto(urls.main as string);
    await widen(page);
    await loadAll(page);
    const result = await plan(page);
    expect(result.ar).toBe(ratio);
    expect(result.overflow).toBe(false);
    const sum = result.cells.reduce((total, box) => total + area(box), 0);
    expect(Math.abs(sum - area(result.list)) / area(result.list)).toBeLessThan(0.004);
    const listWidth = result.list.right - result.list.left;
    const listHeight = result.list.bottom - result.list.top;
    expect(Math.abs(listWidth / listHeight - Number(ratio)), 'container aspect ratio').toBeLessThan(
      0.02,
    );
    await shot(page, `mosaic-wide-${viewport}`);
    await context.close();
  });
}

test('areas follow the emphasis (1 : 1.8 : 3)', async ({ browser }) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.main as string);
  await loadAll(page);
  const result = await plan(page);
  const unit = result.cells.filter((_, index) => EMPHASIS[index] === 1).map(area);
  const mean = unit.reduce((a, b) => a + b, 0) / unit.length;
  for (const [index, box] of result.cells.entries()) {
    const weight = ({ 1: 1, 2: 1.8, 3: 3 } as Record<number, number>)[EMPHASIS[index] as number];
    expect(Math.abs(area(box) / mean - (weight as number)), `item ${index + 1}`).toBeLessThan(0.1);
  }
  await context.close();
});

test('the block renders the same mosaic and the DOM order is the stored order', async ({
  browser,
}) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const shortcode = await context.newPage();
  await shortcode.goto(urls.main as string);
  const block = await context.newPage();
  await block.goto(urls.block as string);
  await expect(block.locator('.swg-gallery.swg-layout-mosaic')).toHaveCount(1);
  const styles = (page: Page): Promise<string[]> =>
    page
      .locator('.swg-gallery .swg-item')
      .evaluateAll((items) => items.map((el) => el.getAttribute('style') ?? ''));
  expect(await styles(block), 'same plan from the same revision').toEqual(await styles(shortcode));
  const stored = (await site.fixtures.items(mainId)).map((item) => item.attachmentId);
  const alts = await shortcode
    .locator('.swg-gallery .swg-link img')
    .evaluateAll((imgs) => imgs.map((img) => img.getAttribute('alt')));
  expect(alts).toEqual(SHAPES.map((_, index) => `main photo ${index + 1}`));
  expect(stored).toHaveLength(SHAPES.length);
  await context.close();
});

test('Tab follows the DOM order and the lightbox navigates in that order', async ({ browser }) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.main as string);
  await loadAll(page);
  const order = await hrefs(page);
  expect(order).toHaveLength(SHAPES.length);

  await page.locator('.swg-gallery a.swg-link').first().focus();
  const focused: string[] = [];
  for (let i = 0; i < order.length; i += 1) {
    focused.push(await page.evaluate(() => document.activeElement?.getAttribute('href') ?? ''));
    await page.keyboard.press('Tab');
  }
  expect(focused, 'keyboard focus order equals DOM order').toEqual(order);

  await page.locator('.swg-gallery a.swg-link').nth(2).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog).toContainText(new RegExp(`3\\s*(of|von)\\s*${order.length}`));
  await page.keyboard.press('ArrowRight');
  await expect(dialog).toContainText(new RegExp(`4\\s*(of|von)\\s*${order.length}`));
  await expect(dialog.locator('img').first()).toHaveAttribute(
    'src',
    new RegExp(fileStem(order[3] as string).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')),
  );
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(page.locator('.swg-gallery a.swg-link').nth(2)).toBeFocused();
  await context.close();
});

test('a focused tile shows an inner focus ring that is not clipped', async ({ browser }) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.main as string);
  await loadAll(page);
  await page.keyboard.press('Tab');
  await page.locator('.swg-gallery a.swg-link').nth(1).focus();
  await page.keyboard.press('Shift+Tab');
  await page.keyboard.press('Tab');
  const ring = await page
    .locator('.swg-gallery a.swg-link')
    .nth(1)
    .evaluate((el) => {
      const style = getComputedStyle(el, '::after');
      return { width: style.outlineWidth, offset: style.outlineOffset, style: style.outlineStyle };
    });
  expect(ring.style).toBe('solid');
  expect(parseFloat(ring.width)).toBeGreaterThanOrEqual(2);
  expect(parseFloat(ring.offset), 'drawn inside the tile').toBeLessThan(0);
  await page
    .locator('.swg-gallery a.swg-link')
    .nth(1)
    .screenshot({
      path: resolve(SCREENSHOT_DIR, 'mosaic-focus.png'),
    });
  await context.close();
});

test('RTL mirrors the plan and keeps the DOM order', async ({ browser }) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.block as string);
  await loadAll(page);
  const order = await hrefs(page);
  const ltr = await plan(page);
  await page.evaluate(() => {
    document.documentElement.dir = 'rtl';
  });
  const rtl = await plan(page);
  expect(await hrefs(page), 'DOM order is untouched').toEqual(order);
  const width = ltr.list.right - ltr.list.left;
  for (const [index, box] of ltr.cells.entries()) {
    const mirrored = rtl.cells[index] as Box;
    expect(Math.abs(mirrored.left - rtl.list.left - (ltr.list.right - box.right))).toBeLessThan(
      1.5,
    );
    expect(Math.abs(mirrored.right - mirrored.left - (box.right - box.left))).toBeLessThan(1);
    expect(Math.abs(mirrored.top - box.top)).toBeLessThan(1);
  }
  expect(width).toBeGreaterThan(0);
  const first = rtl.links[0] as Box;
  expect(
    Math.abs(first.right - (rtl.list.right - rtl.gap / 2)),
    'first tile at the right edge',
  ).toBeLessThan(2);
  await shot(page, 'mosaic-rtl');
  await context.close();
});

test('nothing shifts while the images load', async ({ browser }) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 2600 } });
  const page = await context.newPage();
  let release: () => void = () => undefined;
  const gate = new Promise<void>((resolveGate) => {
    release = resolveGate;
  });
  await page.route(/\/wp-content\/uploads\//, async (route) => {
    await gate;
    await route.continue();
  });
  await page.goto(urls.main as string, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.swg-gallery .swg-item')).toHaveCount(SHAPES.length);
  const positions = (): Promise<number[][]> =>
    page.locator('.swg-gallery .swg-item').evaluateAll((items) =>
      items.map((el) => {
        const r = el.getBoundingClientRect();
        return [r.left, r.top, r.width, r.height].map((n) => Math.round(n * 10) / 10);
      }),
    );
  const before = await positions();
  expect(before.every(([, , width, height]) => (width ?? 0) > 0 && (height ?? 0) > 0)).toBe(true);
  release();
  await page.waitForLoadState('load');
  await loadAll(page);
  expect(await positions(), 'every cell keeps its box once the images are there').toEqual(before);
  await context.close();
});

test('the focal point moves object-position (and the default stays centred)', async ({
  browser,
}) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.main as string);
  const positions = await page
    .locator('.swg-gallery .swg-link img')
    .evaluateAll((imgs) => imgs.map((img) => getComputedStyle(img).objectPosition));
  expect(positions[FOCAL_INDEX]).toBe('10% 90%');
  expect(positions[0]).toBe('50% 50%');
  await expect(page.locator('.swg-gallery .swg-item').nth(FOCAL_INDEX)).toHaveAttribute(
    'style',
    /--swg-fx:10%;--swg-fy:90%/,
  );
  await context.close();
});

for (const scheme of ['light', 'dark'] as const) {
  test(`no serious axe violation (${scheme})`, async ({ browser }) => {
    const context = await visitor(browser, { colorScheme: scheme });
    const page = await context.newPage();
    await page.goto(urls.main as string);
    await loadAll(page);
    expect(await seriousViolations(page, '.swg-gallery')).toEqual([]);
    await context.close();
  });
}

test('a gallery with extreme emphasis stays a calm mosaic', async ({ browser }) => {
  for (const width of [1280, 768]) {
    const context = await visitor(browser, { viewport: { width, height: 900 } });
    const page = await context.newPage();
    await page.goto(urls.extreme as string);
    if (width === 1280) await widen(page);
    await loadAll(page);
    const result = await plan(page);
    expect(result.overflow).toBe(false);
    const sum = result.cells.reduce((total, box) => total + area(box), 0);
    expect(Math.abs(sum - area(result.list)) / area(result.list)).toBeLessThan(0.004);
    for (const box of result.links) {
      const ratio = (box.right - box.left) / (box.bottom - box.top);
      expect(ratio, 'no sliver').toBeGreaterThan(0.25);
      expect(ratio, 'no sliver').toBeLessThan(4);
    }
    await shot(page, `mosaic-extreme-${width}`);
    await context.close();
  }
});

test('thirteen pictures with gap 12, radius 10 and a 3:2 wide shape', async ({ browser }) => {
  const context = await visitor(browser, { viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.thirteen as string);
  await widen(page);
  await loadAll(page);
  const result = await plan(page);
  expect(result.gap).toBe(12);
  expect(result.ar).toBe('1.5');
  const radius = await page
    .locator('.swg-gallery .swg-link')
    .first()
    .evaluate((el) => getComputedStyle(el).borderTopLeftRadius);
  expect(radius).toBe('10px');
  await shot(page, 'mosaic-thirteen-1440');
  await context.close();
});

test('the editor lists the layout and shows the per-image settings form', async ({ page }) => {
  await page.goto(`${PLUGIN_PAGE}#/galleries/${mainId}`);
  const root = page.locator('#swg-admin-root');
  await expect(root.locator('.swg-shell')).toBeVisible();
  await root.locator('.swg-seg').getByText('Layout & settings', { exact: true }).click();
  const panel = root.locator('.swg-lsp');
  await expect(panel.getByText('Shape of the wide mosaic', { exact: true }).first()).toBeVisible();
  await panel.getByLabel('Layout', { exact: true }).click();
  await expect(page.getByRole('option', { name: 'Mosaic', exact: true })).toBeVisible();
  await page.keyboard.press('Escape');

  await root.locator('[role="option"]').first().dblclick();
  const sheet = page.getByRole('dialog');
  await expect(sheet.getByText('Layout settings for this image')).toBeVisible();
  await expect(sheet.getByText('Size in the mosaic').first()).toBeVisible();
  await expect(sheet.getByText('Focal point, horizontal (%)').first()).toBeVisible();
  await expect(sheet.locator('input[type="range"]')).toHaveCount(2);
});

test('item settings stored through the REST API reach the page', async ({ browser }) => {
  const items = await site.fixtures.items(mainId);
  await site.fixtures.setItemSettings(mainId, (items[1] as { id: number }).id, {
    emphasis: 2,
    focal: { x: 70, y: 20 },
  });
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const visit = await context.newPage();
  await visit.goto(urls.main as string);
  await expect(visit.locator('.swg-gallery .swg-item').nth(1)).toHaveAttribute(
    'style',
    /--swg-fx:70%;--swg-fy:20%/,
  );
  await context.close();
});
