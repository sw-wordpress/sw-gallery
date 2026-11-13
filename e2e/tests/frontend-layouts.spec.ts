import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { APIRequestContext, Browser, BrowserContext, Page } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { authFile, PLUGIN_PAGE, SCREENSHOT_DIR } from '../support/env';
import { GalleryFixtures, tinyPngSized, uniquePrefix } from '../support/galleries';

// The justified and masonry layouts as a visitor sees them (cookie-less contexts): geometry in a real browser, behaviour
// without JavaScript, RTL, no layout shift, keyboard order, the lightbox (mouse, keyboard, touch), pagination, axe and
// screenshots. Fixtures are created through the REST API as administrator.

test.use({ storageState: authFile('administrator') });

const prefix = uniquePrefix('layouts');
const ROW_HEIGHT = 150;
const GAP = 8;

/** Very different aspect ratios (width / height): 2, 0.5, 1.5, 3, 0.8, 1.75, 0.42, 1.33. */
const SHAPES: Array<[number, number]> = [
  [800, 400],
  [300, 600],
  [600, 400],
  [900, 300],
  [400, 500],
  [700, 400],
  [250, 600],
  [640, 480],
];
const COLORS: Array<[number, number, number]> = [
  [200, 60, 60],
  [60, 160, 90],
  [60, 100, 200],
  [190, 140, 40],
  [140, 70, 180],
  [40, 160, 170],
  [210, 100, 150],
  [110, 120, 130],
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
let justifiedId = 0;

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

/** An empty alt text leaves the link without an accessible name (axe `link-name`): the fixtures carry alt texts. */
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

interface Row {
  count: number;
  span: number;
  summed: number;
  minHeight: number;
  maxHeight: number;
  left: number;
  right: number;
}

interface Geometry {
  container: { left: number; right: number; width: number };
  rows: Row[];
  overflow: boolean;
}

/** Groups the thumbnails into visual rows (same top) and measures them. */
async function geometry(page: Page, gallery = 0): Promise<Geometry> {
  return page
    .locator('.swg-gallery')
    .nth(gallery)
    .evaluate((root) => {
      const list = root.querySelector('.swg-items') as HTMLElement;
      const box = list.getBoundingClientRect();
      const gap = parseFloat(getComputedStyle(list).columnGap) || 0;
      const imgs = Array.from(root.querySelectorAll<HTMLImageElement>('.swg-link img')).map((img) =>
        img.getBoundingClientRect(),
      );
      const rows: Array<DOMRect[]> = [];
      for (const rect of imgs) {
        const row = rows[rows.length - 1];
        if (row && Math.abs((row[0] as DOMRect).top - rect.top) < 2) row.push(rect);
        else rows.push([rect]);
      }
      return {
        container: { left: box.left, right: box.right, width: box.width },
        overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
        rows: rows.map((row) => {
          const lefts = row.map((r) => r.left);
          const rights = row.map((r) => r.right);
          const heights = row.map((r) => r.height);
          return {
            count: row.length,
            span: Math.max(...rights) - Math.min(...lefts),
            summed: row.reduce((sum, r) => sum + r.width, 0) + gap * (row.length - 1),
            minHeight: Math.min(...heights),
            maxHeight: Math.max(...heights),
            left: Math.min(...lefts),
            right: Math.max(...rights),
          };
        }),
      };
    });
}

/** Loads every thumbnail (they are lazy) so measurements and screenshots show the final state. */
async function loadAll(page: Page): Promise<void> {
  await page.evaluate(async () => {
    const imgs = Array.from(document.querySelectorAll<HTMLImageElement>('.swg-link img'));
    for (const img of imgs) img.loading = 'eager';
    await Promise.all(imgs.map((img) => img.decode().catch(() => undefined)));
    // `sizes`/lazy loading may pick another candidate after switching to eager: wait until each image really has pixels.
    const deadline = Date.now() + 8000;
    while (Date.now() < deadline && !imgs.every((img) => img.complete && img.naturalWidth > 0)) {
      await new Promise((resolveWait) => setTimeout(resolveWait, 50));
    }
    await document.fonts.ready;
  });
}

/** Width / height of the thumbnails in DOM order, rounded to two decimals (proves the stored order). */
async function ratios(page: Page): Promise<number[]> {
  const values = await page
    .locator('.swg-gallery .swg-link img')
    .evaluateAll((imgs) =>
      imgs.map((img) => Number(img.getAttribute('width')) / Number(img.getAttribute('height'))),
    );
  return values.map((value) => Math.round(value * 100) / 100);
}

async function hrefs(page: Page): Promise<string[]> {
  return page
    .locator('.swg-gallery a.swg-link')
    .evaluateAll((links) => links.map((a) => a.getAttribute('href') ?? ''));
}

/** Items per visual column, identified by their left edge (the masonry grid has equal column widths). */
async function columnBottoms(
  page: Page,
): Promise<{ columns: number; spread: number; tallest: number }> {
  return page.locator('.swg-gallery .swg-items').evaluate((list) => {
    const items = Array.from(list.children).map((el) => el.getBoundingClientRect());
    const lefts = [...new Set(items.map((r) => Math.round(r.left)))];
    const bottoms = lefts.map((left) =>
      Math.max(...items.filter((r) => Math.round(r.left) === left).map((r) => r.bottom)),
    );
    return {
      columns: lefts.length,
      spread: Math.max(...bottoms) - Math.min(...bottoms),
      tallest: Math.max(...items.map((r) => r.height)),
    };
  });
}

async function shot(page: Page, name: string): Promise<void> {
  await mkdir(SCREENSHOT_DIR, { recursive: true });
  await loadAll(page);
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({ path: resolve(SCREENSHOT_DIR, `${name}.png`), fullPage: true });
}

function fileStem(href: string): string {
  return (href.split('/').pop() ?? '').replace(/\.[a-z0-9]+$/i, '');
}

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ browser }) => {
  adminContext = await browser.newContext({ storageState: authFile('administrator') });
  const page = await adminContext.newPage();
  const fixtures = await GalleryFixtures.forPage(page);
  const bootstrap = await page.evaluate(() => {
    const value = (window as unknown as { swgAdmin: { restRoot: string; nonce: string } }).swgAdmin;
    return { restRoot: value.restRoot, nonce: value.nonce };
  });
  site = { fixtures, request: page.request, nonce: bootstrap.nonce, restRoot: bootstrap.restRoot };

  const ids: number[] = [];
  for (const [index, [width, height]] of SHAPES.entries()) {
    const image = await fixtures.uploadImage(
      `${prefix} image ${index + 1}`,
      tinyPngSized(COLORS[index] as [number, number, number], width, height),
    );
    await setAlt(image.id, `Photo ${index + 1}`);
    ids.push(image.id);
  }
  // A longer gallery of small images (26 > the default page size of 24) for pagination.
  const many: number[] = [];
  for (let i = 0; i < 26; i += 1) {
    const image = await fixtures.uploadImage(
      `${prefix} many ${String(i + 1).padStart(2, '0')}`,
      tinyPngSized(COLORS[i % 8] as [number, number, number], 60 + (i % 5) * 25, 50 + (i % 3) * 40),
    );
    await setAlt(image.id, `Small photo ${i + 1}`);
    many.push(image.id);
  }

  const justified = await fixtures.create({
    title: `${prefix} justified`,
    status: 'publish',
    layout: 'justified',
    settings: { rowHeight: ROW_HEIGHT, gap: GAP },
  });
  justifiedId = justified.id;
  await fixtures.assign(justified.id, ids);
  const justifiedFull = await fixtures.create({
    title: `${prefix} justified full`,
    status: 'publish',
    layout: 'justified',
    settings: { rowHeight: ROW_HEIGHT, gap: GAP, lastRow: 'justify' },
  });
  await fixtures.assign(justifiedFull.id, ids);
  const masonry = await fixtures.create({
    title: `${prefix} masonry`,
    status: 'publish',
    layout: 'masonry',
    settings: { columns: { base: 1, sm: 2, lg: 3 }, gap: GAP },
  });
  await fixtures.assign(masonry.id, ids);
  const paged = await fixtures.create({
    title: `${prefix} paged`,
    status: 'publish',
    layout: 'masonry',
    settings: { columns: 4, gap: GAP },
  });
  await fixtures.assign(paged.id, many);

  urls.justified = await createPost('justified', `[sw_gallery id="${justified.id}"]`);
  urls.justifiedBlock = await createPost(
    'justified block',
    `<!-- wp:sw-gallery/gallery {"galleryId":${justified.id}} /-->`,
  );
  urls.justifiedFull = await createPost('justified full', `[sw_gallery id="${justifiedFull.id}"]`);
  urls.masonry = await createPost('masonry', `[sw_gallery id="${masonry.id}"]`);
  urls.masonryBlock = await createPost(
    'masonry block',
    `<!-- wp:sw-gallery/gallery {"galleryId":${masonry.id}} /-->`,
  );
  urls.pagedMasonry = await createPost('paged masonry', `[sw_gallery id="${paged.id}"]`);
  urls.pagedJustified = await createPost(
    'paged justified',
    `[sw_gallery id="${paged.id}" layout="justified"]`,
  );
  urls.pagedId = String(paged.id);
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

for (const width of [375, 768, 1280]) {
  test(`justified: every complete row fills the container and has equal heights at ${width}px`, async ({
    browser,
  }) => {
    const context = await visitor(browser, { viewport: { width, height: 900 } });
    const page = await context.newPage();
    await page.goto(urls.justified as string);
    await loadAll(page);

    const result = await geometry(page);
    expect(result.overflow, 'no horizontal overflow').toBe(false);
    expect(result.rows.length).toBeGreaterThan(1);
    for (const row of result.rows.slice(0, -1)) {
      expect(
        Math.abs(row.span - result.container.width),
        `row span ${JSON.stringify(row)}`,
      ).toBeLessThan(1.5);
      expect(Math.abs(row.summed - result.container.width), 'sum of widths and gaps').toBeLessThan(
        1.5,
      );
      expect(row.maxHeight - row.minHeight, 'equal heights in a row').toBeLessThan(1.5);
    }
    // The last row keeps its natural height (it is not stretched), unless it is the only row of that width.
    const last = result.rows[result.rows.length - 1] as Row;
    expect(last.maxHeight - last.minHeight).toBeLessThan(1.5);
    expect(last.span).toBeLessThanOrEqual(result.container.width + 1.5);
    if (width === 1280) {
      for (const row of result.rows.filter((r) => r.count > 1)) {
        expect(
          row.minHeight,
          `row height near ${ROW_HEIGHT}: ${JSON.stringify(row)}`,
        ).toBeGreaterThan(ROW_HEIGHT * 0.85);
        expect(row.maxHeight).toBeLessThan(ROW_HEIGHT * 2.2);
      }
      expect(last.maxHeight, 'last row stays at the row height').toBeLessThan(ROW_HEIGHT + 2);
      expect(last.span, 'last row is left aligned and shorter than the container').toBeLessThan(
        result.container.width - 10,
      );
    }
    await shot(page, `layouts-justified-${width}`);
    await context.close();
  });
}

test('justified: lastRow justify fills the last row as well', async ({ browser }) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.justifiedFull as string);
  await loadAll(page);

  const result = await geometry(page);
  for (const row of result.rows) {
    expect(Math.abs(row.span - result.container.width)).toBeLessThan(1.5);
  }
  await context.close();
});

