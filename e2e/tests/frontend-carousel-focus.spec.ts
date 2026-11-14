import { expect, test } from '@playwright/test';
import type { APIRequestContext, Browser, BrowserContext, Page } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { seriousViolations } from '../support/axe';
import { authFile, PLUGIN_PAGE, SCREENSHOT_DIR } from '../support/env';
import { GalleryFixtures, gradientPng, uniquePrefix } from '../support/galleries';

// The carousel (film strip) and focus (spotlight) layouts as a visitor sees them (cookie-less contexts): behaviour
// without JavaScript (native scroll snap, CSS :target), with the enhancement scripts, RTL, lightbox, axe and review
// screenshots. Fixtures are created through the REST API as administrator.

test.use({ storageState: authFile('administrator') });

const prefix = uniquePrefix('carfocus');

/** Mixed ratios: landscape, portrait, square, wide, portrait, landscape, panorama, 4:3, portrait, landscape. */
const SHAPES: Array<[number, number]> = [
  [1200, 800],
  [800, 1200],
  [1000, 1000],
  [1600, 900],
  [900, 1200],
  [1200, 800],
  [1600, 640],
  [1200, 900],
  [800, 1200],
  [1200, 800],
];
const COLORS: Array<[[number, number, number], [number, number, number]]> = [
  [
    [231, 76, 60],
    [142, 68, 173],
  ],
  [
    [22, 160, 133],
    [41, 128, 185],
  ],
  [
    [243, 156, 18],
    [211, 84, 0],
  ],
  [
    [44, 62, 80],
    [127, 140, 141],
  ],
  [
    [39, 174, 96],
    [241, 196, 15],
  ],
  [
    [142, 68, 173],
    [52, 152, 219],
  ],
  [
    [192, 57, 43],
    [230, 126, 34],
  ],
  [
    [26, 188, 156],
    [52, 73, 94],
  ],
  [
    [232, 67, 147],
    [253, 121, 168],
  ],
  [
    [0, 184, 148],
    [9, 132, 227],
  ],
];
const COUNT = SHAPES.length;

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

/** Loads every image (they are lazy) so measurements and screenshots show the final state. */
async function loadAll(page: Page): Promise<void> {
  await page.evaluate(async () => {
    const imgs = Array.from(document.querySelectorAll<HTMLImageElement>('.swg-gallery img'));
    for (const img of imgs) img.loading = 'eager';
    await Promise.all(imgs.map((img) => img.decode().catch(() => undefined)));
    const deadline = Date.now() + 8000;
    while (Date.now() < deadline && !imgs.every((img) => img.complete && img.naturalWidth > 0)) {
      await new Promise((resolveWait) => setTimeout(resolveWait, 50));
    }
  });
}

async function shot(page: Page, name: string, selector = '.swg-gallery'): Promise<void> {
  await mkdir(SCREENSHOT_DIR, { recursive: true });
  await loadAll(page);
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.evaluate(
    () => new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done))),
  );
  if (selector === 'body') {
    await page.screenshot({ path: resolve(SCREENSHOT_DIR, `${name}.png`), fullPage: true });
  } else {
    await page
      .locator(selector)
      .first()
      .screenshot({ path: resolve(SCREENSHOT_DIR, `${name}.png`) });
  }
}

async function hrefs(page: Page, gallery = 0): Promise<string[]> {
  return page
    .locator('.swg-gallery')
    .nth(gallery)
    .locator('a.swg-link')
    .evaluateAll((links) => links.map((a) => a.getAttribute('href') ?? ''));
}

function fileStem(href: string): string {
  return (href.split('/').pop() ?? '').replace(/\.[a-z0-9]+$/i, '');
}

