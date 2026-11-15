import { expect, test } from '@playwright/test';
import type { APIRequestContext, Browser, BrowserContext, Page } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { seriousViolations } from '../support/axe';
import { authFile, SCREENSHOT_DIR } from '../support/env';
import { GalleryFixtures, gradientPng, uniquePrefix } from '../support/galleries';

// The magazine layout (rule engine rows) as a visitor sees it: every row spans the container, slots tile the row
// without holes or overlaps, DOM and Tab order stay the stored order, the lightbox walks that order, narrow
// containers collapse to a stack, RTL mirrors, nothing shifts, axe is clean (nested list semantics).

test.use({ storageState: authFile('administrator') });

const prefix = uniquePrefix('magazine');
const GAP = 8;

/** Mixed orientations: landscapes, portraits, squares and a panorama. */
const SHAPES: Array<[number, number]> = [
  [600, 400],
  [400, 600],
  [500, 500],
  [640, 360],
  [360, 540],
  [600, 400],
  [900, 300],
  [600, 450],
  [450, 600],
  [500, 500],
  [600, 400],
  [400, 600],
  [640, 360],
  [600, 400],
];
type Rgb = [number, number, number];
const COLORS: Array<[Rgb, Rgb]> = [
  [
    [230, 150, 70],
    [90, 60, 130],
  ],
  [
    [70, 160, 200],
    [20, 50, 110],
  ],
  [
    [150, 200, 90],
    [20, 100, 70],
  ],
  [
    [235, 110, 120],
    [110, 30, 80],
  ],
  [
    [240, 200, 90],
    [170, 80, 40],
  ],
  [
    [120, 140, 230],
    [50, 40, 120],
  ],
  [
    [90, 200, 180],
    [20, 90, 110],
  ],
];

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

async function shot(page: Page, name: string, gallery = 0): Promise<void> {
  await mkdir(SCREENSHOT_DIR, { recursive: true });
  await loadAll(page);
  await page
    .locator('.swg-gallery')
    .nth(gallery)
    .screenshot({ path: resolve(SCREENSHOT_DIR, `${name}.png`) });
}

/** The theme column is narrow (about 650px): let the gallery use the whole viewport. */
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

interface RowData {
  template: string;
  row: Box;
  slots: Box[];
  links: string[];
}

interface Magazine {
  list: Box;
  rows: RowData[];
  gap: number;
  overflow: boolean;
}

