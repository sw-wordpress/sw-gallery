import { expect, test } from '@playwright/test';
import type { APIRequestContext, Browser, BrowserContext, Page } from '@playwright/test';
import { authFile } from '../support/env';
import { GalleryFixtures, tinyPngSized, uniquePrefix } from '../support/galleries';

// Frontend performance (M5-02) as a visitor sees it: the documented loading strategy (docs/rendering.md step 6), no
// layout shift for the grid, responsive srcset/sizes, and the asset footprint: a page with a gallery loads only the
// declared plugin assets below a transfer budget, a page without a gallery loads none.

test.use({ storageState: authFile('administrator') });

const prefix = uniquePrefix('perf');
const IMAGE_COUNT = 14;
const COLUMNS = 4;
/** docs/rendering.md step 6: the first row is eager, "at most 6" images. */
const MAX_EAGER = 6;
/** Plugin assets of one gallery page (layout CSS + lightbox JS + lightbox CSS): budget for compressed bytes incl. headers. */
const ASSET_TRANSFER_BUDGET = 16 * 1024;
const CLS_BUDGET = 0.01;
const PLUGIN_ASSETS = /\/wp-content\/plugins\/[^/]+\/assets\//;

interface Site {
  request: APIRequestContext;
  nonce: string;
  restRoot: string;
}

let site: Site;
let adminContext: BrowserContext;
let galleryUrl = '';
let plainUrl = '';
const postIds: number[] = [];

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

async function visitor(browser: Browser, options: Record<string, unknown> = {}) {
  return browser.newContext({ storageState: { cookies: [], origins: [] }, ...options });
}

/** Loads every thumbnail (they are lazy) so measurements show the final state. */
async function loadAll(page: Page): Promise<void> {
  await page.evaluate(async () => {
    const imgs = Array.from(document.querySelectorAll<HTMLImageElement>('.swg-link img'));
    for (const img of imgs) img.loading = 'eager';
    await Promise.all(imgs.map((img) => img.decode().catch(() => undefined)));
    const deadline = Date.now() + 8000;
    while (Date.now() < deadline && !imgs.every((img) => img.complete && img.naturalWidth > 0)) {
      await new Promise((resolveWait) => setTimeout(resolveWait, 50));
    }
  });
}

/** Lets pending layout and late requests happen: a few animation frames (no fixed sleeps). */
async function settle(page: Page): Promise<void> {
  await page.evaluate(async () => {
    for (let frame = 0; frame < 20; frame += 1) {
      await new Promise((resolveFrame) => requestAnimationFrame(resolveFrame));
    }
  });
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
  site = { request: page.request, nonce: bootstrap.nonce, restRoot: bootstrap.restRoot };

  const ids: number[] = [];
  for (let index = 0; index < IMAGE_COUNT; index += 1) {
    // 1200x800: core generates medium, medium_large and large sub-sizes with the same ratio, so a srcset exists.
    const image = await fixtures.uploadImage(
      `${prefix} image ${index + 1}`,
      tinyPngSized([40 + index * 12, 90, 200 - index * 10], 1200, 800),
    );
    ids.push(image.id);
  }
  const gallery = await fixtures.create({ title: `${prefix} gallery`, status: 'publish' });
  await fixtures.assign(gallery.id, ids);
  galleryUrl = await createPost(
    'with gallery',
    `[sw_gallery id="${gallery.id}" columns="${COLUMNS}" gap="12"]`,
  );
  plainUrl = await createPost('without gallery', '<p>No gallery on this page.</p>');
});

test.afterAll(async () => {
  for (const id of postIds) {
    await site.request.delete(site.restRoot.replace('swg/v1', 'wp/v2') + `posts/${id}?force=true`, {
      headers: { 'X-WP-Nonce': site.nonce },
    });
  }
  const fixtures = await GalleryFixtures.forPage(await adminContext.newPage());
  await fixtures.purge(prefix);
  await fixtures.purgeMedia(prefix);
  await adminContext.close();
});

test('the first row is eager with one fetchpriority=high, the rest lazy, every image async and sized', async ({
  browser,
}) => {
  const context = await visitor(browser);
  const page = await context.newPage();
  await page.goto(galleryUrl);

  const images = await page.locator('.swg-gallery .swg-link img').evaluateAll((imgs) =>
    imgs.map((img) => ({
      loading: img.getAttribute('loading'),
      priority: img.getAttribute('fetchpriority'),
      decoding: img.getAttribute('decoding'),
      width: Number(img.getAttribute('width')),
      height: Number(img.getAttribute('height')),
      srcset: img.getAttribute('srcset'),
      sizes: img.getAttribute('sizes'),
    })),
  );
  expect(images).toHaveLength(IMAGE_COUNT);

  const eager = images.filter((img) => img.loading === 'eager').length;
  expect(eager, 'the first row (columns) up to 6 images is eager').toBeGreaterThanOrEqual(COLUMNS);
  expect(eager).toBeLessThanOrEqual(MAX_EAGER);
  expect(images.slice(0, eager).every((img) => img.loading === 'eager')).toBe(true);
  expect(
    images.slice(eager).every((img) => img.loading === 'lazy'),
    'everything after the first row is lazy',
  ).toBe(true);
  expect(images.map((img) => img.priority)).toEqual(
    images.map((_, index) => (index === 0 ? 'high' : null)),
  );
  for (const [index, img] of images.entries()) {
    expect(img.decoding, `image ${index} decoding`).toBe('async');
    expect(img.width, `image ${index} width`).toBeGreaterThan(0);
    expect(img.height, `image ${index} height`).toBeGreaterThan(0);
    expect(img.srcset, `image ${index} srcset`).toMatch(/\.png \d+w, .+ \d+w/);
    expect(img.sizes, `image ${index} sizes`).toMatch(/\S/);
  }
  console.log(`loading strategy: ${eager} eager of ${images.length}, first has fetchpriority=high`);
  await context.close();
});