const pageOverflow = (page: Page): Promise<boolean> =>
  page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);

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
    const [from, to] = COLORS[index] as (typeof COLORS)[number];
    const image = await fixtures.uploadImage(
      `${prefix} image ${index + 1}`,
      gradientPng(from, to, width, height),
    );
    await setAlt(image.id, `Photo ${index + 1}`);
    ids.push(image.id);
  }

  const carousel = await fixtures.create({
    title: `${prefix} carousel`,
    status: 'publish',
    layout: 'carousel',
    settings: { height: { base: 200, md: 300 }, gap: 10, radius: 6 },
  });
  await fixtures.assign(carousel.id, ids);
  const centred = await fixtures.create({
    title: `${prefix} carousel centred`,
    status: 'publish',
    layout: 'carousel',
    settings: { height: 220, snap: 'center', buttons: false },
  });
  await fixtures.assign(centred.id, ids.slice(0, 5));
  const focus = await fixtures.create({
    title: `${prefix} focus`,
    status: 'publish',
    layout: 'focus',
    settings: { thumbSize: 72, gap: 10 },
  });
  await fixtures.assign(focus.id, ids);
  const beside = await fixtures.create({
    title: `${prefix} focus beside`,
    status: 'publish',
    layout: 'focus',
    settings: { thumbs: 'start', stageRatio: '16:9', thumbSize: 64 },
  });
  await fixtures.assign(beside.id, ids);

  urls.carouselId = String(carousel.id);
  urls.focusId = String(focus.id);
  urls.carousel = await createPost('carousel', `[sw_gallery id="${carousel.id}"]`);
  urls.carouselBlock = await createPost(
    'carousel block',
    `<!-- wp:sw-gallery/gallery {"galleryId":${carousel.id}} /-->`,
  );
  urls.carouselCentred = await createPost('carousel centred', `[sw_gallery id="${centred.id}"]`);
  urls.focus = await createPost('focus', `[sw_gallery id="${focus.id}"]`);
  urls.focusBlock = await createPost(
    'focus block',
    `<!-- wp:sw-gallery/gallery {"galleryId":${focus.id}} /-->`,
  );
  urls.beside = await createPost('focus beside', `[sw_gallery id="${beside.id}"]`);
  urls.twice = await createPost(
    'focus twice',
    `[sw_gallery id="${focus.id}"]\n\n[sw_gallery id="${focus.id}"]`,
  );
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

// ---------------------------------------------------------------------------------------------------- carousel

const viewport = '.swg-carousel__viewport';

test('carousel: without JavaScript it is a focusable scroll-snap strip that the keyboard can scroll', async ({
  browser,
}) => {
  const context = await visitor(browser, {
    javaScriptEnabled: false,
    viewport: { width: 1280, height: 900 },
  });
  const page = await context.newPage();
  await page.goto(urls.carousel as string);
  await page.waitForLoadState('load');

  const strip = page.locator(viewport);
  await expect(strip).toHaveAttribute('role', 'region');
  await expect(strip).toHaveAttribute('aria-label', 'Image carousel');
  await expect(strip).toHaveAttribute('tabindex', '0');
  await expect(page.locator('.swg-carousel__btn')).toHaveCount(0);
  const styles = await strip.evaluate((el) => {
    const css = getComputedStyle(el);
    return {
      snap: css.scrollSnapType,
      overscroll: css.overscrollBehaviorX,
      overflowX: css.overflowX,
      scrollable: el.scrollWidth > el.clientWidth + 50,
      snapAlign: getComputedStyle(el.querySelector('.swg-item') as Element).scrollSnapAlign,
    };
  });
  expect(styles.snap).toContain('x mandatory');
  expect(styles.overscroll).toBe('contain');
  expect(styles.overflowX).toBe('auto');
  expect(styles.snapAlign).toContain('start');
  expect(styles.scrollable).toBe(true);
  expect(await pageOverflow(page)).toBe(false);

  await loadAll(page).catch(() => undefined);
  await strip.focus();
  expect(await strip.evaluate((el) => el.scrollLeft)).toBe(0);
  for (let i = 0; i < 6; i += 1) await page.keyboard.press('ArrowRight');
  await expect.poll(() => strip.evaluate((el) => el.scrollLeft)).toBeGreaterThan(100);
  await context.close();
});

