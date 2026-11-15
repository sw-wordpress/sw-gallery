import { expect, test } from '@playwright/test';
import type { APIRequestContext, Browser, BrowserContext, Page } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { seriousViolations } from '../support/axe';
import { authFile, SCREENSHOT_DIR } from '../support/env';
import { GalleryFixtures, gradientPng, uniquePrefix } from '../support/galleries';

// The honeycomb (hexagon / diamond cells, offset rows) and jigsaw (interlocking pieces, SVG clip paths) layouts as a
// visitor sees them: geometry measured in a real browser, hit testing against the clip, RTL, the plain grid fallback,
// Tab order = stored order, the lightbox, axe and review screenshots.

test.use({ storageState: authFile('administrator') });

const prefix = uniquePrefix('geometric');
const COUNT = 13;
const PALETTE: Array<[[number, number, number], [number, number, number]]> = [
  [
    [230, 90, 70],
    [120, 40, 60],
  ],
  [
    [240, 180, 60],
    [150, 90, 30],
  ],
  [
    [120, 200, 90],
    [30, 110, 70],
  ],
  [
    [70, 190, 200],
    [30, 80, 140],
  ],
  [
    [110, 120, 230],
    [60, 40, 140],
  ],
  [
    [200, 100, 220],
    [110, 40, 120],
  ],
  [
    [240, 130, 160],
    [140, 50, 90],
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
let imageIds: number[] = [];

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

async function createGallery(
  key: string,
  layout: string,
  settings: Record<string, unknown>,
): Promise<number> {
  const gallery = await site.fixtures.create({
    title: `${prefix} ${key}`,
    status: 'publish',
    layout,
    settings,
  });
  await site.fixtures.assign(gallery.id, imageIds);
  urls[key] = await createPost(key, `[sw_gallery id="${gallery.id}"]`);
  return gallery.id;
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

/** The theme column is narrow: let the gallery use the whole viewport. */
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

interface Measure {
  list: Box;
  items: Box[];
  links: Box[];
  overflow: boolean;
  clip: string[];
  position: string;
  display: string;
  columns: number;
}

async function measure(page: Page): Promise<Measure> {
  return page
    .locator('.swg-gallery')
    .first()
    .evaluate((root) => {
      const box = (el: Element): Box => {
        const r = el.getBoundingClientRect();
        return { left: r.left, top: r.top, right: r.right, bottom: r.bottom };
      };
      const list = root.querySelector('.swg-items') as HTMLElement;
      const items = Array.from(root.querySelectorAll('.swg-item'));
      const tops = items.map((el) => Math.round(el.getBoundingClientRect().top));
      const firstTop = tops[0] as number;
      return {
        list: box(list),
        items: items.map(box),
        links: Array.from(root.querySelectorAll('.swg-link')).map(box),
        overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
        clip: Array.from(root.querySelectorAll('.swg-link')).map(
          (el) => getComputedStyle(el).clipPath,
        ),
        position: getComputedStyle(root.querySelector('.swg-link') as Element).position,
        display: getComputedStyle(list).display,
        columns: tops.filter((top) => Math.abs(top - firstTop) < 2).length,
      };
    });
}

async function hrefs(page: Page): Promise<string[]> {
  return page
    .locator('.swg-gallery a.swg-link')
    .evaluateAll((links) => links.map((a) => a.getAttribute('href') ?? ''));
}

function fileStem(href: string): string {
  return (href.split('/').pop() ?? '').replace(/\.[a-z0-9]+$/i, '');
}

const w = (b: Box): number => b.right - b.left;
const h = (b: Box): number => b.bottom - b.top;

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

  imageIds = [];
  for (let index = 0; index < COUNT; index += 1) {
    const [from, to] = PALETTE[index % PALETTE.length] as (typeof PALETTE)[number];
    const image = await site.fixtures.uploadImage(
      `${prefix} ${index + 1}`,
      gradientPng(from, to, 480, 480),
    );
    await site.request.post(site.restRoot.replace('swg/v1', 'wp/v2') + `media/${image.id}`, {
      headers: { 'X-WP-Nonce': site.nonce },
      data: { alt_text: `Picture ${index + 1}` },
    });
    imageIds.push(image.id);
  }
  await createGallery('hex4', 'honeycomb', { columns: 4, gap: 6 });
  for (const columns of [2, 3, 6, 9]) {
    await createGallery(`hex${columns}`, 'honeycomb', { columns, gap: 4 });
  }
  await createGallery('diamond', 'honeycomb', { columns: 4, gap: 6, shape: 'diamond' });
  await createGallery('jig', 'jigsaw', { columns: 4 });
  await createGallery('jiggap', 'jigsaw', { columns: 3, gap: 10, ratio: '4:3', tab: 24 });
  await createGallery('jigvar', 'jigsaw', { columns: 4, variation: 5 });
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

// ---------------------------------------------------------------------------------------------------- honeycomb

for (const width of [1280, 768]) {
  test(`honeycomb rows interlock and odd rows are offset by half a cell at ${width}px`, async ({
    browser,
  }) => {
    const context = await visitor(browser, { viewport: { width, height: 900 } });
    const page = await context.newPage();
    await page.goto(urls.hex4 as string);
    if (width === 1280) await widen(page);
    await loadAll(page);
    const m = await measure(page);
    expect(m.overflow, 'no horizontal overflow').toBe(false);
    expect(m.columns).toBe(4);
    const cell = m.items[0] as Box;
    const ratio = h(cell) / w(cell);
    expect(ratio, 'pointy-top hexagon, height = 2w / sqrt(3)').toBeCloseTo(1.1547, 2);
    const row1 = m.items[4] as Box;
    const row2 = m.items[8] as Box;
    expect((row1.top - cell.top) / h(cell), 'row pitch is 0.75 of the cell height').toBeCloseTo(
      0.75,
      2,
    );
    expect((row2.top - row1.top) / h(cell)).toBeCloseTo(0.75, 2);
    expect((row1.left - cell.left) / w(cell), 'odd row offset by half a cell').toBeCloseTo(0.5, 2);
    expect(Math.abs(row2.left - cell.left), 'even rows line up again').toBeLessThan(1);
    // The partly filled last row (item 13 = row 3, odd) keeps its offset and starts at the inline start.
    const last = m.items[12] as Box;
    expect((last.left - cell.left) / w(cell)).toBeCloseTo(0.5, 2);
    for (const item of m.items) {
      expect(item.left).toBeGreaterThanOrEqual(m.list.left - 1);
      expect(item.right).toBeLessThanOrEqual(m.list.right + 1);
    }
    expect(m.clip[0]).toContain('polygon');
    await shot(page, `honeycomb-${width}`);
    await context.close();
  });
}

for (const columns of [2, 3, 6, 9]) {
  test(`honeycomb with ${columns} cells per row keeps the geometry`, async ({ browser }) => {
    const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();
    await page.goto(urls[`hex${columns}`] as string);
    await widen(page);
    await loadAll(page);
    const m = await measure(page);
    expect(m.overflow).toBe(false);
    expect(m.columns).toBe(columns);
    const cell = m.items[0] as Box;
    const row1 = m.items[columns] as Box;
    expect((row1.top - cell.top) / h(cell)).toBeCloseTo(0.75, 2);
    expect((row1.left - cell.left) / w(cell)).toBeCloseTo(0.5, 2);
    const row0Right = (m.items[columns - 1] as Box).right;
    expect(row0Right, 'a row ends at the container minus the half cell').toBeCloseTo(
      m.list.right - w(cell) / 2,
      0,
    );
    expect(m.items.length).toBe(COUNT);
    if (columns === 9) await shot(page, `honeycomb-9-1280`);
    await context.close();
  });
}

test('the diamond shape uses the same engine (pitch 0.5)', async ({ browser }) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.diamond as string);
  await widen(page);
  await loadAll(page);
  const m = await measure(page);
  const cell = m.items[0] as Box;
  expect(h(cell) / w(cell)).toBeCloseTo(1, 2);
  expect(((m.items[4] as Box).top - cell.top) / h(cell)).toBeCloseTo(0.5, 2);
  expect(((m.items[4] as Box).left - cell.left) / w(cell)).toBeCloseTo(0.5, 2);
  expect(m.clip[0]).toContain('polygon');
  await shot(page, 'honeycomb-diamond-1280');
  await context.close();
});

test('honeycomb RTL mirrors the offset and keeps the DOM order', async ({ browser }) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.hex4 as string);
  await widen(page);
  await loadAll(page);
  const order = await hrefs(page);
  await page.evaluate(() => {
    document.documentElement.dir = 'rtl';
  });
  const m = await measure(page);
  expect(await hrefs(page)).toEqual(order);
  const cell = m.items[0] as Box;
  expect(Math.abs(cell.right - m.list.right), 'first cell at the right edge').toBeLessThan(1.5);
  const row1 = m.items[4] as Box;
  expect((cell.right - row1.right) / w(cell), 'odd row offset to the left').toBeCloseTo(0.5, 2);
  expect(m.overflow).toBe(false);
  await shot(page, 'honeycomb-rtl-1280');
  await context.close();
});

