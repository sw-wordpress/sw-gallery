import { expect, test } from '@playwright/test';
import type { APIRequestContext, Browser, BrowserContext, Page } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { seriousViolations } from '../support/axe';
import { authFile, SCREENSHOT_DIR } from '../support/env';
import { GalleryFixtures, gradientPng, uniquePrefix } from '../support/galleries';

// The playful layouts as a visitor sees them. `scattered`: photo prints with seeded tilt and offsets that stay at least
// 60 % visible, straighten and rise on hover and focus, reduce overlap on touch screens, never cause horizontal scroll.
// `bubbles`: circle packing inside a fixed-ratio container, circular links, optional float animation that respects
// reduced motion, a two column circle grid in narrow containers. Fixtures are created through the REST API.

test.use({ storageState: authFile('administrator') });

const prefix = uniquePrefix('playful');
const TILT = 7;
const OVERLAP = 14;

const SHAPES: Array<[number, number]> = [
  [900, 600],
  [600, 900],
  [800, 800],
  [1200, 800],
  [900, 600],
  [600, 800],
  [1000, 700],
  [800, 600],
];
const PALETTE: Array<[[number, number, number], [number, number, number]]> = [
  [
    [230, 120, 90],
    [120, 40, 60],
  ],
  [
    [90, 190, 130],
    [20, 90, 80],
  ],
  [
    [90, 140, 230],
    [30, 40, 130],
  ],
  [
    [240, 200, 90],
    [150, 90, 30],
  ],
  [
    [190, 120, 220],
    [80, 40, 120],
  ],
  [
    [90, 200, 210],
    [30, 90, 120],
  ],
];
/** Bubbles: emphasis per picture (index = DOM position). */
const EMPHASIS = [3, 1, 1, 2, 1, 1, 3, 1, 2, 1, 1, 1];

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
let bubblesId = 0;

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