test('carousel: buttons scroll one slide, are marked at the ends and the position text follows', async ({
  browser,
}) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.carousel as string);
  await loadAll(page);
  const order = await hrefs(page);
  const strip = page.locator(viewport);
  const prev = page.getByRole('button', { name: 'Previous image' });
  const next = page.getByRole('button', { name: 'Next image' });
  const status = page.locator('.swg-carousel__status');

  await expect(prev).toHaveAttribute('aria-disabled', 'true');
  await expect(next).toHaveAttribute('aria-disabled', 'false');
  await expect(status).toHaveText(`1 of ${COUNT}`);
  await expect(status).toHaveAttribute('aria-live', 'polite');

  const widthOfFirst = (await page.locator('.swg-item').first().boundingBox())?.width ?? 0;
  await next.click();
  await expect(status).toHaveText(`2 of ${COUNT}`);
  const first = await strip.evaluate((el) => el.scrollLeft);
  expect(first, 'about one slide').toBeGreaterThan(widthOfFirst * 0.8);
  expect(first).toBeLessThan(widthOfFirst * 1.3);
  await expect(prev).toHaveAttribute('aria-disabled', 'false');

  await prev.click();
  await expect(status).toHaveText(`1 of ${COUNT}`);
  await expect.poll(() => strip.evaluate((el) => el.scrollLeft)).toBeLessThan(2);

  for (let i = 0; i < COUNT + 2 && (await next.getAttribute('aria-disabled')) !== 'true'; i += 1) {
    const shown = await status.textContent();
    await next.click();
    await expect(status).not.toHaveText(shown ?? '');
  }
  await expect(next).toHaveAttribute('aria-disabled', 'true');
  await expect(status).toHaveText(`${COUNT} of ${COUNT}`);
  await expect(next, 'a button at the end keeps the keyboard focus').toBeFocused();
  expect(await pageOverflow(page)).toBe(false);
  expect(await hrefs(page), 'DOM order is untouched').toEqual(order);
  await context.close();
});

test('carousel: arrow keys, Home and End work inside the strip', async ({ browser }) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.carousel as string);
  await loadAll(page);
  const strip = page.locator(viewport);
  const status = page.locator('.swg-carousel__status');
  await strip.focus();
  await page.keyboard.press('ArrowRight');
  await expect(status).toHaveText(`2 of ${COUNT}`);
  await page.keyboard.press('ArrowRight');
  await expect(status).toHaveText(`3 of ${COUNT}`);
  await page.keyboard.press('ArrowLeft');
  await expect(status).toHaveText(`2 of ${COUNT}`);
  await page.keyboard.press('End');
  await expect(status).toHaveText(`${COUNT} of ${COUNT}`);
  await page.keyboard.press('Home');
  await expect(status).toHaveText(`1 of ${COUNT}`);
  await context.close();
});

test('carousel: there is no autoplay and no page scroll hijacking', async ({ browser }) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.carousel as string);
  await loadAll(page);
  const strip = page.locator(viewport);
  const before = await strip.evaluate((el) => el.scrollLeft);
  // Deliberate wall-clock wait: the claim is that nothing moves by itself.
  await page.evaluate(() => new Promise((done) => setTimeout(done, 3000)));
  expect(await strip.evaluate((el) => el.scrollLeft)).toBe(before);
  await expect(page.locator('.swg-carousel__status')).toHaveText(`1 of ${COUNT}`);
  await context.close();
});

test('carousel: RTL mirrors the buttons and the direction of the strip', async ({ browser }) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.carouselBlock as string);
  await loadAll(page);
  await page.evaluate(() => {
    document.documentElement.dir = 'rtl';
  });
  const strip = page.locator(viewport);
  const prev = page.getByRole('button', { name: 'Previous image' });
  const next = page.getByRole('button', { name: 'Next image' });
  const prevBox = await prev.boundingBox();
  const nextBox = await next.boundingBox();
  expect((prevBox?.x ?? 0) > (nextBox?.x ?? 0), 'previous is at the right in RTL').toBe(true);
  const firstSlide = await page.locator('.swg-item').first().boundingBox();
  const stripBox = await strip.boundingBox();
  expect(
    Math.abs(
      (firstSlide?.x ?? 0) +
        (firstSlide?.width ?? 0) -
        ((stripBox?.x ?? 0) + (stripBox?.width ?? 0)),
    ),
    'the first slide starts at the inline start (right)',
  ).toBeLessThan(12);

  await next.click();
  await expect(page.locator('.swg-carousel__status')).toHaveText(new RegExp(`2.*${COUNT}`));
  expect(await strip.evaluate((el) => el.scrollLeft), 'scrolls towards the left').toBeLessThan(-50);
  await strip.focus();
  await page.keyboard.press('ArrowRight'); // previous in RTL
  await expect(page.locator('.swg-carousel__status')).toHaveText(new RegExp(`1.*${COUNT}`));
  await shot(page, 'carousel-rtl', '.swg-gallery');
  await context.close();
});