test('justified: works without JavaScript and keeps the stored order', async ({ browser }) => {
  const context = await visitor(browser, { javaScriptEnabled: false });
  const page = await context.newPage();
  await page.goto(urls.justified as string);
  await page.waitForLoadState('load');

  const result = await geometry(page);
  expect(result.rows.length).toBeGreaterThan(1);
  for (const row of result.rows.slice(0, -1)) {
    expect(Math.abs(row.span - result.container.width)).toBeLessThan(1.5);
    expect(row.maxHeight - row.minHeight).toBeLessThan(1.5);
  }
  expect(await ratios(page), 'stored order').toEqual(
    SHAPES.map(([w, h]) => Math.round((w / h) * 100) / 100),
  );
  await context.close();
});

test('justified: the block renders the same layout and RTL mirrors the rows', async ({
  browser,
}) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.justifiedBlock as string);
  await expect(page.locator('.swg-gallery.swg-layout-justified')).toHaveCount(1);
  await loadAll(page);
  const ltr = await geometry(page);
  const order = await hrefs(page);

  await page.evaluate(() => {
    document.documentElement.dir = 'rtl';
  });
  const rtl = await geometry(page);
  expect(await hrefs(page), 'DOM order is untouched').toEqual(order);
  for (const row of rtl.rows.slice(0, -1)) {
    expect(Math.abs(row.span - rtl.container.width)).toBeLessThan(1.5);
  }
  const first = await page.locator('.swg-gallery .swg-link').first().boundingBox();
  expect(first).not.toBeNull();
  expect(
    Math.abs((first?.x ?? 0) + (first?.width ?? 0) - rtl.container.right),
    'the first item starts at the inline start (right edge) in RTL',
  ).toBeLessThan(2);
  expect(rtl.rows.length).toBe(ltr.rows.length);
  await shot(page, 'layouts-justified-rtl');
  await context.close();
});