test('honeycomb at 375px is a plain two column grid', async ({ browser }) => {
  const context = await visitor(browser, { viewport: { width: 375, height: 800 } });
  const page = await context.newPage();
  await page.goto(urls.hex4 as string);
  await loadAll(page);
  const m = await measure(page);
  expect(m.overflow).toBe(false);
  expect(m.columns).toBe(2);
  expect(m.clip[0]).toBe('none');
  expect(w(m.items[0] as Box)).toBeGreaterThan(100);
  await shot(page, 'honeycomb-375');
  await context.close();
});

test('honeycomb: Tab follows the stored order, the ring is visible, the lightbox navigates in order', async ({
  browser,
}) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.hex4 as string);
  await loadAll(page);
  const order = await hrefs(page);
  expect(order).toHaveLength(COUNT);
  await page.locator('.swg-gallery a.swg-link').first().focus();
  const focused: string[] = [];
  for (let i = 0; i < order.length; i += 1) {
    focused.push(await page.evaluate(() => document.activeElement?.getAttribute('href') ?? ''));
    await page.keyboard.press('Tab');
  }
  expect(focused).toEqual(order);

  await page.keyboard.press('Shift+Tab');
  await page.locator('.swg-gallery a.swg-link').nth(5).focus();
  await page.keyboard.press('Shift+Tab');
  await page.keyboard.press('Tab');
  const ring = await page
    .locator('.swg-gallery .swg-item')
    .nth(5)
    .evaluate((el) => getComputedStyle(el).filter);
  expect(ring, 'a ring that follows the clipped shape').toContain('drop-shadow');
  await page
    .locator('.swg-gallery .swg-item')
    .nth(5)
    .screenshot({
      path: resolve(SCREENSHOT_DIR, 'honeycomb-focus.png'),
    });

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
  await context.close();
});

