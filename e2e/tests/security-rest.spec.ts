import { expect, test } from '@playwright/test';
import type { APIRequestContext, BrowserContext } from '@playwright/test';
import { authFile } from '../support/env';
import { GalleryFixtures, tinyPng, uniquePrefix } from '../support/galleries';

// Security review (M5-04 b, i): the REST boundary over real HTTP and the visitor view of non-public galleries.
// Fixtures are created as administrator; "cookie only" requests carry the login cookie of that session but no nonce.

test.use({ storageState: authFile('administrator') });
test.describe.configure({ mode: 'serial' });

const prefix = uniquePrefix('security');
const wp = (root: string): string => root.replace('swg/v1', 'wp/v2');
/** Plugin REST URL: with plain permalinks the root already carries a query string (`?rest_route=`), so extra arguments use `&`. */
const join = (root: string, path: string): string => {
  const url = `${root}${path}`;
  const first = url.indexOf('?');
  return first === -1 ? url : url.slice(0, first + 1) + url.slice(first + 1).replace('?', '&');
};
const rest = (path: string): string => join(restRoot, path);
const core = (path: string): string => join(wp(restRoot), path);

let adminContext: BrowserContext;
let anonymous: BrowserContext;
let fixtures: GalleryFixtures;
let cookieOnly: APIRequestContext;
let restRoot = '';
let nonce = '';
let galleryId = 0;
const postIds: number[] = [];
const hiddenUrls: Record<string, string> = {};
let imageUrlPart = '';

test.beforeAll(async ({ browser }) => {
  adminContext = await browser.newContext({ storageState: authFile('administrator') });
  anonymous = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  const page = await adminContext.newPage();
  fixtures = await GalleryFixtures.forPage(page);
  const bootstrap = await page.evaluate(() => {
    const value = (window as unknown as { swgAdmin: { restRoot: string; nonce: string } }).swgAdmin;
    return { restRoot: value.restRoot, nonce: value.nonce };
  });
  restRoot = bootstrap.restRoot;
  nonce = bootstrap.nonce;
  cookieOnly = adminContext.request;

  const image = await fixtures.uploadImage(`${prefix} image`, tinyPng([20, 120, 200], 320));
  const gallery = await fixtures.create({ title: `${prefix} fixture`, status: 'draft' });
  galleryId = gallery.id;
  await fixtures.assign(galleryId, [image.id]);
  const media = await cookieOnly.get(rest(`media?search=${encodeURIComponent(prefix)}`), {
    headers: { 'X-WP-Nonce': nonce },
  });
  const [first] = (await media.json()) as Array<{ fullUrl: string }>;
  imageUrlPart = new URL(first?.fullUrl ?? 'http://invalid/').pathname.split('/').pop() ?? '';
  expect(imageUrlPart).not.toBe('');

  for (const status of ['draft', 'pending', 'private'] as const) {
    const hidden = await fixtures.create({ title: `${prefix} ${status}`, status });
    await fixtures.assign(hidden.id, [image.id]);
    const post = await cookieOnly.post(core('posts'), {
      headers: { 'X-WP-Nonce': nonce },
      data: {
        title: `${prefix} page ${status}`,
        status: 'publish',
        content: `<p>Visible text</p>[sw_gallery id="${hidden.id}"]`,
      },
    });
    expect(post.status(), await post.text()).toBe(201);
    const body = (await post.json()) as { id: number; link: string };
    postIds.push(body.id);
    hiddenUrls[status] = body.link;
  }
});

test.afterAll(async () => {
  // A failed beforeAll leaves nothing to clean up (the retry runs it again).
  if (!fixtures) return;
  for (const id of postIds) {
    await cookieOnly.delete(core(`posts/${id}?force=true`), {
      headers: { 'X-WP-Nonce': nonce },
    });
  }
  await fixtures.purge(prefix);
  await fixtures.purgeMedia(prefix);
  await adminContext.close();
  await anonymous.close();
});