test('carousel: reduced motion switches the smooth scrolling off', async ({ browser }) => {
  const context = await visitor(browser, {
    viewport: { width: 1280, height: 900 },
    reducedMotion: 'reduce',
  });
  const page = await context.newPage();
  await page.goto(urls.carousel as string);
  await expect(page.locator(viewport)).toBeVisible();
  expect(await page.locator(viewport).evaluate((el) => getComputedStyle(el).scrollBehavior)).toBe(
    'auto',
  );
  const status = page.locator('.swg-carousel__status');
  await page.getByRole('button', { name: 'Next image' }).click();
  await expect(status).toHaveText(`2 of ${COUNT}`);
  await context.close();
});

test('carousel: the lightbox opens from a slide and navigates in DOM order', async ({
  browser,
}) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.carousel as string);
  await loadAll(page);
  const order = await hrefs(page);
  expect(order).toHaveLength(COUNT);

  await page.locator('a.swg-link').nth(1).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog).toContainText(new RegExp(`2\\s*(of|von)\\s*${COUNT}`));
  await page.keyboard.press('ArrowRight');
  await expect(dialog).toContainText(new RegExp(`3\\s*(of|von)\\s*${COUNT}`));
  await expect(dialog.locator('img').first()).toHaveAttribute(
    'src',
    new RegExp(fileStem(order[2] as string).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')),
  );
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(page.locator('a.swg-link').nth(1)).toBeFocused();
  await context.close();
});

test('carousel: the centred variant without buttons keeps the position text', async ({
  browser,
}) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await page.goto(urls.carouselCentred as string);
  await loadAll(page);
  await expect(page.locator('.swg-carousel__btn').first()).toBeHidden();
  await page.locator(viewport).focus();
  await page.keyboard.press('ArrowRight');
  await expect(page.locator('.swg-carousel__status')).toHaveText('2 of 5');
  const slide = await page.locator('.swg-item').nth(1).boundingBox();
  const stripBox = await page.locator(viewport).boundingBox();
  expect(
    Math.abs(
      (slide?.x ?? 0) + (slide?.width ?? 0) / 2 - ((stripBox?.x ?? 0) + (stripBox?.width ?? 0) / 2),
    ),
    'the slide is centred',
  ).toBeLessThan(6);
  await shot(page, 'carousel-centred', '.swg-gallery');
  await context.close();
});

for (const scheme of ['light', 'dark'] as const) {
  for (const key of ['carousel', 'focus', 'beside']) {
    test(`${key}: no serious axe violations (${scheme})`, async ({ browser }) => {
      const context = await visitor(browser, {
        viewport: { width: 1280, height: 900 },
        colorScheme: scheme,
      });
      const page = await context.newPage();
      await page.goto(urls[key] as string);
      await loadAll(page);
      expect(await seriousViolations(page, '.swg-gallery'), `${key} (${scheme})`).toEqual([]);
      await context.close();
    });
  }
}

for (const width of [1280, 768, 375]) {
  test(`carousel: no page overflow and screenshot at ${width}px`, async ({ browser }) => {
    const context = await visitor(browser, { viewport: { width, height: 900 } });
    const page = await context.newPage();
    await page.goto(urls.carousel as string);
    await loadAll(page);
    expect(await pageOverflow(page)).toBe(false);
    await expect(page.locator('.swg-carousel__btn').first()).toBeVisible();
    await shot(page, `carousel-${width}`);
    await context.close();
  });
}

// ---------------------------------------------------------------------------------------------------- focus

