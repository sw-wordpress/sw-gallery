import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { authFile, PLUGIN_PAGE } from '../support/env';
import { GalleryFixtures, uniquePrefix } from '../support/galleries';
import type { GalleryRecord } from '../support/galleries';

/**
 * Long-lived admin sessions (M2-17): an expired REST nonce is refreshed transparently exactly once, a gone login
 * session shows the "session expired" banner while the editor keeps its unsaved changes.
 */
const PREFIX = uniquePrefix('session');
test.use({ storageState: authFile('administrator') });

let admin: GalleryFixtures;
let gallery: GalleryRecord;

test.beforeAll(async ({ browser, baseURL }) => {
  const context = await browser.newContext({ storageState: authFile('administrator'), baseURL });
  admin = await GalleryFixtures.forPage(await context.newPage());
  gallery = await admin.create({ title: `${PREFIX} nonce` });
});

test.afterAll(async () => {
  await admin.purge(PREFIX);
});

const root = (page: Page): Locator => page.locator('#swg-admin-root');
const title = (page: Page): Locator => root(page).locator('.swg-ed__title');
const details = (page: Page): Locator => root(page).locator('.swg-gdp');
const state = (page: Page): Locator => root(page).locator('.swg-ed__state');
const save = (page: Page): Locator => root(page).getByRole('button', { name: 'Save', exact: true });

/** Serves the plugin page with a nonce WordPress rejects (what a 12-24 h old tab holds). The real nonce stays valid. */
async function withExpiredNonce(page: Page): Promise<void> {
  await page.route(/page=swg-galleries/, async (route) => {
    if (route.request().resourceType() !== 'document') {
      await route.continue();
      return;
    }
    const response = await route.fetch();
    // Only the app's own bootstrap: other inline scripts of the page carry nonces too.
    const html = (await response.text()).replace(
      /(window\.swgAdmin = \{[^;]*?"nonce":")[A-Za-z0-9]+(")/,
      '$10badnonce0$2',
    );
    await route.fulfill({ response, body: html });
  });
}

test('an expired nonce is refreshed once, transparently, and writes work afterwards', async ({
  page,
}) => {
  await withExpiredNonce(page);
  const refreshes: string[] = [];
  const rejected: string[] = [];
  page.on('request', (request) => {
    if (request.url().includes('action=rest-nonce')) refreshes.push(request.url());
  });
  page.on('response', (response) => {
    if (response.status() === 403 && response.url().includes('swg')) rejected.push(response.url());
  });

  await page.goto(`${PLUGIN_PAGE}#/galleries/${gallery.id}`);
  // The editor's first reads fail on the bad nonce, are replayed after one refresh and succeed.
  await expect(title(page)).toHaveText(gallery.title);
  await expect(state(page)).toBeVisible();
  expect(rejected.length).toBeGreaterThan(0);
  expect(refreshes).toHaveLength(1);
  await expect(root(page).locator('.swg-session')).toHaveCount(0);

  // A write with the refreshed nonce goes through without another refresh.
  await details(page).getByLabel('Title').fill(`${gallery.title} saved`);
  await save(page).click();
  await expect(state(page)).toContainText('All changes saved');
  expect(refreshes).toHaveLength(1);
  expect((await admin.detail(gallery.id)).title).toBe(`${gallery.title} saved`);
});

test('a gone session shows the session-expired state and keeps the staged editor changes', async ({
  page,
  context,
}) => {
  const own = await admin.create({ title: `${PREFIX} logout` });
  await page.goto(`${PLUGIN_PAGE}#/galleries/${own.id}`);
  await expect(title(page)).toHaveText(own.title);
  await details(page).getByLabel('Title').fill(`${own.title} unsaved`);

  await context.clearCookies(); // the session ends (logout in another tab, expiry)
  await save(page).click();

  const banner = root(page).locator('.swg-session');
  await expect(banner).toBeVisible();
  await expect(banner.getByRole('link', { name: 'Log in again' })).toHaveAttribute(
    'href',
    /wp-login\.php\?.*redirect_to=/,
  );
  await expect(banner.getByRole('link', { name: 'Log in again' })).toHaveAttribute(
    'target',
    '_blank',
  );
  // The editor stays mounted: nothing the user typed is lost and the save stays available.
  await expect(details(page).getByLabel('Title')).toHaveValue(`${own.title} unsaved`);
  await expect(save(page)).toBeEnabled();
  // ... and the unsaved-changes guard still works.
  await root(page).getByRole('link', { name: 'Galleries', exact: true }).first().click();
  const dialog = page.getByRole('alertdialog');
  await expect(dialog.getByRole('heading', { name: 'Leave without saving?' })).toBeVisible();
  await dialog.getByRole('button', { name: 'Keep editing' }).click();
  await expect(banner.getByText('Your session has expired')).toBeVisible();

  // Still logged out: "continue" says so and keeps the banner.
  await banner.getByRole('button', { name: 'I have logged in, continue' }).click();
  await expect(banner.getByText('You are still logged out.')).toBeVisible();
});