test('every plugin route refuses a request without any credentials (no unauthenticated route)', async () => {
  const routes = [
    ['GET', 'galleries'],
    ['GET', `galleries/${galleryId}`],
    ['GET', `galleries/${galleryId}/items`],
    ['GET', 'layouts'],
    ['GET', 'media'],
    ['GET', 'settings'],
    ['GET', 'status'],
    ['POST', 'galleries'],
    ['POST', `galleries/${galleryId}/preview`],
    ['POST', 'media'],
    ['PATCH', 'settings'],
    ['PATCH', `galleries/${galleryId}`],
    ['DELETE', `galleries/${galleryId}?expectedRevision=1&force=true`],
  ] as const;
  for (const [method, path] of routes) {
    const response = await anonymous.request.fetch(rest(`${path}`), {
      method,
      data: method === 'GET' ? undefined : { title: 'x', expectedRevision: 1 },
    });
    expect(response.status(), `${method} ${path}`).toBe(401);
    expect(response.headers()['content-type']).toContain('application/json');
    const text = await response.text();
    expect(text, `${method} ${path}`).not.toMatch(/\/var\/www|wp_posts|Stack trace|SELECT /);
  }
  // The `rest_route` query form is the same pipeline.
  const viaQuery = await anonymous.request.get(`/?rest_route=/swg/v1/status`);
  expect(viaQuery.status()).toBe(401);
});

test('a login cookie without a nonce is treated as anonymous', async () => {
  for (const path of ['galleries', 'status', 'settings', `galleries/${galleryId}`]) {
    const response = await cookieOnly.get(rest(`${path}`));
    expect(response.status(), path).toBe(401);
  }
  const created = await cookieOnly.post(rest(`galleries`), {
    data: { title: `${prefix} csrf` },
  });
  expect(created.status()).toBe(401);
});

test('a forged nonce is refused and a valid one works', async () => {
  for (const bad of ['garbage', '0', 'abcdef1234']) {
    const response = await cookieOnly.get(rest(`status`), { headers: { 'X-WP-Nonce': bad } });
    expect(response.status(), bad).toBe(403);
    expect(((await response.json()) as { code: string }).code).toBe('rest_cookie_invalid_nonce');
  }
  const ok = await cookieOnly.get(rest(`status`), { headers: { 'X-WP-Nonce': nonce } });
  expect(ok.status()).toBe(200);
});

test('method override and GET cannot change state without a nonce', async () => {
  const detail = await fixtures.detail(galleryId);
  // A cross-site <img>/<form> can send a GET with `_method=DELETE` and the victim's cookies, but never the nonce.
  const viaQuery = await cookieOnly.get(
    rest(`galleries/${galleryId}?_method=DELETE&expectedRevision=${detail.revision}&force=true`),
  );
  expect(viaQuery.status()).toBe(401);
  const viaHeader = await cookieOnly.fetch(rest(`galleries/${galleryId}`), {
    method: 'POST',
    headers: { 'X-HTTP-Method-Override': 'DELETE' },
    data: { expectedRevision: detail.revision, force: true },
  });
  expect(viaHeader.status()).toBe(401);
  const after = await fixtures.detail(galleryId);
  expect(after.revision).toBe(detail.revision);
  expect(after.status).toBe('draft');
});

test('a wrong HTTP method is a 404 route error, not a handler', async () => {
  for (const [method, path] of [
    ['PUT', `galleries/${galleryId}`],
    ['GET', `galleries/${galleryId}/restore`],
    ['DELETE', 'status'],
  ] as const) {
    const response = await cookieOnly.fetch(rest(`${path}`), {
      method,
      headers: { 'X-WP-Nonce': nonce },
    });
    expect(response.status(), `${method} ${path}`).toBe(404);
  }
});

test('private REST answers are not cacheable by shared caches', async () => {
  const response = await cookieOnly.get(rest(`galleries`), {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect(response.status()).toBe(200);
  const cacheControl = (response.headers()['cache-control'] ?? '').toLowerCase();
  expect(cacheControl).toMatch(/no-cache|no-store|private/);
  expect(cacheControl).not.toMatch(/public|max-age=[1-9]/);
});

for (const status of ['draft', 'pending', 'private'] as const) {
  test(`a ${status} gallery shows a visitor nothing: page, paging argument and feeds`, async () => {
    const page = await anonymous.newPage();
    const urls = [
      hiddenUrls[status] ?? '',
      `${hiddenUrls[status] ?? ''}${(hiddenUrls[status] ?? '').includes('?') ? '&' : '?'}swg_page_1=2`,
    ];
    for (const url of urls) {
      const response = await page.goto(url);
      expect(response?.status()).toBe(200);
      await expect(page.getByText('Visible text')).toBeVisible();
      const html = await page.content();
      expect(html, url).not.toContain('swg-gallery');
      expect(html, url).not.toContain(imageUrlPart);
    }
    const feed = await anonymous.request.get('/?feed=rss2');
    expect(feed.status()).toBe(200);
    const feedXml = await feed.text();
    expect(feedXml).not.toContain(imageUrlPart);
    expect(feedXml).not.toContain('swg-gallery');
    await page.close();
  });
}