test('honeycomb hover lifts the cell with a drop shadow', async ({ browser }) => {
  const context = await visitor(browser, {
    viewport: { width: 1280, height: 900 },
    reducedMotion: 'no-preference',
  });
  const page = await context.newPage();
  await page.goto(urls.hex4 as string);
  await loadAll(page);
  const item = page.locator('.swg-gallery .swg-item').nth(5);
  await item.hover();
  await expect
    .poll(() => item.evaluate((el) => getComputedStyle(el).filter))
    .toContain('drop-shadow');
  expect(await item.evaluate((el) => getComputedStyle(el).zIndex)).toBe('2');
  await context.close();
});

// ---------------------------------------------------------------------------------------------------- jigsaw

interface Piece {
  slot: Box;
  link: Box;
  code: number;
}

async function pieces(page: Page): Promise<{ list: Box; pieces: Piece[]; columns: number }> {
  return page
    .locator('.swg-gallery')
    .first()
    .evaluate((root) => {
      const box = (el: Element): Box => {
        const r = el.getBoundingClientRect();
        return { left: r.left, top: r.top, right: r.right, bottom: r.bottom };
      };
      const items = Array.from(root.querySelectorAll('.swg-item'));
      const tops = items.map((el) => Math.round(el.getBoundingClientRect().top));
      return {
        list: box(root.querySelector('.swg-items') as Element),
        columns: tops.filter((top) => Math.abs(top - (tops[0] as number)) < 2).length,
        pieces: items.map((el) => {
          const link = el.querySelector('.swg-link') as Element;
          const style = getComputedStyle(link).clipPath;
          const ref = /#([^")]+)/.exec(style)?.[1] ?? '';
          const target = ref === '' ? null : document.getElementById(ref);
          return {
            slot: box(el),
            link: box(link),
            code: target === null ? -1 : Number(ref.replace(/.*-jc/, '')),
          };
        }),
      };
    });
}