/** The panels that are displayed (exactly one in a working gallery). */
const visiblePanels = (page: Page, gallery = 0) =>
  page.locator('.swg-gallery').nth(gallery).locator('.swg-panel:visible');

test('focus: without JavaScript the thumbnails switch the stage through :target and the page does not jump', async ({
  browser,
}) => {
  const context = await visitor(browser, {
    javaScriptEnabled: false,
    viewport: { width: 1280, height: 1100 },
  });
  const page = await context.newPage();
  await page.goto(urls.focus as string);
  await page.waitForLoadState('load');

  await expect(visiblePanels(page)).toHaveCount(1);
  await expect(visiblePanels(page).locator('img')).toHaveAttribute('alt', 'Photo 1');
  await expect(page.locator('.swg-stage')).toHaveAttribute('role', 'region');
  await expect(page.locator('.swg-stage')).toHaveAttribute('aria-label', 'Selected image');
  await expect(page.locator('.swg-thumbs')).toHaveAttribute('aria-label', 'Thumbnails');

  await page.locator('.swg-gallery').scrollIntoViewIfNeeded();
  await page.mouse.wheel(0, 120);
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(0);
  const before = await page.evaluate(() => window.scrollY);
  await page.locator('a.swg-thumb').nth(2).click();
  await expect(visiblePanels(page)).toHaveCount(1);
  await expect(visiblePanels(page).locator('img')).toHaveAttribute('alt', 'Photo 3');
  expect(page.url()).toMatch(/#swg-\d+-\d+-s3$/);
  expect(Math.abs((await page.evaluate(() => window.scrollY)) - before), 'no jump').toBeLessThan(2);

  await page.locator('a.swg-thumb').nth(7).click();
  await expect(visiblePanels(page).locator('img')).toHaveAttribute('alt', 'Photo 8');
  await page.locator('a.swg-thumb').nth(0).click();
  await expect(visiblePanels(page).locator('img')).toHaveAttribute('alt', 'Photo 1');
  expect(await pageOverflow(page)).toBe(false);

  // Without CSS support the stage image still is a plain link to the full image.
  await expect(visiblePanels(page).locator('a.swg-link')).toHaveAttribute('href', /\.png$/);
  await context.close();
});

test('focus: with JavaScript switching is instant, keeps the URL and the scroll position', async ({
  browser,
}) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 1100 } });
  const page = await context.newPage();
  await page.goto(urls.focus as string);
  await loadAll(page);
  const start = page.url();
  await expect(page.locator('.swg-gallery')).toHaveClass(/swg-focus-active/);
  const thumbs = page.locator('a.swg-thumb');
  await expect(thumbs.nth(0)).toHaveAttribute('aria-current', 'true');

  await page.mouse.wheel(0, 120);
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(0);
  const before = await page.evaluate(() => window.scrollY);
  await thumbs.nth(3).click();
  await expect(visiblePanels(page)).toHaveCount(1);
  await expect(visiblePanels(page).locator('img')).toHaveAttribute('alt', 'Photo 4');
  await expect(thumbs.nth(3)).toHaveAttribute('aria-current', 'true');
  await expect(thumbs.nth(0)).not.toHaveAttribute('aria-current', 'true');
  expect(page.url(), 'no hash change').toBe(start);
  expect(
    Math.abs((await page.evaluate(() => window.scrollY)) - before),
    'no page scroll',
  ).toBeLessThan(2);
  await expect(page.locator('.swg-sr')).toHaveText(`4 of ${COUNT}`);
  await context.close();
});

test('focus: arrow keys, Home and End move between the thumbnails', async ({ browser }) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 1100 } });
  const page = await context.newPage();
  await page.goto(urls.focus as string);
  await loadAll(page);
  const thumbs = page.locator('a.swg-thumb');
  await thumbs.nth(0).focus();
  await page.keyboard.press('ArrowRight');
  await expect(thumbs.nth(1)).toBeFocused();
  await expect(thumbs.nth(1)).toHaveAttribute('aria-current', 'true');
  await expect(visiblePanels(page).locator('img')).toHaveAttribute('alt', 'Photo 2');
  await page.keyboard.press('ArrowLeft');
  await expect(thumbs.nth(0)).toBeFocused();
  await page.keyboard.press('End');
  await expect(thumbs.nth(COUNT - 1)).toBeFocused();
  await expect(visiblePanels(page).locator('img')).toHaveAttribute('alt', `Photo ${COUNT}`);
  await page.keyboard.press('Home');
  await expect(visiblePanels(page).locator('img')).toHaveAttribute('alt', 'Photo 1');
  await context.close();
});