async function magazine(page: Page, gallery = 0): Promise<Magazine> {
  return page
    .locator('.swg-gallery')
    .nth(gallery)
    .evaluate((root) => {
      const box = (el: Element): Box => {
        const r = el.getBoundingClientRect();
        return { left: r.left, top: r.top, right: r.right, bottom: r.bottom };
      };
      const rows = Array.from(root.querySelectorAll('li.swg-row')).map((li) => ({
        template: Array.from(li.classList)
          .find((name) => name.startsWith('swg-tpl-'))
          ?.slice('swg-tpl-'.length) as string,
        row: box(li.querySelector('.swg-row-items') as Element),
        slots: Array.from(li.querySelectorAll('.swg-item')).map(box),
        links: Array.from(li.querySelectorAll('a.swg-link')).map(
          (a) => a.getAttribute('href') ?? '',
        ),
      }));
      return {
        list: box(root.querySelector('.swg-items') as Element),
        rows,
        gap: parseFloat(getComputedStyle(root).getPropertyValue('--swg-gap')) || 0,
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
  settings: Record<string, unknown> = {},
  focalIndex = -1,
): Promise<number> {
  const ids: number[] = [];
  for (let index = 0; index < count; index += 1) {
    const [width, height] = SHAPES[(index * 5) % SHAPES.length] as [number, number];
    const [from, to] = COLORS[index % COLORS.length] as [Rgb, Rgb];
    const image = await site.fixtures.uploadImage(
      `${prefix} ${title} ${index + 1}`,
      gradientPng(from, to, width, height),
    );
    await setAlt(image.id, `${title} photo ${index + 1}`);
    ids.push(image.id);
  }
  const gallery = await site.fixtures.create({
    title: `${prefix} ${title}`,
    status: 'publish',
    layout: 'magazine',
    settings: { gap: GAP, ...settings },
  });
  await site.fixtures.assign(gallery.id, ids);
  if (focalIndex >= 0) {
    const items = await site.fixtures.items(gallery.id);
    await site.fixtures.setItemSettings(gallery.id, (items[focalIndex] as { id: number }).id, {
      focal: { x: 10, y: 90 },
    });
  }
  return gallery.id;
}

const MAIN_COUNT = 14;
const LONG_COUNT = 24;
const FOCAL_INDEX = 3;

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ browser }) => {
  test.setTimeout(300_000);
  adminContext = await browser.newContext({ storageState: authFile('administrator') });
  const page = await adminContext.newPage();
  const fixtures = await GalleryFixtures.forPage(page);
  const bootstrap = await page.evaluate(() => {
    const value = (window as unknown as { swgAdmin: { restRoot: string; nonce: string } }).swgAdmin;
    return { restRoot: value.restRoot, nonce: value.nonce };
  });
  site = { fixtures, request: page.request, nonce: bootstrap.nonce, restRoot: bootstrap.restRoot };

  mainId = await createGallery('main', MAIN_COUNT, {}, FOCAL_INDEX);
  const long = await createGallery('long', LONG_COUNT, { rhythm: 'bold', gap: 12, radius: 8 });
  const small = await createGallery('small', 9, { rhythm: 'calm', rowHeight: 200 });
  urls.main = await createPost('main', `[sw_gallery id="${mainId}"]`);
  urls.block = await createPost('block', `<!-- wp:sw-gallery/gallery {"galleryId":${mainId}} /-->`);
  urls.long = await createPost('long', `[sw_gallery id="${long}"]`);
  urls.small = await createPost('small', `[sw_gallery id="${small}"]`);
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

/** Rows of a wide container: span the box, tile without holes or overlaps. */
function expectTiledRows(result: Magazine): void {
  expect(result.rows.length).toBeGreaterThan(1);
  for (const [index, data] of result.rows.entries()) {
    const label = `row ${index + 1} (${data.template})`;
    expect(
      Math.abs(data.row.left - result.list.left),
      `${label} starts at the container`,
    ).toBeLessThan(1);
    expect(
      Math.abs(data.row.right - result.list.right),
      `${label} spans the container`,
    ).toBeLessThan(1);
    for (const slot of data.slots) {
      expect(slot.left, label).toBeGreaterThanOrEqual(data.row.left - 1);
      expect(slot.right, label).toBeLessThanOrEqual(data.row.right + 1);
      expect(slot.top, label).toBeGreaterThanOrEqual(data.row.top - 1);
      expect(slot.bottom, label).toBeLessThanOrEqual(data.row.bottom + 1);
    }
    for (const [i, a] of data.slots.entries()) {
      for (const b of data.slots.slice(i + 1)) expect(overlap(a, b), label).toBeLessThanOrEqual(1);
    }
    if (data.template === 'solo') continue;
    // No holes: the outer slots touch the row edges and the slots plus gaps cover the row.
    expect(Math.min(...data.slots.map((s) => s.left)) - data.row.left, label).toBeLessThan(1);
    expect(data.row.right - Math.max(...data.slots.map((s) => s.right)), label).toBeLessThan(1);
    expect(Math.min(...data.slots.map((s) => s.top)) - data.row.top, label).toBeLessThan(1);
    expect(data.row.bottom - Math.max(...data.slots.map((s) => s.bottom)), label).toBeLessThan(1);
    const covered = data.slots.reduce((total, box) => total + area(box), 0);
    const gaps =
      (data.slots.length - 1) * result.gap * Math.max(data.row.bottom - data.row.top, 1) * 1.5;
    expect(covered + gaps, `${label} has no hole`).toBeGreaterThan(area(data.row) * 0.97);
    for (const slot of data.slots) {
      expect(slot.right - slot.left, label).toBeGreaterThan(60);
      expect(slot.bottom - slot.top, label).toBeGreaterThan(60);
    }
  }
  // Rows are stacked in order, separated by the gap.
  for (const [index, data] of result.rows.slice(1).entries()) {
    const previous = result.rows[index] as RowData;
    expect(Math.abs(data.row.top - previous.row.bottom - result.gap)).toBeLessThan(1.5);
  }
}

for (const width of [1280, 768]) {
  test(`rows tile the container without holes or overlaps at ${width}px`, async ({ browser }) => {
    const context = await visitor(browser, { viewport: { width, height: 900 } });
    const page = await context.newPage();
    await page.goto(urls.main as string);
    if (width === 1280) await widen(page);
    await loadAll(page);
    const result = await magazine(page);
    expect(result.overflow, 'no horizontal overflow').toBe(false);
    expectTiledRows(result);
    expect(result.rows.flatMap((row) => row.links)).toHaveLength(MAIN_COUNT);
    expect(new Set(result.rows.map((row) => row.template)).size, 'variety').toBeGreaterThan(2);
    await shot(page, `magazine-${width}`);
    await context.close();
  });
}

test('a long gallery shows many templates and reads like a photo book', async ({ browser }) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.long as string);
  await widen(page);
  await loadAll(page);
  const result = await magazine(page);
  expectTiledRows(result);
  expect(result.rows.flatMap((row) => row.links)).toHaveLength(LONG_COUNT);
  expect(new Set(result.rows.map((row) => row.template)).size).toBeGreaterThanOrEqual(3);
  for (const [index, row] of result.rows.slice(1).entries()) {
    expect(row.template, 'never the same template twice in a row').not.toBe(
      (result.rows[index] as RowData).template,
    );
  }
  await shot(page, 'magazine-long-1280');
  await context.close();
});