/** Side values of an edge code, as JigsawEdges: [top, right, bottom, left]; 1 = tab, 2 = blank. */
const sides = (code: number): number[] => [
  code % 3,
  Math.floor(code / 3) % 3,
  Math.floor(code / 9) % 3,
  Math.floor(code / 27) % 3,
];

for (const width of [1280, 768]) {
  test(`jigsaw pieces tile and interlock at ${width}px`, async ({ browser }) => {
    const context = await visitor(browser, { viewport: { width, height: 900 } });
    const page = await context.newPage();
    await page.goto(urls.jig as string);
    if (width === 1280) await widen(page);
    await loadAll(page);
    const m = await measure(page);
    expect(m.overflow, 'oversized pieces never widen the page').toBe(false);
    const result = await pieces(page);
    expect(result.columns).toBe(4);
    const slotW = w((result.pieces[0] as Piece).slot);
    const depth = 0.18 * slotW;
    for (const [index, piece] of result.pieces.entries()) {
      expect(
        piece.code,
        `piece ${index + 1} references an existing clipPath`,
      ).toBeGreaterThanOrEqual(0);
      expect(w(piece.link), 'piece box = slot + tab depth on each side').toBeCloseTo(
        slotW + 2 * depth,
        0,
      );
      const right = result.pieces[index + 1];
      if (index % 4 < 3 && right !== undefined) {
        expect(
          Math.abs(piece.slot.right - right.slot.left),
          'slots touch (gap 0): no holes',
        ).toBeLessThan(1);
        expect(
          piece.link.right - right.link.left,
          'piece boxes overlap by about 2 x tab depth',
        ).toBeCloseTo(2 * depth, 0);
        // tab of one piece meets the blank of the next
        expect((sides(piece.code)[1] as number) + (sides(right.code)[3] as number)).toBe(3);
      }
      const below = result.pieces[index + 4];
      if (below !== undefined) {
        expect(Math.abs(piece.slot.bottom - below.slot.top)).toBeLessThan(1);
        expect((sides(piece.code)[2] as number) + (sides(below.code)[0] as number)).toBe(3);
      }
    }
    // The outer edges are flat.
    for (const [index, piece] of result.pieces.entries()) {
      if (index % 4 === 0) expect(sides(piece.code)[3]).toBe(0);
      if (index < 4) expect(sides(piece.code)[0]).toBe(0);
    }
    await shot(page, `jigsaw-${width}`);
    await context.close();
  });
}

async function hitTargets(
  page: Page,
  direction: 'ltr' | 'rtl',
): Promise<{ tab: number; beside: number; checked: number }> {
  return page.evaluate((dir) => {
    const items = Array.from(document.querySelectorAll('.swg-gallery .swg-item'));
    const gallery = document.querySelector('.swg-gallery') as Element;
    const tab = parseFloat(getComputedStyle(gallery).getPropertyValue('--swg-tab')) / 100;
    const gap = parseFloat(getComputedStyle(gallery).getPropertyValue('--swg-gap')) || 0;
    const hit = (x: number, y: number): number =>
      items.indexOf(document.elementFromPoint(x, y)?.closest('.swg-item') as Element);
    const codeOf = (el: Element): number => {
      const ref =
        /#([^")]+)/.exec(
          getComputedStyle(el.querySelector('.swg-link') as Element).clipPath,
        )?.[1] ?? '';
      return Number(ref.replace(/.*-jc/, ''));
    };
    const sign = dir === 'rtl' ? -1 : 1;
    let tabHits = 0;
    let besideHits = 0;
    let checked = 0;
    const columns = items.filter(
      (el) => Math.abs(el.getBoundingClientRect().top - items[0]!.getBoundingClientRect().top) < 2,
    ).length;
    for (let index = 0; index + 1 < items.length; index += 1) {
      if (index % columns === columns - 1) continue;
      const a = items[index] as Element;
      const rect = a.getBoundingClientRect();
      const right = Math.floor(codeOf(a) / 3) % 3; // logical end side of A
      if (right !== 1) continue;
      const depth = tab * rect.width;
      const edge = dir === 'rtl' ? rect.left : rect.right;
      const y = (rect.top + rect.bottom) / 2;
      checked += 1;
      // inside the tab: beyond the slot of A, still A
      if (hit(edge + sign * (depth * 0.5 + gap), y) === index) tabHits += 1;
      // beside the tab (a fifth of the edge from the corner): the neighbour's solid material
      const beside = rect.top + rect.height * 0.12;
      if (hit(edge + sign * (depth * 0.5 + gap + 1), beside) === index + 1) besideHits += 1;
    }
    return { tab: tabHits, beside: besideHits, checked };
  }, direction);
}

