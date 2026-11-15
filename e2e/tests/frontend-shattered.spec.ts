import { expect, test } from '@playwright/test';
import type { APIRequestContext, Browser, BrowserContext, Page } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { seriousViolations } from '../support/axe';
import { authFile, SCREENSHOT_DIR } from '../support/env';
import { GalleryFixtures, uniquePrefix } from '../support/galleries';
import { photoPng } from '../support/photo';

// The shattered layout (weighted Voronoi shards) as a visitor sees it: the shards fill the container, do not overlap,
// hit-testing follows the outline, DOM and Tab order stay the stored order, the lightbox opens from a shard, RTL
// mirrors the plan, nothing shifts, axe is clean and the focal point reaches object-position. Fixtures are created
// through the REST API from photo-like PNGs so the screenshots show real crops.

test.use({ storageState: authFile('administrator') });

const prefix = uniquePrefix('shattered');

/** Mixed aspect ratios (width x height, kept small: the PNGs are generated). */
const SHAPES: Array<[number, number]> = [
  [480, 320],
  [300, 450],
  [400, 400],
  [480, 200],
  [320, 480],
  [480, 320],
  [360, 480],
  [450, 300],
  [400, 400],
  [300, 450],
  [480, 360],
  [420, 280],
];
/** Emphasis per picture (index = DOM position). */
const EMPHASIS = [3, 1, 1, 1, 2, 1, 1, 3, 1, 2, 1, 1];
const FOCAL_INDEX = 3;
const EXTREME_EMPHASIS = [3, 3, 1, 3, 1, 3, 1];
const GROUT = 3;

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
const attachments: number[] = [];
let mainId = 0;
let variedId = 0;

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

/** The theme column is narrow: let the gallery use the whole viewport so the wide variant can show. */
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

type Point = [number, number];

interface Shard {
  box: Box;
  poly: Point[];
}

interface Geometry {
  list: Box;
  shards: Shard[];
  ar: string;
  position: string;
  overflow: boolean;
}

/** Boxes of the tiles and their outlines in page coordinates (read from the computed clip-path, mirrored lists included). */
async function geometry(page: Page, gallery = 0): Promise<Geometry> {
  return page
    .locator('.swg-gallery')
    .nth(gallery)
    .evaluate((root) => {
      const list = root.querySelector('.swg-items') as HTMLElement;
      const box = (el: Element): Box => {
        const r = el.getBoundingClientRect();
        return { left: r.left, top: r.top, right: r.right, bottom: r.bottom };
      };
      const mirrored = getComputedStyle(list).transform !== 'none';
      const shards = Array.from(root.querySelectorAll('.swg-item')).map((li) => {
        const b = box(li);
        const clip = getComputedStyle(li.querySelector('.swg-link') as Element).clipPath;
        const poly: Point[] = [];
        const body = /polygon\((.*)\)/.exec(clip)?.[1] ?? '';
        for (const pair of body.split(',')) {
          const [x, y] = pair
            .trim()
            .split(/\s+/)
            .map((v) => parseFloat(v) / 100);
          if (x === undefined || y === undefined) continue;
          poly.push([
            mirrored ? b.right - x * (b.right - b.left) : b.left + x * (b.right - b.left),
            b.top + y * (b.bottom - b.top),
          ]);
        }
        return { box: b, poly };
      });
      return {
        list: box(list),
        shards,
        ar: getComputedStyle(list).getPropertyValue('--swg-ar').trim(),
        position: getComputedStyle(root.querySelector('.swg-item') as Element).position,
        overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
      };
    });
}

function inside(poly: Point[], x: number, y: number): boolean {
  let hit = false;
  for (let i = 0, j = poly.length - 1; i < poly.length; j = i, i += 1) {
    const [xi, yi] = poly[i] as Point;
    const [xj, yj] = poly[j] as Point;
    if (yi > y !== yj > y && x < ((xj - xi) * (y - yi)) / (yj - yi) + xi) hit = !hit;
  }
  return hit;
}

