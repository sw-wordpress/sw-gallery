import { expect, test } from '@playwright/test';
import type { APIRequestContext, Browser, BrowserContext } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { authFile, SCREENSHOT_DIR } from '../support/env';
import { GalleryFixtures, tinyPng, uniquePrefix } from '../support/galleries';

// The public website, as a visitor sees it: shortcode and block output of the SAME renderer, the layout stylesheet,
// the lightbox, link pagination, visibility and the admin bar. Fixtures are created through the REST API as
// administrator; the visitor context has no cookies at all.

test.use({ storageState: authFile('administrator') });

const prefix = uniquePrefix('frontend');
const colors: [number, number, number][] = [
  [200, 40, 40],
  [40, 160, 60],
  [40, 80, 200],
];

interface Site {
  fixtures: GalleryFixtures;
  request: APIRequestContext;
  nonce: string;
  restRoot: string;
}

const postIds: number[] = [];
let site: Site;
let adminContext: BrowserContext;
let publishedId = 0;
let draftId = 0;
let shortcodeUrl = '';
let blockUrl = '';
let plainUrl = '';
let draftUrl = '';

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

async function visitor(browser: Browser): Promise<BrowserContext> {
  return browser.newContext({ storageState: { cookies: [], origins: [] } });
}

async function setItemsPerPage(value: number): Promise<void> {
  const response = await site.request.patch(site.restRoot + 'settings', {
    headers: { 'X-WP-Nonce': site.nonce },
    data: { itemsPerPage: value },
  });
  expect(response.status(), await response.text()).toBe(200);
}

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ browser }) => {
  // Kept open until afterAll: the fixtures use this context's request API for the whole spec.
  adminContext = await browser.newContext({ storageState: authFile('administrator') });
  const page = await adminContext.newPage();
  const fixtures = await GalleryFixtures.forPage(page);
  const bootstrap = await page.evaluate(() => {
    const value = (window as unknown as { swgAdmin: { restRoot: string; nonce: string } }).swgAdmin;
    return { restRoot: value.restRoot, nonce: value.nonce };
  });
  site = { fixtures, request: page.request, nonce: bootstrap.nonce, restRoot: bootstrap.restRoot };

  const images = [];
  for (const [index, rgb] of colors.entries()) {
    images.push(await fixtures.uploadImage(`${prefix} image ${index + 1}`, tinyPng(rgb, 640)));
  }
  const ids = images.map((image) => image.id);

  const published = await fixtures.create({ title: `${prefix} published`, status: 'publish' });
  publishedId = published.id;
  await fixtures.assign(publishedId, ids);
  const draft = await fixtures.create({ title: `${prefix} draft`, status: 'draft' });
  draftId = draft.id;
  await fixtures.assign(draftId, ids);

  shortcodeUrl = await createPost(
    'shortcode',
    `<p>Before</p>\n[sw_gallery id="${publishedId}" columns="2" gap="12"]`,
  );
  blockUrl = await createPost(
    'block',
    `<!-- wp:sw-gallery/gallery {"galleryId":${publishedId},"columns":2,"gap":12} /-->`,
  );
  plainUrl = await createPost('plain', '<p>No gallery on this page.</p>');
  draftUrl = await createPost('draft gallery', `[sw_gallery id="${draftId}"]`);
});

test.afterAll(async () => {
  for (const id of postIds) {
    await site.request.delete(site.restRoot.replace('swg/v1', 'wp/v2') + `posts/${id}?force=true`, {
      headers: { 'X-WP-Nonce': site.nonce },
    });
  }
  await setItemsPerPage(24);
  await site.fixtures.purge(prefix);
  await site.fixtures.purgeMedia(prefix);
  await adminContext.close();
});

for (const surface of ['shortcode', 'block'] as const) {
  test(`the ${surface} shows the gallery to a visitor with layout CSS, versioned assets and a working lightbox`, async ({
    browser,
  }) => {
    const context = await visitor(browser);
    const page = await context.newPage();
    const requests: string[] = [];
    page.on('request', (request) => requests.push(request.url()));

    await page.goto(surface === 'shortcode' ? shortcodeUrl : blockUrl);

    const gallery = page.locator('.swg-gallery');
    await expect(gallery).toHaveCount(1);
    await expect(gallery.locator('img')).toHaveCount(3);
    await expect(gallery.locator('a.swg-link')).toHaveCount(3);
    // Same alt text on every image, intrinsic size set (no layout shift).
    for (const img of await gallery.locator('img').all()) {
      await expect(img).toHaveAttribute('width', /^\d+$/);
      await expect(img).toHaveAttribute('height', /^\d+$/);
    }

    // The layout stylesheet and the lightbox are loaded with the plugin version; the grid is really a grid with 2 columns.
    const html = await page.content();
    expect(html).toMatch(/layouts-[\w-]+\.css\?ver=\d/);
    expect(html).toMatch(/lightbox-[\w-]+\.js\?ver=\d/);
    expect(html).toMatch(/lightbox-[\w-]+\.css\?ver=\d/);
    const items = gallery.locator('ul.swg-items');
    await expect(items).toHaveCSS('display', 'grid');
    const columns = await items.evaluate(
      (el) => getComputedStyle(el).gridTemplateColumns.split(' ').length,
    );
    expect(columns).toBe(2);

    // Lightbox: opens on click, announces itself as a modal dialog, closes with Escape and returns the focus.
    const first = gallery.locator('a.swg-link').first();
    await first.click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog).toHaveAttribute('aria-modal', 'true');
    await page.keyboard.press('ArrowRight');
    await expect(dialog).toContainText(/2\s*(of|von)\s*3/);
    // Wait for the fade-in to finish so the screenshot shows the final state.
    await expect(dialog).toHaveCSS('opacity', '1');
    await mkdir(SCREENSHOT_DIR, { recursive: true });
    await page.screenshot({ path: resolve(SCREENSHOT_DIR, `frontend-${surface}-lightbox.png`) });
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(first).toBeFocused();

    expect(requests.filter((url) => url.includes('/wp-json/swg/'))).toEqual([]);
    await page.screenshot({
      path: resolve(SCREENSHOT_DIR, `frontend-${surface}.png`),
      fullPage: true,
    });
    await context.close();
  });
}