for (const [key, direction] of [
  ['jig', 'ltr'],
  ['jiggap', 'ltr'],
  ['jig', 'rtl'],
] as const) {
  test(`jigsaw hit testing follows the clip (${key}, ${direction})`, async ({ browser }) => {
    const context = await visitor(browser, { viewport: { width: 1280, height: 1400 } });
    const page = await context.newPage();
    await page.goto(urls[key] as string);
    await loadAll(page);
    if (direction === 'rtl') {
      await page.evaluate(() => {
        document.documentElement.dir = 'rtl';
      });
    }
    await page.locator('.swg-gallery').first().scrollIntoViewIfNeeded();
    const result = await hitTargets(page, direction);
    expect(result.checked, 'there are tabs to test').toBeGreaterThan(1);
    expect(result.tab, 'a point inside a tab hits its own piece').toBe(result.checked);
    expect(result.beside, 'a point beside the tab hits the neighbour').toBe(result.checked);
    await context.close();
  });
}

test('jigsaw: clip-path references an existing clipPath, the SVG is zero sized and hidden from AT', async ({
  browser,
}) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.jig as string);
  const info = await page
    .locator('.swg-gallery')
    .first()
    .evaluate((root) => {
      const svg = root.querySelector('svg.swg-jigsaw-defs') as SVGElement;
      const rect = svg.getBoundingClientRect();
      const refs = Array.from(root.querySelectorAll('.swg-link')).map(
        (el) => /#([^")]+)/.exec(getComputedStyle(el).clipPath)?.[1] ?? '',
      );
      return {
        width: rect.width,
        height: rect.height,
        hidden: svg.getAttribute('aria-hidden'),
        display: getComputedStyle(svg).display,
        refs,
        exists: refs.every((ref) => root.querySelector(`clipPath[id="${ref}"]`) !== null),
        instance: root.getAttribute('data-swg-instance'),
      };
    });
  expect(info.width).toBe(0);
  expect(info.height).toBe(0);
  expect(info.hidden).toBe('true');
  expect(info.display).not.toBe('none');
  expect(info.exists).toBe(true);
  expect(info.refs.length).toBe(COUNT);
  for (const ref of info.refs) expect(ref.startsWith(`${info.instance}-`)).toBe(true);
  await context.close();
});

test('jigsaw: `variation` changes the arrangement, the same settings repeat it', async ({
  browser,
}) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  const codes = async (key: string): Promise<number[]> => {
    await page.goto(urls[key] as string);
    return (await pieces(page)).pieces.map((piece) => piece.code);
  };
  const base = await codes('jig');
  expect(await codes('jig')).toEqual(base);
  expect(await codes('jigvar')).not.toEqual(base);
  await context.close();
});

test('jigsaw RTL mirrors the pieces and keeps the DOM order and upright images', async ({
  browser,
}) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.jig as string);
  await widen(page);
  await loadAll(page);
  const order = await hrefs(page);
  const ltr = await pieces(page);
  await page.evaluate(() => {
    document.documentElement.dir = 'rtl';
  });
  const rtl = await pieces(page);
  expect(await hrefs(page)).toEqual(order);
  for (const [index, piece] of ltr.pieces.entries()) {
    const mirrored = (rtl.pieces[index] as Piece).slot;
    expect(
      Math.abs(mirrored.left - rtl.list.left - (ltr.list.right - piece.slot.right)),
    ).toBeLessThan(1.5);
  }
  await expect
    .poll(
      () =>
        page
          .locator('.swg-gallery .swg-link img')
          .first()
          .evaluate((img) => {
            const matrix = (el: Element): number => new DOMMatrix(getComputedStyle(el).transform).a;
            return matrix(img) * matrix(img.parentElement as Element);
          }),
      { message: 'the picture is mirrored twice: upright' },
    )
    .toBe(1);
  expect((await measure(page)).overflow).toBe(false);
  await shot(page, 'jigsaw-rtl-1280');
  await context.close();
});