test('the grid does not shift while images load (boxes stay, CLS below the budget)', async ({
  browser,
}) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 2400 } });
  const page = await context.newPage();
  await page.addInitScript(() => {
    const holder = window as unknown as { __cls: number };
    holder.__cls = 0;
    new PerformanceObserver((list) => {
      for (const entry of list.getEntries()) {
        const shift = entry as PerformanceEntry & { value: number; hadRecentInput: boolean };
        if (!shift.hadRecentInput) holder.__cls += shift.value;
      }
    }).observe({ type: 'layout-shift', buffered: true });
  });
  let release: () => void = () => undefined;
  const gate = new Promise<void>((resolveGate) => {
    release = resolveGate;
  });
  await page.route(/\/wp-content\/uploads\//, async (route) => {
    await gate;
    await route.continue();
  });
  await page.goto(galleryUrl, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.swg-gallery .swg-item')).toHaveCount(IMAGE_COUNT);

  const boxes = (): Promise<number[][]> =>
    page.locator('.swg-gallery .swg-item').evaluateAll((items) =>
      items.map((el) => {
        const r = el.getBoundingClientRect();
        return [r.left, r.top, r.width, r.height].map((n) => Math.round(n * 10) / 10);
      }),
    );
  const before = await boxes();
  expect(before.every(([, , width, height]) => (width ?? 0) > 0 && (height ?? 0) > 0)).toBe(true);
  release();
  await page.waitForLoadState('load');
  await loadAll(page);
  await settle(page);
  expect(await boxes(), 'every item keeps its box once the images are there').toEqual(before);
  const cls = await page.evaluate(() => (window as unknown as { __cls: number }).__cls);
  console.log(`grid CLS: ${cls.toFixed(4)} (budget ${CLS_BUDGET})`);
  expect(cls).toBeLessThanOrEqual(CLS_BUDGET);
  await context.close();
});

test('a page with a gallery loads only the declared plugin assets within the transfer budget', async ({
  browser,
}) => {
  const context = await visitor(browser);
  const page = await context.newPage();
  const transfers: Array<{ url: string; bytes: number }> = [];
  const apiCalls: string[] = [];
  page.on('requestfinished', (request) => {
    void request.sizes().then((sizes) => {
      if (PLUGIN_ASSETS.test(request.url())) {
        transfers.push({
          url: request.url(),
          bytes: sizes.responseBodySize + sizes.responseHeadersSize,
        });
      }
    });
  });
  page.on('request', (request) => {
    if (request.url().includes('/wp-json/swg/')) apiCalls.push(request.url());
  });
  await page.goto(galleryUrl, { waitUntil: 'load' });
  await expect(page.locator('.swg-gallery')).toHaveCount(1);
  await expect.poll(() => transfers.length).toBeGreaterThanOrEqual(3);
  await settle(page);

  const names = transfers.map(
    (t) => (new URL(t.url).pathname.split('/').pop() ?? '').split('?')[0],
  );
  const kinds = names.map((name) => name?.replace(/-[\w]+(\.\w+)$/, '$1'));
  expect(kinds.sort(), `declared assets only, got ${names.join(', ')}`).toEqual([
    'layouts.css',
    'lightbox.css',
    'lightbox.js',
  ]);
  expect(apiCalls, 'no REST call for a visitor').toEqual([]);
  const total = transfers.reduce((sum, t) => sum + t.bytes, 0);
  console.log(
    `plugin assets: ${transfers.map((t) => `${t.url.split('/').pop()?.split('?')[0]} ${t.bytes} B`).join(', ')}; total ${total} B (budget ${ASSET_TRANSFER_BUDGET} B)`,
  );
  expect(total).toBeLessThanOrEqual(ASSET_TRANSFER_BUDGET);
  await context.close();
});

test('a page without a gallery loads none of the plugin assets', async ({ browser }) => {
  const context = await visitor(browser);
  const page = await context.newPage();
  const requests: string[] = [];
  page.on('request', (request) => requests.push(request.url()));
  await page.goto(plainUrl, { waitUntil: 'load' });
  await settle(page);

  expect(
    requests.filter((url) => PLUGIN_ASSETS.test(url) || url.includes('/wp-json/swg/')),
  ).toEqual([]);
  await context.close();
});