test('focus: the stage opens the lightbox on the current image; every image is listed once in order', async ({
  browser,
}) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 1100 } });
  const page = await context.newPage();
  await page.goto(urls.focus as string);
  await loadAll(page);
  const order = await hrefs(page);
  expect(order, 'one lightbox link per image').toHaveLength(COUNT);
  expect(new Set(order).size).toBe(COUNT);
  await expect(page.locator('.swg-thumbs a.swg-link')).toHaveCount(0);

  await page.locator('a.swg-thumb').nth(3).click();
  await expect(page.getByRole('dialog'), 'a thumbnail never opens the lightbox').toBeHidden();
  await visiblePanels(page).locator('a.swg-link').click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog).toContainText(new RegExp(`4\\s*(of|von)\\s*${COUNT}`));
  await expect(dialog.locator('img').first()).toHaveAttribute(
    'src',
    new RegExp(fileStem(order[3] as string).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')),
  );
  await page.keyboard.press('ArrowRight');
  await expect(dialog).toContainText(new RegExp(`5\\s*(of|von)\\s*${COUNT}`));
  await page.keyboard.press('ArrowLeft');
  await page.keyboard.press('ArrowLeft');
  await expect(dialog).toContainText(new RegExp(`3\\s*(of|von)\\s*${COUNT}`));
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await context.close();
});

test('focus: thumbnails sit below in narrow containers and beside the stage in wide ones', async ({
  browser,
}) => {
  const wide = await visitor(browser, { viewport: { width: 1280, height: 1000 } });
  const wp = await wide.newPage();
  await wp.goto(urls.beside as string);
  await loadAll(wp);
  const stage = (await wp.locator('.swg-stage').boundingBox())!;
  const strip = (await wp.locator('.swg-thumbs').boundingBox())!;
  expect(strip.x + strip.width, 'thumbnails before the stage').toBeLessThanOrEqual(stage.x + 1);
  expect(strip.height, 'the column is as tall as the stage (it scrolls)').toBeLessThan(
    stage.height + 40,
  );
  await shot(wp, 'focus-beside-1280');
  await wide.close();

  const narrow = await visitor(browser, { viewport: { width: 375, height: 900 } });
  const np = await narrow.newPage();
  await np.goto(urls.beside as string);
  await loadAll(np);
  const nstage = (await np.locator('.swg-stage').boundingBox())!;
  const nstrip = (await np.locator('.swg-thumbs').boundingBox())!;
  expect(nstrip.y, 'thumbnails below the stage').toBeGreaterThanOrEqual(
    nstage.y + nstage.height - 1,
  );
  expect(await pageOverflow(np)).toBe(false);
  await narrow.close();
});

test('focus: two instances of one gallery have unique anchors and switch independently', async ({
  browser,
}) => {
  const context = await visitor(browser, {
    javaScriptEnabled: false,
    viewport: { width: 1280, height: 1100 },
  });
  const page = await context.newPage();
  await page.goto(urls.twice as string);
  const ids = await page.locator('.swg-anchor').evaluateAll((els) => els.map((el) => el.id));
  expect(ids).toHaveLength(COUNT * 2);
  expect(new Set(ids).size, 'ids are unique per page').toBe(COUNT * 2);

  await page.locator('.swg-gallery').nth(1).locator('a.swg-thumb').nth(4).click();
  await expect(visiblePanels(page, 1).locator('img')).toHaveAttribute('alt', 'Photo 5');
  await expect(visiblePanels(page, 0).locator('img')).toHaveAttribute('alt', 'Photo 1');
  await context.close();

  const js = await visitor(browser, { viewport: { width: 1280, height: 1100 } });
  const jsPage = await js.newPage();
  await jsPage.goto(urls.twice as string);
  await jsPage.locator('.swg-gallery').nth(0).locator('a.swg-thumb').nth(6).click();
  await expect(visiblePanels(jsPage, 0).locator('img')).toHaveAttribute('alt', 'Photo 7');
  await expect(visiblePanels(jsPage, 1).locator('img')).toHaveAttribute('alt', 'Photo 1');
  await js.close();
});