function distanceToEdges(poly: Point[], x: number, y: number): number {
  let best = Infinity;
  for (let i = 0, j = poly.length - 1; i < poly.length; j = i, i += 1) {
    const [ax, ay] = poly[j] as Point;
    const [bx, by] = poly[i] as Point;
    const dx = bx - ax;
    const dy = by - ay;
    const t = Math.max(0, Math.min(1, ((x - ax) * dx + (y - ay) * dy) / (dx * dx + dy * dy || 1)));
    best = Math.min(best, Math.hypot(x - (ax + t * dx), y - (ay + t * dy)));
  }
  return best;
}

function polygonArea(poly: Point[]): number {
  let sum = 0;
  for (let i = 0, j = poly.length - 1; i < poly.length; j = i, i += 1) {
    const [xi, yi] = poly[i] as Point;
    const [xj, yj] = poly[j] as Point;
    sum += xj * yi - xi * yj;
  }
  return Math.abs(sum) / 2;
}

/** Grid of sample points over the list (kept clear of the very border). */
function samples(list: Box, columns = 60, rows = 40): Point[] {
  const points: Point[] = [];
  for (let r = 0; r < rows; r += 1) {
    for (let c = 0; c < columns; c += 1) {
      points.push([
        list.left + ((c + 0.5) / columns) * (list.right - list.left),
        list.top + ((r + 0.5) / rows) * (list.bottom - list.top),
      ]);
    }
  }
  return points;
}

async function hrefs(page: Page): Promise<string[]> {
  return page
    .locator('.swg-gallery a.swg-link')
    .evaluateAll((links) => links.map((a) => a.getAttribute('href') ?? ''));
}

function fileStem(href: string): string {
  return (href.split('/').pop() ?? '').replace(/\.[a-z0-9]+$/i, '');
}

async function upload(index: number): Promise<number> {
  const [width, height] = SHAPES[index % SHAPES.length] as [number, number];
  const image = await site.fixtures.uploadImage(
    `${prefix} photo ${index + 1}`,
    photoPng(index, width, height),
  );
  await setAlt(image.id, `photo ${index + 1}`);
  return image.id;
}

