import { expect, test } from '@playwright/test';
import type { APIRequestContext, BrowserContext } from '@playwright/test';
import { authFile, PLUGIN_PAGE, requireEnv } from '../support/env';
import { GalleryFixtures, tinyPng, uniquePrefix } from '../support/galleries';
import { monitorPage } from '../support/monitor';

// Smoke test of the RELEASE ZIP as installed by `wp plugin install` into a fresh WordPress (no source tree mounted):
// admin app, shortcode + lightbox on the website, block editor script, and no failing plugin request anywhere.
// PHP notices are checked by bin/test-zip-install.sh (debug.log of the container) after this run.

test.use({ storageState: authFile('administrator') });

const prefix = uniquePrefix('zip');
const PLUGIN_ASSETS = '/wp-content/plugins/sw-gallery/';

let adminContext: BrowserContext;
let request: APIRequestContext;
let nonce = '';
let restRoot = '';
let fixtures: GalleryFixtures;
let galleryId = 0;
let postId = 0;
let postUrl = '';

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ browser }) => {
  adminContext = await browser.newContext({ storageState: authFile('administrator') });
  const page = await adminContext.newPage();
  fixtures = await GalleryFixtures.forPage(page);
  const bootstrap = await page.evaluate(() => {
    const value = (window as unknown as { swgAdmin: { restRoot: string; nonce: string } }).swgAdmin;
    return { restRoot: value.restRoot, nonce: value.nonce };
  });
  request = page.request;
  nonce = bootstrap.nonce;
  restRoot = bootstrap.restRoot;
});

test.afterAll(async () => {
  if (postId !== 0) {
    await request.delete(restRoot.replace('swg/v1', 'wp/v2') + `posts/${postId}?force=true`, {
      headers: { 'X-WP-Nonce': nonce },
    });
  }
  await fixtures.purge(prefix);
  await fixtures.purgeMedia(prefix);
  await adminContext.close();
});

test('the installed plugin is active in the installed version and its admin app mounts', async ({
  page,
}) => {
  const monitor = monitorPage(page);
  await page.goto('/wp-admin/plugins.php');
  const row = page.locator('tr[data-slug="sw-gallery"]');
  await expect(row).toHaveClass(/active/);
  await expect(row).toContainText(`Version ${requireEnv('SWG_E2E_ZIP_VERSION')}`);

  await page.goto(PLUGIN_PAGE);
  await expect(page.locator('#swg-admin-root .swg-shell')).toBeVisible();
  const urls = await page.evaluate(() => [
    ...Array.from(document.querySelectorAll<HTMLScriptElement>('script[src*="sw-gallery"]')).map(
      (el) => el.src,
    ),
    ...Array.from(
      document.querySelectorAll<HTMLLinkElement>('link[rel="stylesheet"][href*="sw-gallery"]'),
    ).map((el) => el.href),
  ]);
  expect(urls.length).toBeGreaterThanOrEqual(2);
  for (const url of urls) {
    expect(new URL(url).searchParams.get('ver'), url).toBe(requireEnv('SWG_E2E_ZIP_VERSION'));
  }
  expect(monitor.failedRequests, 'failing requests').toEqual([]);
  expect(monitor.problems, 'console problems').toEqual([]);
});

test('a gallery can be created and is rendered by the shortcode with a working lightbox', async ({
  browser,
}) => {
  const images = [];
  for (const [index, rgb] of (
    [
      [200, 40, 40],
      [40, 80, 200],
    ] as [number, number, number][]
  ).entries()) {
    images.push(await fixtures.uploadImage(`${prefix} image ${index + 1}`, tinyPng(rgb, 640)));
  }
  const gallery = await fixtures.create({ title: `${prefix} gallery`, status: 'publish' });
  galleryId = gallery.id;
  await fixtures.assign(
    galleryId,
    images.map((image) => image.id),
  );

  const response = await request.post(restRoot.replace('swg/v1', 'wp/v2') + 'posts', {
    headers: { 'X-WP-Nonce': nonce },
    data: {
      title: `${prefix} post`,
      status: 'publish',
      content: `[sw_gallery id="${galleryId}"]`,
    },
  });
  expect(response.status(), await response.text()).toBe(201);
  const post = (await response.json()) as { id: number; link: string };
  postId = post.id;
  postUrl = post.link;

  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  const page = await context.newPage();
  const monitor = monitorPage(page);
  await page.goto(postUrl);
  const gallerySection = page.locator('.swg-gallery');
  await expect(gallerySection).toHaveCount(1);
  await expect(gallerySection.locator('img')).toHaveCount(2);
  const html = await page.content();
  for (const pattern of [/layouts-[\w-]+\.css\?ver=/, /lightbox-[\w-]+\.js\?ver=/]) {
    expect(html).toMatch(pattern);
  }
  await gallerySection.locator('a.swg-link').first().click();
  await expect(page.getByRole('dialog')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('dialog')).toBeHidden();

  const pluginRequests = monitor.requests.filter((url) => url.includes(PLUGIN_ASSETS));
  expect(pluginRequests.length, 'plugin assets requested').toBeGreaterThanOrEqual(3);
  expect(monitor.failedRequests, 'failing requests').toEqual([]);
  expect(monitor.problems, 'console problems').toEqual([]);
  await context.close();
});

test('the block editor loads the block script from the ZIP and registers the block', async ({
  page,
}) => {
  const monitor = monitorPage(page);
  await page.goto('/wp-admin/post-new.php');
  await page.waitForFunction(
    () =>
      (
        window as unknown as {
          wp?: { blocks?: { getBlockType?: (name: string) => unknown } };
        }
      ).wp?.blocks?.getBlockType?.('sw-gallery/gallery') !== undefined,
  );
  expect(
    monitor.requests.some((url) => url.includes(`${PLUGIN_ASSETS}assets/blocks/build/index.js`)),
    'block editor script requested',
  ).toBe(true);
  expect(
    monitor.failedRequests.filter((entry) => entry.includes(PLUGIN_ASSETS)),
    'failing plugin requests',
  ).toEqual([]);
});