test('focus: reduced motion disables the transitions and the stage crossfade', async ({
  browser,
}) => {
  const context = await visitor(browser, {
    viewport: { width: 1280, height: 1100 },
    reducedMotion: 'reduce',
  });
  const page = await context.newPage();
  await page.goto(urls.focus as string);
  await loadAll(page);
  await page.locator('a.swg-thumb').nth(2).click();
  await expect(visiblePanels(page).locator('img')).toHaveAttribute('alt', 'Photo 3');
  const motion = await page.evaluate(() => ({
    panel: getComputedStyle(document.querySelector('.swg-panel.swg-current') as Element)
      .animationName,
    thumb: getComputedStyle(document.querySelector('.swg-thumb') as Element).transitionDuration,
  }));
  expect(motion.panel).toBe('none');
  expect(motion.thumb).toBe('0s');
  await context.close();

  const normal = await visitor(browser, { viewport: { width: 1280, height: 1100 } });
  const np = await normal.newPage();
  await np.goto(urls.focus as string);
  await np.locator('a.swg-thumb').nth(2).click();
  expect(
    await np.evaluate(
      () =>
        getComputedStyle(document.querySelector('.swg-panel.swg-current') as Element).animationName,
    ),
  ).toBe('swg-focus-in');
  await normal.close();
});

test('focus: the block renders the same layout and RTL mirrors it', async ({ browser }) => {
  const context = await visitor(browser, { viewport: { width: 1280, height: 1100 } });
  const page = await context.newPage();
  await page.goto(urls.focusBlock as string);
  await expect(page.locator('.swg-gallery.swg-layout-focus')).toHaveCount(1);
  await loadAll(page);
  await page.evaluate(() => {
    document.documentElement.dir = 'rtl';
  });
  const thumbs = page.locator('a.swg-thumb');
  const first = (await thumbs.nth(0).boundingBox())!;
  const second = (await thumbs.nth(1).boundingBox())!;
  expect(first.x, 'the first thumbnail is at the right in RTL').toBeGreaterThan(second.x);
  await thumbs.nth(0).focus();
  await page.keyboard.press('ArrowLeft'); // next in RTL
  await expect(visiblePanels(page).locator('img')).toHaveAttribute('alt', 'Photo 2');
  await shot(page, 'focus-rtl', '.swg-gallery');
  await context.close();
});

for (const width of [1280, 768, 375]) {
  test(`focus: no page overflow and screenshot at ${width}px`, async ({ browser }) => {
    const context = await visitor(browser, { viewport: { width, height: 1000 } });
    const page = await context.newPage();
    await page.goto(urls.focus as string);
    await loadAll(page);
    await page.locator('a.swg-thumb').nth(3).click();
    expect(await pageOverflow(page)).toBe(false);
    await shot(page, `focus-${width}`);
    await context.close();
  });
}

test('the editor generates the settings forms of carousel and focus from their schemas', async ({
  page,
}) => {
  for (const [id, labels] of [
    [urls.carouselId, ['Slide height (px)', 'Previous/next buttons', 'Snap position']],
    [urls.focusId, ['Stage ratio', 'Thumbnails', 'Thumbnail size (px)']],
  ] as Array<[string, string[]]>) {
    await page.goto(`${PLUGIN_PAGE}#/galleries/${id}`);
    const root = page.locator('#swg-admin-root');
    await expect(root.locator('.swg-shell')).toBeVisible();
    await root.locator('.swg-seg').getByText('Layout & settings', { exact: true }).click();
    const panel = root.locator('.swg-lsp');
    await expect(panel).toBeVisible();
    for (const label of labels) await expect(panel.getByText(label).first()).toBeVisible();
  }
});