test('the small gallery uses its own rhythm and row height', async ({ browser }) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.small as string);
  await widen(page);
  await loadAll(page);
  const result = await magazine(page);
  expectTiledRows(result);
  const rowHeights = result.rows.map((row) => row.row.bottom - row.row.top);
  // rowHeight 200: a triptych row would be 200px at 1200px width; the tallest row template is 2.5x that.
  expect(Math.max(...rowHeights)).toBeLessThan(
    (200 * 2.6 * (result.list.right - result.list.left)) / 1200 + 2,
  );
  await shot(page, 'magazine-small-1280');
  await context.close();
});

test('375px collapses every row to a stack of one or two columns', async ({ browser }) => {
  const context = await visitor(browser, { viewport: { width: 375, height: 800 } });
  const page = await context.newPage();
  await page.goto(urls.main as string);
  await loadAll(page);
  const result = await magazine(page);
  expect(result.overflow).toBe(false);
  const width = result.list.right - result.list.left;
  for (const data of result.rows) {
    expect(data.row.right - data.row.left).toBeLessThanOrEqual(width + 1);
    const lefts = new Set(data.slots.map((slot) => Math.round(slot.left)));
    expect(lefts.size, `${data.template} has at most two columns`).toBeLessThanOrEqual(2);
    for (const slot of data.slots) expect(slot.right - slot.left).toBeGreaterThan(100);
  }
  const display = await page
    .locator('.swg-gallery li.swg-row .swg-row-items')
    .first()
    .evaluate((el) => getComputedStyle(el).gridTemplateAreas);
  expect(display, 'no template areas in stack mode').toBe('none');
  await shot(page, 'magazine-375');
  await context.close();
});

test('the block renders the same rows and the DOM order is the stored order', async ({
  browser,
}) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const shortcode = await context.newPage();
  await shortcode.goto(urls.main as string);
  const block = await context.newPage();
  await block.goto(urls.block as string);
  await expect(block.locator('.swg-gallery.swg-layout-magazine')).toHaveCount(1);
  const classes = (page: Page): Promise<string[]> =>
    page.locator('.swg-gallery li.swg-row').evaluateAll((rows) => rows.map((el) => el.className));
  expect(await classes(block), 'same rows from the same revision').toEqual(
    await classes(shortcode),
  );
  const alts = await shortcode
    .locator('.swg-gallery .swg-link img')
    .evaluateAll((imgs) => imgs.map((img) => img.getAttribute('alt')));
  expect(alts).toEqual(Array.from({ length: MAIN_COUNT }, (_, i) => `main photo ${i + 1}`));
  await context.close();
});

test('Tab follows the DOM order and the lightbox navigates in that order', async ({ browser }) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.main as string);
  await loadAll(page);
  const order = await hrefs(page);
  expect(order).toHaveLength(MAIN_COUNT);

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
  await widen(page);
  await loadAll(page);
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
  await context.close();
});

test('RTL mirrors every row and keeps the DOM order', async ({ browser }) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.block as string);
  await widen(page);
  await loadAll(page);
  const order = await hrefs(page);
  const ltr = await magazine(page);
  await page.evaluate(() => {
    document.documentElement.dir = 'rtl';
  });
  const rtl = await magazine(page);
  expect(await hrefs(page), 'DOM order is untouched').toEqual(order);
  expect(rtl.rows.map((row) => row.template)).toEqual(ltr.rows.map((row) => row.template));
  for (const [index, data] of ltr.rows.entries()) {
    const mirrored = rtl.rows[index] as RowData;
    for (const [slotIndex, box] of data.slots.entries()) {
      const other = mirrored.slots[slotIndex] as Box;
      expect(
        Math.abs(other.left - mirrored.row.left - (data.row.right - box.right)),
        `row ${index + 1} slot ${slotIndex + 1}`,
      ).toBeLessThan(1.5);
      expect(Math.abs(other.top - box.top)).toBeLessThan(1);
    }
  }
  expectTiledRows(rtl);
  await shot(page, 'magazine-rtl');
  await context.close();
});

test('nothing shifts while the images load', async ({ browser }) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 4000 } });
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
  await expect(page.locator('.swg-gallery .swg-item')).toHaveCount(MAIN_COUNT);
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
  await context.close();
});

for (const scheme of ['light', 'dark'] as const) {
  test(`no serious axe violation, list semantics included (${scheme})`, async ({ browser }) => {
    const context = await visitor(browser, { colorScheme: scheme });
    const page = await context.newPage();
    await page.goto(urls.main as string);
    await loadAll(page);
    expect(await seriousViolations(page, '.swg-gallery')).toEqual([]);
    await context.close();
  });
}