test('jigsaw at 375px falls back to a plain grid with rounded corners', async ({ browser }) => {
  const context = await visitor(browser, { viewport: { width: 375, height: 800 } });
  const page = await context.newPage();
  await page.goto(urls.jig as string);
  await loadAll(page);
  const m = await measure(page);
  expect(m.overflow).toBe(false);
  expect(m.columns).toBe(2);
  expect(m.clip[0]).toBe('none');
  expect(m.position).not.toBe('absolute');
  const radius = await page
    .locator('.swg-gallery .swg-link')
    .first()
    .evaluate((el) => getComputedStyle(el).borderTopLeftRadius);
  expect(radius).toBe('8px');
  await shot(page, 'jigsaw-375');
  await context.close();
});

test('jigsaw with gap, 4:3 pieces and large tabs', async ({ browser }) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.jiggap as string);
  await widen(page);
  await loadAll(page);
  const result = await pieces(page);
  const slot = (result.pieces[0] as Piece).slot;
  expect(w(slot) / h(slot)).toBeCloseTo(4 / 3, 2);
  expect((result.pieces[1] as Piece).slot.left - slot.right).toBeCloseTo(10, 0);
  expect(w((result.pieces[0] as Piece).link)).toBeCloseTo(w(slot) * 1.48, 0);
  await shot(page, 'jigsaw-gap-1280');
  await context.close();
});

test('jigsaw: Tab order, the focus ring follows the piece, hover lifts it, the lightbox navigates in order', async ({
  browser,
}) => {
  const context = await visitor(browser, {
    viewport: { width: 1280, height: 900 },
    reducedMotion: 'no-preference',
  });
  const page = await context.newPage();
  await page.goto(urls.jig as string);
  await loadAll(page);
  const order = await hrefs(page);
  expect(order).toHaveLength(COUNT);
  await page.locator('.swg-gallery a.swg-link').first().focus();
  const focused: string[] = [];
  for (let i = 0; i < order.length; i += 1) {
    focused.push(await page.evaluate(() => document.activeElement?.getAttribute('href') ?? ''));
    await page.keyboard.press('Tab');
  }
  expect(focused).toEqual(order);

  await page.keyboard.press('Shift+Tab');
  await page.locator('.swg-gallery a.swg-link').nth(5).focus();
  await page.keyboard.press('Shift+Tab');
  await page.keyboard.press('Tab');
  const item = page.locator('.swg-gallery .swg-item').nth(5);
  expect(await item.evaluate((el) => getComputedStyle(el).filter)).toContain('drop-shadow');
  await mkdir(SCREENSHOT_DIR, { recursive: true });
  await page
    .locator('.swg-gallery')
    .first()
    .screenshot({ path: resolve(SCREENSHOT_DIR, 'jigsaw-focus.png') });

  await page.locator('.swg-gallery a.swg-link').nth(7).blur();
  await item.hover();
  await expect.poll(() => item.evaluate((el) => getComputedStyle(el).zIndex)).toBe('2');
  await expect
    .poll(() => item.evaluate((el) => getComputedStyle(el).filter))
    .toContain('drop-shadow');
  await page
    .locator('.swg-gallery')
    .first()
    .screenshot({ path: resolve(SCREENSHOT_DIR, 'jigsaw-hover.png') });

  await page.locator('.swg-gallery a.swg-link').nth(2).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog).toContainText(new RegExp(`3\\s*(of|von)\\s*${order.length}`));
  await page.keyboard.press('ArrowRight');
  await expect(dialog).toContainText(new RegExp(`4\\s*(of|von)\\s*${order.length}`));
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await context.close();
});

for (const key of ['hex4', 'jig']) {
  for (const scheme of ['light', 'dark'] as const) {
    test(`no serious axe violation: ${key} (${scheme})`, async ({ browser }) => {
      const context = await visitor(browser, { colorScheme: scheme });
      const page = await context.newPage();
      await page.goto(urls[key] as string);
      await loadAll(page);
      expect(await seriousViolations(page, '.swg-gallery')).toEqual([]);
      await context.close();
    });
  }
}