async function createGallery(
  title: string,
  imageIds: number[],
  emphasis: number[],
  settings: Record<string, unknown> = {},
): Promise<number> {
  const gallery = await site.fixtures.create({
    title: `${prefix} ${title}`,
    status: 'publish',
    layout: 'shattered',
    settings: { grout: GROUT, ...settings },
  });
  await site.fixtures.assign(gallery.id, imageIds);
  const items = await site.fixtures.items(gallery.id);
  for (const [index, item] of items.entries()) {
    const level = emphasis[index % emphasis.length] ?? 1;
    const map: Record<string, unknown> = {};
    if (level !== 1) map.emphasis = level;
    if (title === 'main' && index === FOCAL_INDEX) map.focal = { x: 10, y: 90 };
    if (Object.keys(map).length > 0) await site.fixtures.setItemSettings(gallery.id, item.id, map);
  }
  return gallery.id;
}

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ browser }) => {
  test.setTimeout(360_000);
  adminContext = await browser.newContext({ storageState: authFile('administrator') });
  const page = await adminContext.newPage();
  const fixtures = await GalleryFixtures.forPage(page);
  const bootstrap = await page.evaluate(() => {
    const value = (window as unknown as { swgAdmin: { restRoot: string; nonce: string } }).swgAdmin;
    return { restRoot: value.restRoot, nonce: value.nonce };
  });
  site = { fixtures, request: page.request, nonce: bootstrap.nonce, restRoot: bootstrap.restRoot };

  for (let index = 0; index < 26; index += 1) attachments.push(await upload(index));
  mainId = await createGallery('main', attachments.slice(0, SHAPES.length), EMPHASIS);
  const extreme = await createGallery('extreme', attachments.slice(0, 7), EXTREME_EMPHASIS);
  const many = await createGallery('many', attachments, [3, 1, 1, 2, 1, 1, 1, 1, 2], {
    grout: 5,
    ratio: '3:2',
  });
  variedId = await createGallery('varied', attachments.slice(0, 9), [1, 1, 2]);
  urls.main = await createPost('main', `[sw_gallery id="${mainId}"]`);
  urls.block = await createPost('block', `<!-- wp:sw-gallery/gallery {"galleryId":${mainId}} /-->`);
  urls.extreme = await createPost('extreme', `[sw_gallery id="${extreme}"]`);
  urls.many = await createPost('many', `[sw_gallery id="${many}"]`);
  urls.varied = await createPost('varied', `[sw_gallery id="${variedId}"]`);
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

/** Shared checks of a laid out plan: tiles inside the container, covering it, not overlapping, no slivers. */
function expectGlass(result: Geometry, expectedCount: number): void {
  expect(result.overflow, 'no horizontal overflow').toBe(false);
  expect(result.shards).toHaveLength(expectedCount);
  const listArea = (result.list.right - result.list.left) * (result.list.bottom - result.list.top);
  for (const shard of result.shards) {
    expect(shard.box.left).toBeGreaterThanOrEqual(result.list.left - 1);
    expect(shard.box.right).toBeLessThanOrEqual(result.list.right + 1);
    expect(shard.box.top).toBeGreaterThanOrEqual(result.list.top - 1);
    expect(shard.box.bottom).toBeLessThanOrEqual(result.list.bottom + 1);
    expect(shard.poly.length, 'a real polygon').toBeGreaterThanOrEqual(3);
  }
  // The union of the outlines covers the container except the grout lines.
  let uncovered = 0;
  let overlapping = 0;
  for (const [x, y] of samples(result.list)) {
    const hits = result.shards.filter((s) => inside(s.poly, x, y)).length;
    if (hits > 1) overlapping += 1;
    if (hits === 0) {
      uncovered += 1;
      const nearest = Math.min(...result.shards.map((s) => distanceToEdges(s.poly, x, y)));
      expect(nearest, 'a gap is at most a grout wide').toBeLessThan(GROUT + 4);
    }
  }
  expect(overlapping, 'shards do not overlap').toBe(0);
  expect(uncovered / 2400, 'the grout is a thin line').toBeLessThan(0.12);
  const covered = result.shards.reduce((total, s) => total + polygonArea(s.poly), 0);
  expect(covered / listArea, 'the shards fill the container').toBeGreaterThan(0.85);
  expect(covered / listArea).toBeLessThanOrEqual(1.001);
}

for (const width of [1280, 768, 375]) {
  test(`the shards fill the container without overlaps at ${width}px (375 = grid fallback)`, async ({
    browser,
  }) => {
    const context = await visitor(browser, { viewport: { width, height: 900 } });
    const page = await context.newPage();
    await page.goto(urls.main as string);
    if (width === 1280) await widen(page);
    await loadAll(page);
    const result = await geometry(page);
    const containerWidth = result.list.right - result.list.left;
    const expected = expectedRatio(containerWidth);
    if (expected === null) {
      expect(result.overflow).toBe(false);
      expect(result.position, 'below 480px the list is a plain grid').not.toBe('absolute');
      const clip = await page
        .locator('.swg-link')
        .first()
        .evaluate((el) => getComputedStyle(el).clipPath);
      expect(clip, 'no outline in the grid').toBe('none');
      await shot(page, `shattered-${width}`);
      await context.close();
      return;
    }
    expect(result.position).toBe('absolute');
    expect(result.ar, 'the variant follows the container width').toBe(expected);
    expectGlass(result, SHAPES.length);
    for (const shard of result.shards) {
      const w = shard.box.right - shard.box.left;
      const h = shard.box.bottom - shard.box.top;
      expect(Math.min(w, h), 'no sliver').toBeGreaterThan(40);
    }
    await shot(page, `shattered-${width}`);
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
    const result = await geometry(page);
    expect(result.ar).toBe(ratio);
    expectGlass(result, SHAPES.length);
    const w = result.list.right - result.list.left;
    const h = result.list.bottom - result.list.top;
    expect(Math.abs(w / h - Number(ratio)), 'container aspect ratio').toBeLessThan(0.02);
    await shot(page, `shattered-wide-${viewport}`);
    await context.close();
  });
}

test('hit-testing follows the outline: a point inside a box but outside its shard hits the neighbour', async ({
  browser,
}) => {
  const context = await visitor(browser, { viewport: { width: 1440, height: 1100 } });
  const page = await context.newPage();
  await page.goto(urls.main as string);
  await widen(page);
  await loadAll(page);
  await page.locator('.swg-gallery').scrollIntoViewIfNeeded();
  await page.evaluate(() => window.scrollTo(0, 0));
  await page
    .locator('.swg-gallery')
    .first()
    .evaluate((el) => el.scrollIntoView({ block: 'start' }));
  const result = await geometry(page);
  let checked = 0;
  for (const [index, shard] of result.shards.entries()) {
    // Points of the bounding box that lie outside the outline but inside another shard.
    for (const [x, y] of samples(shard.box, 14, 14)) {
      if (inside(shard.poly, x, y) || distanceToEdges(shard.poly, x, y) < 4) continue;
      const owner = result.shards.findIndex((s, i) => i !== index && inside(s.poly, x, y));
      if (owner < 0) continue;
      if (y < 0 || y > 1090) continue;
      const hit = await page.evaluate(
        ([px, py]) => {
          const link = document.elementFromPoint(px as number, py as number)?.closest('a.swg-link');
          return link ? Array.from(document.querySelectorAll('a.swg-link')).indexOf(link) : -1;
        },
        [x, y],
      );
      expect(hit, `point (${Math.round(x)}, ${Math.round(y)}) of box ${index + 1}`).toBe(owner);
      checked += 1;
    }
  }
  expect(checked, 'enough probes').toBeGreaterThan(20);
  // A point inside the outline hits the shard itself.
  for (const [index, shard] of result.shards.entries()) {
    const cx = shard.poly.reduce((a, p) => a + p[0], 0) / shard.poly.length;
    const cy = shard.poly.reduce((a, p) => a + p[1], 0) / shard.poly.length;
    if (cy < 0 || cy > 1090) continue;
    const hit = await page.evaluate(
      ([px, py]) => {
        const link = document.elementFromPoint(px as number, py as number)?.closest('a.swg-link');
        return link ? Array.from(document.querySelectorAll('a.swg-link')).indexOf(link) : -1;
      },
      [cx, cy],
    );
    expect(hit, `centre of shard ${index + 1}`).toBe(index);
  }
  await context.close();
});

test('the block renders the same shards and the DOM order is the stored order', async ({
  browser,
}) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const shortcode = await context.newPage();
  await shortcode.goto(urls.main as string);
  const block = await context.newPage();
  await block.goto(urls.block as string);
  await expect(block.locator('.swg-gallery.swg-layout-shattered')).toHaveCount(1);
  const styles = (page: Page): Promise<string[]> =>
    page
      .locator('.swg-gallery .swg-item')
      .evaluateAll((items) => items.map((el) => el.getAttribute('style') ?? ''));
  expect(await styles(block), 'same plan from the same revision').toEqual(await styles(shortcode));
  const alts = await shortcode
    .locator('.swg-gallery .swg-link img')
    .evaluateAll((imgs) => imgs.map((img) => img.getAttribute('alt')));
  expect(alts).toEqual(SHAPES.map((_, index) => `photo ${index + 1}`));
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

  await page.locator('.swg-gallery a.swg-link').nth(2).focus();
  await page.keyboard.press('Enter');
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

test('a click on the shard (not its bounding box corner) opens the lightbox', async ({
  browser,
}) => {
  const context = await visitor(browser, { viewport: { width: 1440, height: 1100 } });
  const page = await context.newPage();
  await page.goto(urls.main as string);
  await widen(page);
  await loadAll(page);
  await page
    .locator('.swg-gallery')
    .first()
    .evaluate((el) => el.scrollIntoView({ block: 'start' }));
  const result = await geometry(page);
  const shard = result.shards[4] as Shard;
  const cx = shard.poly.reduce((a, p) => a + p[0], 0) / shard.poly.length;
  const cy = shard.poly.reduce((a, p) => a + p[1], 0) / shard.poly.length;
  await page.mouse.click(cx, cy);
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog).toContainText(new RegExp(`5\\s*(of|von)\\s*${SHAPES.length}`));
  await context.close();
});

test('RTL mirrors the plan: the mirrored centre of every shard hits the same shard', async ({
  browser,
}) => {
  const context = await visitor(browser, { viewport: { width: 1440, height: 1100 } });
  const page = await context.newPage();
  await page.goto(urls.main as string);
  await widen(page);
  await loadAll(page);
  await page
    .locator('.swg-gallery')
    .first()
    .evaluate((el) => el.scrollIntoView({ block: 'start' }));
  const order = await hrefs(page);
  const ltr = await geometry(page);
  await page.evaluate(() => {
    document.documentElement.dir = 'rtl';
  });
  const rtl = await geometry(page);
  expect(await hrefs(page), 'DOM order is untouched').toEqual(order);
  expect(rtl.overflow).toBe(false);
  const mirrorX = (x: number): number =>
    ltr.list.left + (ltr.list.right - x) + (rtl.list.left - ltr.list.left);
  for (const [index, shard] of ltr.shards.entries()) {
    const cx = shard.poly.reduce((a, p) => a + p[0], 0) / shard.poly.length;
    const cy = shard.poly.reduce((a, p) => a + p[1], 0) / shard.poly.length;
    const hit = await page.evaluate(
      ([px, py]) => {
        const link = document.elementFromPoint(px as number, py as number)?.closest('a.swg-link');
        return link ? Array.from(document.querySelectorAll('a.swg-link')).indexOf(link) : -1;
      },
      [mirrorX(cx), cy],
    );
    expect(hit, `shard ${index + 1} sits at the mirrored position`).toBe(index);
    const mirrored = rtl.shards[index] as Shard;
    expect(
      Math.abs(mirrored.box.left - rtl.list.left - (ltr.list.right - shard.box.right)),
    ).toBeLessThan(1.5);
  }
  expect(
    Math.abs(
      polygonArea((rtl.shards[0] as Shard).poly) - polygonArea((ltr.shards[0] as Shard).poly),
    ),
  ).toBeLessThan(2);
  const image = await page
    .locator('.swg-gallery .swg-link img')
    .first()
    .evaluate((el) => getComputedStyle(el).transform);
  expect(image, 'the picture itself is mirrored back').toBe('matrix(-1, 0, 0, 1, 0, 0)');
  await shot(page, 'shattered-rtl');
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
  expect(await positions(), 'every tile keeps its box once the images are there').toEqual(before);
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

test('hover lifts the shard with a shadow, reduced motion keeps it still', async ({ browser }) => {
  for (const motion of ['no-preference', 'reduce'] as const) {
    const context = await visitor(browser, {
      viewport: { width: 1440, height: 1100 },
      reducedMotion: motion,
    });
    const page = await context.newPage();
    await page.goto(urls.main as string);
    await widen(page);
    await loadAll(page);
    await page
      .locator('.swg-gallery')
      .first()
      .evaluate((el) => el.scrollIntoView({ block: 'start' }));
    const result = await geometry(page);
    const shard = result.shards[1] as Shard;
    const cx = shard.poly.reduce((a, p) => a + p[0], 0) / shard.poly.length;
    const cy = shard.poly.reduce((a, p) => a + p[1], 0) / shard.poly.length;
    await page.mouse.move(cx, cy);
    await expect
      .poll(() =>
        page
          .locator('.swg-gallery .swg-item')
          .nth(1)
          .evaluate((el) => getComputedStyle(el).filter),
      )
      .toContain('drop-shadow');
    if (motion === 'no-preference') {
      await expect
        .poll(() =>
          page
            .locator('.swg-gallery .swg-item')
            .nth(1)
            .evaluate((el) => getComputedStyle(el).transform),
        )
        .not.toBe('none');
    }
    const state = await page
      .locator('.swg-gallery .swg-item')
      .nth(1)
      .evaluate((el) => {
        const style = getComputedStyle(el);
        return { transform: style.transform, filter: style.filter, z: style.zIndex };
      });
    expect(state.filter, 'drop shadow on the wrapper').toContain('drop-shadow');
    expect(state.z).toBe('2');
    if (motion === 'no-preference') {
      expect(state.transform, 'lifted').not.toBe('none');
      await shot(page, 'shattered-hover');
    } else {
      expect(state.transform, 'no motion').toBe('none');
    }
    await context.close();
  }
});

test('a keyboard-focused shard shows a ring that follows its outline plus an inner ring', async ({
  browser,
}) => {
  const context = await visitor(browser, { viewport: { width: 1440, height: 1100 } });
  const page = await context.newPage();
  await page.goto(urls.main as string);
  await widen(page);
  await loadAll(page);
  await page.keyboard.press('Tab');
  await page.locator('.swg-gallery a.swg-link').nth(1).focus();
  await page.keyboard.press('Shift+Tab');
  await page.keyboard.press('Tab');
  const link = page.locator('.swg-gallery a.swg-link').nth(1);
  const inner = await link.evaluate((el) => {
    const style = getComputedStyle(el, '::after');
    return { width: style.outlineWidth, offset: style.outlineOffset, style: style.outlineStyle };
  });
  expect(inner.style).toBe('solid');
  expect(parseFloat(inner.width)).toBeGreaterThanOrEqual(2);
  expect(parseFloat(inner.offset), 'drawn inside the tile').toBeLessThan(0);
  const filter = await page
    .locator('.swg-gallery .swg-item')
    .nth(1)
    .evaluate((el) => getComputedStyle(el).filter);
  expect(filter, 'outer ring follows the shard').toContain('drop-shadow');
  await page
    .locator('.swg-gallery')
    .first()
    .evaluate((el) => el.scrollIntoView({ block: 'start' }));
  await shot(page, 'shattered-focus');
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

test('a gallery with extreme emphasis keeps healthy shards', async ({ browser }) => {
  for (const width of [1280, 768]) {
    const context = await visitor(browser, { viewport: { width, height: 900 } });
    const page = await context.newPage();
    await page.goto(urls.extreme as string);
    if (width === 1280) await widen(page);
    await loadAll(page);
    const result = await geometry(page);
    expectGlass(result, EXTREME_EMPHASIS.length);
    for (const shard of result.shards) {
      expect(
        Math.min(shard.box.right - shard.box.left, shard.box.bottom - shard.box.top),
      ).toBeGreaterThan(60);
    }
    await shot(page, `shattered-extreme-${width}`);
    await context.close();
  }
});

test('twenty-six shards with grout 5 and a 3:2 wide shape', async ({ browser }) => {
  const context = await visitor(browser, { viewport: { width: 1440, height: 1100 } });
  const page = await context.newPage();
  await page.goto(urls.many as string);
  await widen(page);
  await loadAll(page);
  const result = await geometry(page);
  expect(result.ar).toBe('1.6');
  expect(result.shards).toHaveLength(26);
  expect(result.overflow).toBe(false);
  await shot(page, 'shattered-many-1440');
  await context.close();
});

test('changing `variation` re-shapes the shards and keeps the pictures', async ({ browser }) => {
  const clips = async (): Promise<string[]> => {
    const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();
    await page.goto(urls.varied as string);
    const values = await page
      .locator('.swg-gallery .swg-item')
      .evaluateAll((items) => items.map((el) => el.getAttribute('style') ?? ''));
    await context.close();
    return values;
  };
  const before = await clips();
  const detail = await site.fixtures.detail(variedId);
  expect(
    await site.fixtures.update(variedId, detail.revision, {
      settings: { grout: GROUT, variation: 7 },
    }),
  ).toBe(200);
  const after = await clips();
  expect(after).toHaveLength(before.length);
  expect(after).not.toEqual(before);
});

test('the layout is offered by the REST API', async () => {
  const layouts = await site.fixtures.rest('layouts');
  const data = (await layouts.json()) as { layouts: Array<{ id: string; label: string }> };
  expect(data.layouts.find((layout) => layout.id === 'shattered')?.label).toBe('Shattered');
});