test('masonry: without JavaScript it is a plain grid in editor order', async ({ browser }) => {
  const context = await visitor(browser, {
    javaScriptEnabled: false,
    viewport: { width: 1280, height: 900 },
  });
  const page = await context.newPage();
  await page.goto(urls.masonry as string);
  await page.waitForLoadState('load');

  const gallery = page.locator('.swg-gallery.swg-layout-masonry');
  await expect(gallery).toHaveCount(1);
  await expect(gallery).not.toHaveClass(/swg-masonry-active/);
  await expect(gallery.locator('.swg-items')).toHaveCSS('display', 'grid');
  expect((await columnBottoms(page)).columns).toBe(3);
  expect(await ratios(page), 'stored order').toEqual(
    SHAPES.map(([w, h]) => Math.round((w / h) * 100) / 100),
  );
  await context.close();
});

test('masonry: with JavaScript the columns are balanced, the DOM order is unchanged and resizing re-packs', async ({
  browser,
}) => {
  const plainContext = await visitor(browser, {
    javaScriptEnabled: false,
    viewport: { width: 1280, height: 900 },
  });
  const plain = await plainContext.newPage();
  await plain.goto(urls.masonry as string);
  await loadAll(plain);
  const plainOrder = await hrefs(plain);
  const plainColumns = await columnBottoms(plain);
  await plainContext.close();

  const context = await visitor(browser, { viewport: { width: 375, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.masonry as string);
  await loadAll(page);
  await expect(page.locator('.swg-gallery')).toHaveClass(/swg-masonry-active/);
  expect((await columnBottoms(page)).columns, 'one column at 375px').toBe(1);

  await page.setViewportSize({ width: 1280, height: 900 });
  await expect.poll(async () => (await columnBottoms(page)).columns).toBe(3);
  await expect
    .poll(async () => (await columnBottoms(page)).spread, { message: 'columns are packed' })
    .toBeLessThan(plainColumns.spread + 1);
  const packed = await columnBottoms(page);
  expect(packed.spread, 'column bottoms differ by less than the tallest item').toBeLessThanOrEqual(
    packed.tallest,
  );
  expect(await hrefs(page), 'DOM order unchanged by the packing').toEqual(plainOrder);
  console.log(
    `masonry column spread: packed ${packed.spread.toFixed(1)}px, plain grid ${plainColumns.spread.toFixed(1)}px, tallest ${packed.tallest.toFixed(1)}px`,
  );

  await page.setViewportSize({ width: 768, height: 900 });
  await expect.poll(async () => (await columnBottoms(page)).columns).toBe(2);
  const two = await columnBottoms(page);
  expect(two.spread).toBeLessThanOrEqual(two.tallest);
  await context.close();
});

for (const surface of ['justified', 'masonry'] as const) {
  test(`${surface}: nothing shifts while the images load`, async ({ browser }) => {
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
    await page.goto(urls[surface] as string, { waitUntil: 'domcontentloaded' });
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
    const after = await positions();
    expect(after, 'every item keeps its box once the images are there').toEqual(before);
    await context.close();
  });
}

for (const surface of ['justified', 'masonry'] as const) {
  test(`${surface}: Tab follows the DOM order and the lightbox navigates in that order`, async ({
    browser,
  }) => {
    const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();
    await page.goto(urls[surface] as string);
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

    await page.locator('.swg-gallery a.swg-link').first().click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText(new RegExp(`1\\s*(of|von)\\s*${order.length}`));
    for (let i = 1; i < 4; i += 1) {
      await page.keyboard.press('ArrowRight');
      await expect(dialog).toContainText(new RegExp(`${i + 1}\\s*(of|von)\\s*${order.length}`));
      await expect(dialog.locator('img').first()).toHaveAttribute(
        'src',
        new RegExp(fileStem(order[i] as string).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')),
      );
    }
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(page.locator('.swg-gallery a.swg-link').first()).toBeFocused();
    await context.close();
  });
}

test('masonry: a touch swipe moves the lightbox to the next image', async ({ browser }) => {
  const context = await visitor(browser, {
    hasTouch: true,
    isMobile: true,
    viewport: { width: 390, height: 844 },
  });
  const page = await context.newPage();
  await page.goto(urls.masonry as string);
  await loadAll(page);
  await page.locator('.swg-gallery a.swg-link').first().tap();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog).toContainText(/1\s*(of|von)\s*8/);
  await expect(dialog).toHaveCSS('opacity', '1');

  const client = await context.newCDPSession(page);
  const touch = (type: 'touchStart' | 'touchMove' | 'touchEnd', x: number): Promise<unknown> =>
    client.send('Input.dispatchTouchEvent', {
      type,
      touchPoints: type === 'touchEnd' ? [] : [{ x, y: 420 }],
    });
  await touch('touchStart', 320);
  for (const x of [280, 240, 200, 160, 120]) await touch('touchMove', x);
  await touch('touchEnd', 120);
  await expect(dialog).toContainText(/2\s*(of|von)\s*8/);
  await context.close();
});

for (const surface of ['pagedMasonry', 'pagedJustified'] as const) {
  for (const scripted of [true, false]) {
    test(`${surface}: link pagination works ${scripted ? 'with' : 'without'} JavaScript`, async ({
      browser,
    }) => {
      const context = await visitor(browser, { javaScriptEnabled: scripted });
      const page = await context.newPage();
      await page.goto(urls[surface] as string);
      await loadAll(page).catch(() => undefined);

      await expect(page.locator('.swg-gallery .swg-item')).toHaveCount(24);
      const nav = page.getByRole('navigation', { name: /pages|seiten/i });
      await expect(nav).toBeVisible();
      if (scripted && surface === 'pagedMasonry') {
        await expect(page.locator('.swg-gallery')).toHaveClass(/swg-masonry-active/);
      }
      await nav
        .getByRole('link', { name: /2|next|weiter|nächste/i })
        .first()
        .click();
      await expect(page).toHaveURL(new RegExp(`swg_page_${urls.pagedId}=2`));
      await expect(page.locator('.swg-gallery .swg-item')).toHaveCount(2);
      if (scripted && surface === 'pagedMasonry') {
        await expect(page.locator('.swg-gallery')).toHaveClass(/swg-masonry-active/);
      }
      await context.close();
    });
  }
}

for (const surface of ['justified', 'masonry'] as const) {
  for (const scheme of ['light', 'dark'] as const) {
    test(`${surface}: axe finds no serious or critical violation (${scheme})`, async ({
      browser,
    }) => {
      const context = await visitor(browser, { colorScheme: scheme });
      const page = await context.newPage();
      await page.goto(urls[surface] as string);
      await loadAll(page);

      const results = await new AxeBuilder({ page })
        .include('.swg-gallery')
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'])
        .analyze();
      for (const violation of results.violations) {
        console.log(
          `axe [${violation.impact ?? 'n/a'}] ${violation.id}: ${violation.help} (${surface}, ${scheme})`,
        );
      }
      expect(
        results.violations
          .filter((v) => v.impact === 'serious' || v.impact === 'critical')
          .map((v) => `${v.id}: ${v.help}`),
      ).toEqual([]);
      await context.close();
    });
  }
}

for (const width of [375, 768, 1280]) {
  test(`masonry: screenshot and no horizontal overflow at ${width}px`, async ({ browser }) => {
    const context = await visitor(browser, { viewport: { width, height: 900 } });
    const page = await context.newPage();
    await page.goto(urls.masonry as string);
    await loadAll(page);
    await expect(page.locator('.swg-gallery')).toHaveClass(/swg-masonry-active/);
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
      ),
    ).toBe(false);
    await shot(page, `layouts-masonry-${width}`);
    await context.close();
  });
}