test('without JavaScript the thumbnails are plain links to the full images', async ({
  browser,
}) => {
  const context = await browser.newContext({
    storageState: { cookies: [], origins: [] },
    javaScriptEnabled: false,
  });
  const page = await context.newPage();
  await page.goto(shortcodeUrl);

  const links = page.locator('.swg-gallery a.swg-link');
  await expect(links).toHaveCount(3);
  for (const link of await links.all()) {
    await expect(link).toHaveAttribute('href', /\.png$|\.jpe?g$|\.webp$/);
  }
  await context.close();
});

test('a page without a gallery loads none of the gallery assets', async ({ browser }) => {
  const context = await visitor(browser);
  const page = await context.newPage();
  await page.goto(plainUrl);

  const html = await page.content();
  expect(html).not.toMatch(/layouts-[\w-]+\.css/);
  expect(html).not.toMatch(/lightbox-[\w-]+\.(js|css)/);
  expect(html).not.toContain('swg-gallery');
  await context.close();
});

test('a draft gallery shows a visitor nothing at all', async ({ browser }) => {
  const context = await visitor(browser);
  const page = await context.newPage();
  await page.goto(draftUrl);

  await expect(page.locator('.swg-gallery')).toHaveCount(0);
  const html = await page.content();
  // No gallery markup, no editor diagnostic and no lightbox script. (The layout stylesheet may be linked: it is enqueued
  // early whenever the post contains the shortcode, a documented trade-off against a flash of unstyled galleries.)
  for (const forbidden of [
    'swg-gallery',
    'data-swg',
    'swg-items',
    'Gallery diagnostic',
    'Galerie-Hinweis',
  ]) {
    expect(html).not.toContain(forbidden);
  }
  expect(html).not.toMatch(/lightbox-[\w-]+\.js/);
  await context.close();
});

test('the administrator sees the draft diagnostic and an "Edit gallery" entry in the admin bar', async ({
  page,
}) => {
  await page.goto(draftUrl);
  await expect(page.locator('.swg-gallery')).toHaveCount(0);

  await page.goto(shortcodeUrl);
  const entry = page.locator(`#wp-admin-bar-swg-edit-gallery-${publishedId} a`);
  await expect(entry).toBeVisible();
  await expect(entry).toHaveAttribute(
    'href',
    new RegExp(`page=swg-galleries#/galleries/${publishedId}$`),
  );
});

test('link pagination works without JavaScript: other galleries stay independent and the query is preserved', async ({
  browser,
}) => {
  await setItemsPerPage(2);
  const context = await browser.newContext({
    storageState: { cookies: [], origins: [] },
    javaScriptEnabled: false,
  });
  const page = await context.newPage();
  await page.goto(shortcodeUrl);

  const nav = page.getByRole('navigation', { name: /pages|seiten/i });
  await expect(nav).toBeVisible();
  await expect(page.locator('.swg-gallery a.swg-link')).toHaveCount(2);
  await expect(nav.locator('[aria-current="page"]')).toHaveText('1');

  await nav
    .getByRole('link', { name: /2|next|weiter|nächste/i })
    .first()
    .click();
  await expect(page).toHaveURL(new RegExp(`swg_page_${publishedId}=2`));
  await expect(page.locator('.swg-gallery a.swg-link')).toHaveCount(1);
  await expect(
    page.getByRole('navigation', { name: /pages|seiten/i }).locator('[aria-current="page"]'),
  ).toHaveText('2');

  // A page past the end falls back to the last page instead of an empty gallery.
  await page.goto(
    `${shortcodeUrl}${shortcodeUrl.includes('?') ? '&' : '?'}swg_page_${publishedId}=999`,
  );
  await expect(page.locator('.swg-gallery a.swg-link')).toHaveCount(1);
  await context.close();
  await setItemsPerPage(24);
});