async function createGallery(
  title: string,
  layout: string,
  count: number,
  settings: Record<string, unknown>,
  emphasis: number[] = [],
): Promise<number> {
  const ids: number[] = [];
  for (let index = 0; index < count; index += 1) {
    const [width, height] = SHAPES[index % SHAPES.length] as [number, number];
    const [from, to] = PALETTE[index % PALETTE.length] as [
      [number, number, number],
      [number, number, number],
    ];
    const image = await site.fixtures.uploadImage(
      `${prefix} ${title} ${index + 1}`,
      gradientPng(from, to, width / 2, height / 2),
    );
    await setAlt(image.id, `${title} photo ${index + 1}`);
    ids.push(image.id);
  }
  const gallery = await site.fixtures.create({
    title: `${prefix} ${title}`,
    status: 'publish',
    layout,
    settings,
  });
  await site.fixtures.assign(gallery.id, ids);
  if (emphasis.length > 0) {
    const items = await site.fixtures.items(gallery.id);
    for (const [index, item] of items.entries()) {
      const level = emphasis[index % emphasis.length] ?? 1;
      if (level !== 1)
        await site.fixtures.setItemSettings(gallery.id, item.id, { emphasis: level });
    }
  }
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

/** The theme column is narrow (about 650px): let the gallery use the whole viewport. */
async function widen(page: Page): Promise<void> {
  await page.addStyleTag({
    content:
      '.swg-shortcode > *, .entry-content > .swg-gallery, .swg-gallery { max-width: none !important; width: 100% !important; margin-inline: 0 !important; } .swg-shortcode, .entry-content { max-width: none !important; }',
  });
}

async function open(
  browser: Browser,
  url: string,
  width: number,
  options: Record<string, unknown> = {},
  wide = false,
): Promise<{ page: Page; context: BrowserContext }> {
  const context = await visitor(browser, { viewport: { width, height: 2400 }, ...options });
  const page = await context.newPage();
  await page.goto(url);
  if (wide) await widen(page);
  await loadAll(page);
  return { page, context };
}

/** Waits until every running CSS transition has finished (a hover or focus change is then fully applied). */
async function settle(page: Page): Promise<void> {
  await page.evaluate(() =>
    Promise.all(
      document.getAnimations().map((animation) => animation.finished.catch(() => undefined)),
    ),
  );
}

async function overflows(page: Page): Promise<boolean> {
  return page.evaluate(
    () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
  );
}

async function hrefs(page: Page): Promise<string[]> {
  return page
    .locator('.swg-gallery a.swg-link')
    .evaluateAll((links) => links.map((a) => a.getAttribute('href') ?? ''));
}

function fileStem(href: string): string {
  return (href.split('/').pop() ?? '').replace(/\.[a-z0-9]+$/i, '');
}

interface PrintInfo {
  rotation: number;
  scale: number;
  share: number;
  /** A point that shows this print (the visible sample closest to its centre), or null. */
  point: [number, number] | null;
  z: string;
}

/**
 * Per print: rotation and scale from the computed transform, and the visible share: a 11 x 11 grid of points inside
 * the rotated print rectangle (corners from the transform matrix), counted when `elementFromPoint` returns this link.
 */
async function prints(page: Page): Promise<PrintInfo[]> {
  return page
    .locator('.swg-gallery')
    .first()
    .evaluate((root) => {
      const links = Array.from(root.querySelectorAll<HTMLElement>('.swg-link'));
      return links.map((link) => {
        const li = link.parentElement as HTMLElement;
        const cell = li.getBoundingClientRect();
        const cx = cell.left + cell.width / 2;
        const cy = cell.top + cell.height / 2;
        const m = new DOMMatrix(getComputedStyle(link).transform);
        const w = link.offsetWidth;
        const h = link.offsetHeight;
        let visible = 0;
        let total = 0;
        let point: [number, number] | null = null;
        let best = Infinity;
        const steps = 11;
        for (let i = 0; i < steps; i += 1) {
          for (let j = 0; j < steps; j += 1) {
            const lx = ((i + 0.5) / steps - 0.5) * w;
            const ly = ((j + 0.5) / steps - 0.5) * h;
            const x = cx + m.a * lx + m.c * ly + m.e;
            const y = cy + m.b * lx + m.d * ly + m.f;
            total += 1;
            const hit = document.elementFromPoint(x, y);
            if (hit !== null && hit.closest('.swg-link') === link) {
              visible += 1;
              const distance = Math.hypot(lx, ly);
              if (distance < best) {
                best = distance;
                point = [x, y];
              }
            }
          }
        }
        return {
          rotation: (Math.atan2(m.b, m.a) * 180) / Math.PI,
          scale: Math.hypot(m.a, m.b),
          share: visible / total,
          point,
          z: getComputedStyle(li).zIndex,
        };
      });
    });
}

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

  const scattered = await createGallery('scattered', 'scattered', 12, {
    columns: { base: 3, md: 4, lg: 5 },
    tilt: TILT,
    overlap: OVERLAP,
    border: 10,
  });
  const strong = await createGallery('strong', 'scattered', 16, {
    columns: 4,
    tilt: 12,
    overlap: 40,
    border: 6,
    variation: 7,
  });
  bubblesId = await createGallery('bubbles', 'bubbles', 12, { gap: 4 }, EMPHASIS);
  const floating = await createGallery('floating', 'bubbles', 9, { gap: 6, float: true }, EMPHASIS);
  urls.scattered = await createPost('scattered', `[sw_gallery id="${scattered}"]`);
  urls.strong = await createPost('strong', `[sw_gallery id="${strong}"]`);
  urls.bubbles = await createPost('bubbles', `[sw_gallery id="${bubblesId}"]`);
  urls.floating = await createPost('floating', `[sw_gallery id="${floating}"]`);
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

// ---------------------------------------------------------------- scattered

for (const [width, wide] of [
  [1280, true],
  [768, false],
] as const) {
  for (const key of ['scattered', 'strong'] as const) {
    test(`scattered (${key}): every print stays 60 % visible, rotation is bounded, no overflow at ${width}px`, async ({
      browser,
    }) => {
      const { page, context } = await open(browser, urls[key] as string, width, {}, wide);
      const info = await prints(page);
      expect(info).toHaveLength(key === 'strong' ? 16 : 12);
      const bound = key === 'strong' ? 12 : TILT;
      for (const [index, print] of info.entries()) {
        expect(print.share, `print ${index + 1} visible share`).toBeGreaterThanOrEqual(0.6);
        expect(Math.abs(print.rotation), `print ${index + 1} rotation`).toBeLessThanOrEqual(
          bound + 0.05,
        );
        expect(print.scale).toBeGreaterThan(1);
      }
      expect(
        info.some((print) => Math.abs(print.rotation) > 1),
        'prints are visibly tilted',
      ).toBe(true);
      expect(await overflows(page), 'rotation never creates page scroll').toBe(false);
      if (key === 'scattered') await shot(page, `scattered-${width}`);
      if (key === 'strong' && width === 1280) await shot(page, 'scattered-strong-1280');
      await context.close();
    });
  }
}

test('scattered: hover straightens and raises the print', async ({ browser }) => {
  const { page, context } = await open(browser, urls.scattered as string, 1280, {}, true);
  const before = await prints(page);
  const index = before.findIndex((print) => Math.abs(print.rotation) > 2 && print.point !== null);
  expect(index).toBeGreaterThanOrEqual(0);
  const target = before[index] as PrintInfo;
  const [x, y] = target.point as [number, number];
  await page.mouse.move(x, y);
  await settle(page);
  const after = (await prints(page))[index] as PrintInfo;
  expect(Math.abs(after.rotation)).toBeLessThan(0.05);
  expect(after.scale).toBeGreaterThan(target.scale);
  expect(after.z).toBe('100000');
  expect(
    await page.evaluate(
      ([px, py]) =>
        document
          .elementFromPoint(px as number, py as number)
          ?.closest('.swg-link')
          ?.getAttribute('href'),
      [x, y],
    ),
  ).toBe((await hrefs(page))[index]);
  await page.screenshot({
    path: resolve(SCREENSHOT_DIR, 'scattered-hover.png'),
    clip: { x: Math.max(0, x - 260), y: Math.max(0, y - 220), width: 520, height: 440 },
  });
  await context.close();
});

test('scattered: keyboard focus straightens, raises and shows a ring; Tab order is the DOM order', async ({
  browser,
}) => {
  const { page, context } = await open(browser, urls.scattered as string, 1280, {}, true);
  const order = await hrefs(page);
  expect(order).toHaveLength(12);
  await page.locator('.swg-gallery a.swg-link').first().focus();
  const focused: string[] = [];
  for (let i = 0; i < order.length; i += 1) {
    focused.push(await page.evaluate(() => document.activeElement?.getAttribute('href') ?? ''));
    await page.keyboard.press('Tab');
  }
  expect(focused, 'keyboard focus order equals DOM order').toEqual(order);

  await page.keyboard.press('Shift+Tab');
  await settle(page);
  const current = await page.evaluate(() => {
    const el = document.activeElement as HTMLElement;
    const style = getComputedStyle(el);
    return {
      rotation: Math.atan2(new DOMMatrix(style.transform).b, new DOMMatrix(style.transform).a),
      outline: style.outlineStyle,
      width: parseFloat(style.outlineWidth),
      z: getComputedStyle(el.parentElement as HTMLElement).zIndex,
    };
  });
  expect(Math.abs(current.rotation)).toBeLessThan(0.001);
  expect(current.outline).toBe('solid');
  expect(current.width).toBeGreaterThanOrEqual(2);
  expect(current.z).toBe('100000');
  await context.close();
});

test('scattered: the lightbox opens from a print and follows the stored order', async ({
  browser,
}) => {
  const { page, context } = await open(browser, urls.scattered as string, 1280, {}, true);
  const order = await hrefs(page);
  const info = await prints(page);
  const point = (info[2] as PrintInfo).point as [number, number];
  await page.mouse.click(point[0], point[1]);
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

test('scattered: touch screens get less overlap and tilt', async ({ browser }) => {
  const desktop = await open(browser, urls.scattered as string, 768);
  const normal = await prints(desktop.page);
  await desktop.context.close();
  const touch = await open(browser, urls.scattered as string, 768, {
    hasTouch: true,
    isMobile: true,
  });
  expect(await touch.page.evaluate(() => matchMedia('(hover: none)').matches)).toBe(true);
  const reduced = await prints(touch.page);
  const max = (list: PrintInfo[]): number => Math.max(...list.map((p) => Math.abs(p.rotation)));
  const meanScale = (list: PrintInfo[]): number =>
    list.reduce((total, p) => total + p.scale, 0) / list.length;
  expect(max(reduced)).toBeLessThan(max(normal) * 0.7);
  expect(meanScale(reduced)).toBeLessThan(meanScale(normal));
  for (const print of reduced) expect(print.share).toBeGreaterThanOrEqual(0.6);
  expect(await overflows(touch.page)).toBe(false);
  await shot(touch.page, 'scattered-touch-768');
  await touch.context.close();
});

test('scattered: a narrow container keeps two columns with half the tilt', async ({ browser }) => {
  const { page, context } = await open(browser, urls.scattered as string, 375);
  const columns = await page
    .locator('.swg-gallery .swg-items')
    .evaluate((list) => getComputedStyle(list).gridTemplateColumns.split(' ').length);
  expect(columns).toBe(2);
  const info = await prints(page);
  for (const print of info) {
    expect(Math.abs(print.rotation)).toBeLessThanOrEqual(TILT / 2 + 0.05);
    expect(print.share).toBeGreaterThanOrEqual(0.6);
  }
  expect(await overflows(page)).toBe(false);
  await shot(page, 'scattered-375');
  await context.close();
});

test('scattered: RTL mirrors the grid, keeps the DOM order and does not overflow', async ({
  browser,
}) => {
  const { page, context } = await open(browser, urls.scattered as string, 1280, {}, true);
  const order = await hrefs(page);
  await page.evaluate(() => {
    document.documentElement.dir = 'rtl';
  });
  expect(await hrefs(page)).toEqual(order);
  const [first, second] = await page
    .locator('.swg-gallery .swg-item')
    .evaluateAll((items) => items.slice(0, 2).map((el) => el.getBoundingClientRect().left));
  expect(first as number, 'the first print is at the inline start (right)').toBeGreaterThan(
    second as number,
  );
  expect(await overflows(page)).toBe(false);
  for (const print of await prints(page)) expect(print.share).toBeGreaterThanOrEqual(0.6);
  await shot(page, 'scattered-rtl');
  await context.close();
});

test('scattered: reduced motion disables the transition', async ({ browser }) => {
  const motion = await open(browser, urls.scattered as string, 768);
  const normal = await motion.page
    .locator('.swg-gallery .swg-link')
    .first()
    .evaluate((el) => getComputedStyle(el).transitionDuration);
  await motion.context.close();
  const reduced = await open(browser, urls.scattered as string, 768, { reducedMotion: 'reduce' });
  const none = await reduced.page
    .locator('.swg-gallery .swg-link')
    .first()
    .evaluate((el) => getComputedStyle(el).transitionDuration);
  expect(normal).not.toBe('0s');
  expect(none).toBe('0s');
  await reduced.context.close();
});

// ------------------------------------------------------------------ bubbles

interface Circle {
  x: number;
  y: number;
  r: number;
  radius: string;
}

async function circles(
  page: Page,
  gallery = 0,
): Promise<{ list: DOMRect; items: Circle[]; position: string }> {
  return page
    .locator('.swg-gallery')
    .nth(gallery)
    .evaluate((root) => {
      const list = root.querySelector('.swg-items') as HTMLElement;
      const items = Array.from(root.querySelectorAll<HTMLElement>('.swg-link')).map((link) => {
        const box = link.getBoundingClientRect();
        return {
          x: box.left + box.width / 2,
          y: box.top + box.height / 2,
          r: box.width / 2,
          radius: getComputedStyle(link).borderTopLeftRadius,
        };
      });
      return {
        list: list.getBoundingClientRect().toJSON() as DOMRect,
        items,
        position: getComputedStyle(root.querySelector('.swg-item') as Element).position,
      };
    });
}

function expectPacked(result: Awaited<ReturnType<typeof circles>>, gap: number): void {
  const left = result.list.left + gap / 2;
  const right = result.list.right - gap / 2;
  const top = result.list.top + gap / 2;
  const bottom = result.list.bottom - gap / 2;
  for (const [index, c] of result.items.entries()) {
    expect(c.x - c.r, `circle ${index + 1} inside (left)`).toBeGreaterThanOrEqual(left - 1.5);
    expect(c.x + c.r, `circle ${index + 1} inside (right)`).toBeLessThanOrEqual(right + 1.5);
    expect(c.y - c.r, `circle ${index + 1} inside (top)`).toBeGreaterThanOrEqual(top - 1.5);
    expect(c.y + c.r, `circle ${index + 1} inside (bottom)`).toBeLessThanOrEqual(bottom + 1.5);
    expect(c.radius, 'circular link').toBe('50%');
    for (const [other, d] of result.items.entries()) {
      if (other > index) {
        const distance = Math.hypot(c.x - d.x, c.y - d.y);
        expect(
          distance,
          `circles ${index + 1} and ${other + 1} do not overlap`,
        ).toBeGreaterThanOrEqual(c.r + d.r - 1.5);
      }
    }
  }
}

for (const [width, wide] of [
  [1280, true],
  [768, false],
  [375, false],
] as const) {
  test(`bubbles: circles are circular, inside the container and never overlap at ${width}px`, async ({
    browser,
  }) => {
    const { page, context } = await open(browser, urls.bubbles as string, width, {}, wide);
    const result = await circles(page);
    expect(result.items).toHaveLength(12);
    expect(await overflows(page)).toBe(false);
    if (width === 375) {
      expect(result.position, 'below 480px the list is a grid of circles').not.toBe('absolute');
      const columns = await page
        .locator('.swg-gallery .swg-items')
        .evaluate((list) => getComputedStyle(list).gridTemplateColumns.split(' ').length);
      expect(columns).toBe(2);
      for (const c of result.items) expect(c.radius).toBe('50%');
    } else {
      expect(result.position).toBe('absolute');
      expectPacked(result, 4);
      const listWidth = result.list.right - result.list.left;
      const listHeight = result.list.bottom - result.list.top;
      // The packing fills the container: the circles cover a good share of it.
      const area = result.items.reduce((sum, c) => sum + Math.PI * c.r * c.r, 0);
      expect(area / (listWidth * listHeight), 'packing density').toBeGreaterThan(0.5);
    }
    await shot(page, `bubbles-${width}`);
    await context.close();
  });
}

test('bubbles: emphasis changes the size (areas 1 : 1.8 : 3)', async ({ browser }) => {
  const { page, context } = await open(browser, urls.bubbles as string, 1280, {}, true);
  const result = await circles(page);
  const unit = result.items.filter((_, i) => EMPHASIS[i] === 1).map((c) => c.r * c.r);
  const mean = unit.reduce((a, b) => a + b, 0) / unit.length;
  for (const [index, c] of result.items.entries()) {
    const weight = ({ 1: 1, 2: 1.8, 3: 3 } as Record<number, number>)[EMPHASIS[index] as number];
    expect(Math.abs((c.r * c.r) / mean - (weight as number)), `circle ${index + 1}`).toBeLessThan(
      0.15,
    );
  }
  await context.close();
});

test('bubbles: float animates only without a reduced-motion preference', async ({ browser }) => {
  const motion = await open(browser, urls.floating as string, 1280, {}, true);
  const animated = await motion.page
    .locator('.swg-gallery .swg-item')
    .evaluateAll((items) => items.map((el) => getComputedStyle(el).animationName));
  expect(animated.every((name) => name === 'swg-bubble-float')).toBe(true);
  const delays = await motion.page
    .locator('.swg-gallery .swg-item')
    .evaluateAll((items) => items.map((el) => getComputedStyle(el).animationDelay));
  expect(new Set(delays).size, 'seeded delays differ').toBeGreaterThan(2);
  await motion.context.close();

  const reduced = await open(
    browser,
    urls.floating as string,
    1280,
    { reducedMotion: 'reduce' },
    true,
  );
  const none = await reduced.page
    .locator('.swg-gallery .swg-item')
    .evaluateAll((items) => items.map((el) => getComputedStyle(el).animationName));
  expect(none.every((name) => name === 'none')).toBe(true);
  await shot(reduced.page, 'bubbles-floating-1280');
  await reduced.context.close();

  const plain = await open(browser, urls.bubbles as string, 1280, {}, true);
  const off = await plain.page
    .locator('.swg-gallery .swg-item')
    .evaluateAll((items) => items.map((el) => getComputedStyle(el).animationName));
  expect(
    off.every((name) => name === 'none'),
    'float is off by default',
  ).toBe(true);
  await plain.context.close();
});

test('bubbles: hover and focus pop a bubble forward', async ({ browser }) => {
  const { page, context } = await open(browser, urls.bubbles as string, 1280, {}, true);
  const link = page.locator('.swg-gallery a.swg-link').nth(4);
  await link.hover();
  await settle(page);
  expect(await link.evaluate((el) => new DOMMatrix(getComputedStyle(el).transform).a)).toBeCloseTo(
    1.06,
    2,
  );
  await page.mouse.move(0, 0);
  await page.keyboard.press('Tab');
  await page.locator('.swg-gallery a.swg-link').nth(2).focus();
  await page.keyboard.press('Shift+Tab');
  await page.keyboard.press('Tab');
  await settle(page);
  const state = await page
    .locator('.swg-gallery a.swg-link')
    .nth(2)
    .evaluate((el) => {
      const ring = getComputedStyle(el, '::after');
      return {
        scale: new DOMMatrix(getComputedStyle(el).transform).a,
        outline: ring.outlineStyle,
        width: parseFloat(ring.outlineWidth),
        offset: parseFloat(ring.outlineOffset),
        z: getComputedStyle(el.parentElement as HTMLElement).zIndex,
      };
    });
  expect(state.scale).toBeCloseTo(1.06, 2);
  expect(state.outline).toBe('solid');
  expect(state.width).toBeGreaterThanOrEqual(2);
  expect(state.offset, 'ring inside the circle').toBeLessThan(0);
  expect(state.z).toBe('2');
  await context.close();
});

test('bubbles: Tab and the lightbox follow the stored order', async ({ browser }) => {
  const { page, context } = await open(browser, urls.bubbles as string, 1280, {}, true);
  const order = await hrefs(page);
  expect(order).toHaveLength(12);
  await page.locator('.swg-gallery a.swg-link').first().focus();
  const focused: string[] = [];
  for (let i = 0; i < order.length; i += 1) {
    focused.push(await page.evaluate(() => document.activeElement?.getAttribute('href') ?? ''));
    await page.keyboard.press('Tab');
  }
  expect(focused).toEqual(order);
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

test('bubbles: RTL mirrors the packing and keeps the DOM order', async ({ browser }) => {
  const { page, context } = await open(browser, urls.bubbles as string, 1280, {}, true);
  const order = await hrefs(page);
  const ltr = await circles(page);
  await page.evaluate(() => {
    document.documentElement.dir = 'rtl';
  });
  const rtl = await circles(page);
  expect(await hrefs(page)).toEqual(order);
  for (const [index, c] of ltr.items.entries()) {
    const mirrored = rtl.items[index] as Circle;
    expect(
      Math.abs(mirrored.x - rtl.list.left - (ltr.list.right - c.x)),
      `circle ${index + 1} mirrored`,
    ).toBeLessThan(2);
    expect(Math.abs(mirrored.y - c.y)).toBeLessThan(1.5);
  }
  expectPacked(rtl, 4);
  await shot(page, 'bubbles-rtl');
  await context.close();
});

// --------------------------------------------------------------------- axe

for (const scheme of ['light', 'dark'] as const) {
  for (const key of ['scattered', 'bubbles'] as const) {
    test(`${key}: no serious axe violation (${scheme})`, async ({ browser }) => {
      const context = await visitor(browser, { colorScheme: scheme });
      const page = await context.newPage();
      await page.goto(urls[key] as string);
      await loadAll(page);
      expect(await seriousViolations(page, '.swg-gallery')).toEqual([]);
      await context.close();
    });
  }
}