test('masonry: the RTL page keeps the DOM order and starts at the right edge', async ({
  browser,
}) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.masonryBlock as string);
  await loadAll(page);
  const order = await hrefs(page);
  await page.evaluate(() => {
    document.documentElement.dir = 'rtl';
  });
  await expect.poll(async () => (await columnBottoms(page)).columns).toBe(3);

  expect(await hrefs(page)).toEqual(order);
  const list = await page.locator('.swg-gallery .swg-items').boundingBox();
  const first = await page.locator('.swg-gallery .swg-link').first().boundingBox();
  expect(
    Math.abs((first?.x ?? 0) + (first?.width ?? 0) - ((list?.x ?? 0) + (list?.width ?? 0))),
  ).toBeLessThan(2);
  await shot(page, 'layouts-masonry-rtl');
  await context.close();
});

test('the editor generates the settings form of the new layouts from their schemas', async ({
  page,
}) => {
  await page.goto(`${PLUGIN_PAGE}#/galleries/${justifiedId}`);
  const root = page.locator('#swg-admin-root');
  await expect(root.locator('.swg-shell')).toBeVisible();
  await root.locator('.swg-seg').getByText('Layout & settings', { exact: true }).click();
  const panel = root.locator('.swg-lsp');
  await expect(panel).toBeVisible();

  await expect(panel.getByText('Row height (px)')).toBeVisible();
  await expect(panel.getByText('Last row', { exact: true })).toBeVisible();
  await expect(panel.getByText('Default: 220')).toBeVisible();
  const numbers = panel.locator('input[type="number"]');
  await numbers.first().fill('40');
  await expect(panel.getByText('Must be at least 80.')).toBeVisible();
  await numbers.first().fill('180');
  await expect(panel.getByText('Must be at least 80.')).toHaveCount(0);

  await panel.getByLabel('Layout', { exact: true }).click();
  await expect(page.getByRole('option', { name: 'Masonry' })).toBeVisible();
  await expect(page.getByRole('option', { name: 'Justified' })).toBeVisible();
  await page.getByRole('option', { name: 'Masonry' }).click();
  await expect(panel.getByText('Columns', { exact: true })).toBeVisible();
  await expect(panel.getByText('Row height (px)')).toHaveCount(0);
});
