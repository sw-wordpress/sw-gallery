import { expect, test } from '@playwright/test';
import type { APIRequestContext, Browser } from '@playwright/test';
import { authFile } from '../support/env';
import type { Role } from '../support/env';
import { GalleryFixtures, tinyPng, uniquePrefix } from '../support/galleries';

// FU-16 (contributors may use readable images) and S-04 (owner handover needs edit_others_posts and a new owner who
// may write posts), over real HTTP with real role sessions.

test.describe.configure({ mode: 'serial' });

const prefix = uniquePrefix('contributor');

interface Session {
  fixtures: GalleryFixtures;
  request: APIRequestContext;
  restRoot: string;
  nonce: string;
  userId: number;
}

const join = (root: string, path: string): string => {
  const url = `${root}${path}`;
  const first = url.indexOf('?');
  return first === -1 ? url : url.slice(0, first + 1) + url.slice(first + 1).replace('?', '&');
};

async function session(browser: Browser, baseURL: string | undefined, role: Role) {
  const context = await browser.newContext({ storageState: authFile(role), baseURL });
  const page = await context.newPage();
  const fixtures = await GalleryFixtures.forPage(page);
  const bootstrap = await page.evaluate(() => {
    const value = (window as unknown as { swgAdmin: { restRoot: string; nonce: string } }).swgAdmin;
    return { restRoot: value.restRoot, nonce: value.nonce };
  });
  const me = await page.request.get(
    join(bootstrap.restRoot.replace('swg/v1', 'wp/v2'), 'users/me?context=edit'),
    { headers: { 'X-WP-Nonce': bootstrap.nonce } },
  );
  expect(me.status(), await me.text()).toBe(200);
  const { id } = (await me.json()) as { id: number };
  return { fixtures, request: page.request, ...bootstrap, userId: id } satisfies Session;
}

let admin: Session;
let contributor: Session;
let author: Session;
let editor: Session;
let subscriberId = 0;
let imageId = 0;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await session(browser, baseURL, 'administrator');
  contributor = await session(browser, baseURL, 'contributor');
  author = await session(browser, baseURL, 'author');
  editor = await session(browser, baseURL, 'editor');
  const users = await admin.request.get(
    join(admin.restRoot.replace('swg/v1', 'wp/v2'), 'users?roles=subscriber&context=edit'),
    { headers: { 'X-WP-Nonce': admin.nonce } },
  );
  expect(users.status(), await users.text()).toBe(200);
  const [first] = (await users.json()) as Array<{ id: number }>;
  expect(first, 'the E2E environment has a subscriber').toBeDefined();
  subscriberId = first?.id ?? 0;
  imageId = (await admin.fixtures.uploadImage(`${prefix} image`, tinyPng([30, 140, 90], 64))).id;
});

test.afterAll(async () => {
  if (!admin) return;
  await admin.fixtures.purge(prefix);
  await admin.fixtures.purgeMedia(prefix);
});

async function patch(who: Session, id: number, revision: number, data: Record<string, unknown>) {
  return who.request.patch(join(who.restRoot, `galleries/${id}`), {
    headers: { 'X-WP-Nonce': who.nonce },
    data: { ...data, expectedRevision: revision },
  });
}

test('a contributor assigns a readable image by id, sees it as available and in the preview', async () => {
  const gallery = await contributor.fixtures.create({ title: `${prefix} contributor gallery` });
  const assign = await contributor.request.post(
    join(contributor.restRoot, `galleries/${gallery.id}/items`),
    {
      headers: { 'X-WP-Nonce': contributor.nonce },
      data: { attachmentIds: [imageId], expectedRevision: gallery.revision },
    },
  );
  expect(assign.status(), await assign.text()).toBe(200);
  const body = (await assign.json()) as { added: number[]; missing: number[] };
  expect(body.added).toEqual([imageId]);
  expect(body.missing).toEqual([]);

  const items = await contributor.request.get(
    join(contributor.restRoot, `galleries/${gallery.id}/items`),
    {
      headers: { 'X-WP-Nonce': contributor.nonce },
    },
  );
  const list = (await items.json()) as Array<{ missing: boolean }>;
  expect(list.map((item) => item.missing)).toEqual([false]);

  const preview = await contributor.request.post(
    join(contributor.restRoot, `galleries/${gallery.id}/preview`),
    {
      headers: { 'X-WP-Nonce': contributor.nonce },
      data: {},
    },
  );
  expect(preview.status(), await preview.text()).toBe(200);
  expect(((await preview.json()) as { html: string }).html).toContain('<img');
});

test('a contributor still cannot upload, and cannot browse the Media Library endpoint', async () => {
  const upload = await contributor.request.post(join(contributor.restRoot, 'media'), {
    headers: { 'X-WP-Nonce': contributor.nonce },
    multipart: { file: { name: 'x.png', mimeType: 'image/png', buffer: tinyPng([1, 2, 3], 16) } },
  });
  expect(upload.status()).toBe(403);
  const list = await contributor.request.get(join(contributor.restRoot, 'media'), {
    headers: { 'X-WP-Nonce': contributor.nonce },
  });
  expect(list.status()).toBe(403);
});

test('only users with edit_others_posts may hand a gallery over, and only to users who may write posts', async () => {
  const own = await author.fixtures.create({ title: `${prefix} author gallery` });
  const denied = await patch(author, own.id, own.revision, { ownerId: editor.userId });
  expect(denied.status()).toBe(403);
  const same = await patch(author, own.id, own.revision, {
    ownerId: author.userId,
    title: `${prefix} same owner`,
  });
  expect(same.status(), await same.text()).toBe(200);

  const detail = await author.fixtures.detail(own.id);
  const toSubscriber = await patch(editor, own.id, detail.revision, { ownerId: subscriberId });
  expect(toSubscriber.status()).toBe(422);
  expect(((await toSubscriber.json()) as { code: string }).code).toBe('swg_invalid_owner');
  const toNobody = await patch(editor, own.id, detail.revision, { ownerId: 99999999 });
  expect(toNobody.status()).toBe(422);

  const handed = await patch(editor, own.id, detail.revision, { ownerId: contributor.userId });
  expect(handed.status(), await handed.text()).toBe(200);
  expect(((await handed.json()) as { ownerId: number }).ownerId).toBe(contributor.userId);
});
