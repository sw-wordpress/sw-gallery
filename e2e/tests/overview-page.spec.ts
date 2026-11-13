import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { authFile, PLUGIN_PAGE } from '../support/env';
import { GalleryFixtures, uniquePrefix } from '../support/galleries';
import type { GalleryRecord } from '../support/galleries';
import { monitorPage } from '../support/monitor';
import { preferTheme, shot } from '../support/screenshots';

/**
 * Overview page (M2-34): counts, recently modified galleries, quick actions and (managers) the health line, all from
 * existing endpoints. Galleries are created per file with a unique prefix and purged again.
 */
test.describe.configure({ mode: 'default' });
const PREFIX = uniquePrefix('overview');
let admin: GalleryFixtures;
let created: GalleryRecord[] = [];

const root = (page: Page): Locator => page.locator('#swg-admin-root');
const tile = (page: Page, label: string): Locator =>
  root(page).locator('.swg-stat', { has: page.getByText(label, { exact: true }) });

async function openOverview(page: Page): Promise<void> {
  await page.goto(PLUGIN_PAGE);
  await expect(root(page).locator('.swg-overview .swg-stat').first()).toBeVisible();
}

test.beforeAll(async ({ browser, baseURL }) => {
  const context = await browser.newContext({ storageState: authFile('administrator'), baseURL });
  admin = await GalleryFixtures.forPage(await context.newPage());
  created = [];
  for (let n = 1; n <= 6; n += 1) {
    // sequential: the list is ordered by modification time, newest first
    created.push(
      await admin.create({ title: `${PREFIX} ${n}`, status: n % 2 === 0 ? 'publish' : 'draft' }),
    );
  }
});

test.afterAll(async () => {
  await admin.purge(PREFIX);
});

test.describe('as administrator', () => {
  test.use({ storageState: authFile('administrator') });

  test('counts equal the API totals; the page runs without console or network errors', async ({
    page,
  }) => {
    const monitor = monitorPage(page);
    await openOverview(page);
    const fx = await GalleryFixtures.forPage(page);
    await openOverview(page);
    const total = await fx.galleryTotal();
    const published = await fx.galleryTotal({ status: 'publish' });
    const drafts = await fx.galleryTotal({ status: 'draft,pending' });
    const privates = await fx.galleryTotal({ status: 'private' });
    await expect(tile(page, 'All galleries').locator('.swg-stat__value')).toHaveText(String(total));
    await expect(tile(page, 'Published').locator('.swg-stat__value')).toHaveText(String(published));
    await expect(tile(page, 'Drafts').locator('.swg-stat__value')).toHaveText(String(drafts));
    await expect(tile(page, 'Private').locator('.swg-stat__value')).toHaveText(String(privates));
    await expect(tile(page, 'In trash')).toBeVisible();
    expect(monitor.problems, 'console errors/warnings').toEqual([]);
    expect(monitor.failedRequests, 'failed requests').toEqual([]);
  });

  test('lists the five most recently modified galleries, newest first, linking to the editor', async ({
    page,
  }) => {
    await openOverview(page);
    const rows = root(page).locator('.swg-overview__recent li');
    await expect(rows).toHaveCount(5);
    // The site is shared with parallel specs that create galleries all the time: compare the page with what the
    // API reports as newest at that moment and retry with a fresh page when another spec got in between.
    const fx = await GalleryFixtures.forPage(await page.context().newPage());
    let newest = await fx.list({ per_page: 5 });
    await expect(async () => {
      await openOverview(page);
      await expect(rows).toHaveCount(5);
      newest = await fx.list({ per_page: 5 });
      for (const [index, gallery] of newest.entries()) {
        await expect(rows.nth(index)).toContainText(gallery.title, { timeout: 1000 });
      }
    }).toPass({ timeout: 45_000 });
    await rows.first().getByRole('link').click();
    await expect(page).toHaveURL(new RegExp(`#/galleries/${newest[0]?.id}$`));
    await expect(root(page).locator('.swg-ed')).toBeVisible();
  });

  test('quick actions: new gallery opens the create sheet once, media overview opens', async ({
    page,
  }) => {
    await openOverview(page);
    await root(page)
      .locator('.swg-overview__actions')
      .getByRole('button', { name: 'New gallery' })
      .click();
    await expect(page).toHaveURL(/#\/galleries$/);
    await expect(page.getByRole('dialog', { name: 'New gallery' })).toBeVisible();
    await page.getByRole('button', { name: 'Cancel' }).click();
    await page.reload();
    await expect(root(page).locator('.swg-galleries')).toBeVisible();
    await expect(page.getByRole('dialog', { name: 'New gallery' })).toHaveCount(0);
    await openOverview(page);
    await root(page).getByRole('link', { name: 'Open Media Overview' }).click();
    await expect(page).toHaveURL(/#\/media$/);
    await expect(root(page).locator('.swg-media')).toBeVisible();
  });

  test('the health line matches the status API and links to System Status', async ({ page }) => {
    await openOverview(page);
    const fx = await GalleryFixtures.forPage(page);
    await openOverview(page);
    const status = (await (await fx.rest('status')).json()) as {
      schema: { migrationPending: boolean };
      recentErrors: unknown[];
    };
    const health = root(page).locator('.swg-overview__health');
    await expect(health).toContainText(
      status.schema.migrationPending ? 'Migration pending' : 'Schema is up to date',
    );
    await expect(health).toContainText(
      status.recentErrors.length === 0 ? 'No recent errors' : 'recent error',
    );
    await root(page).getByRole('link', { name: 'Open System Status' }).click();
    await expect(page).toHaveURL(/#\/status$/);
  });

  test('screenshots: light, dark, mobile', async ({ page }) => {
    await openOverview(page);
    await shot(page, 'overview-light');
    await page.setViewportSize({ width: 375, height: 800 });
    await shot(page, 'overview-mobile', true);
    await page.setViewportSize({ width: 1280, height: 900 });
    await preferTheme(page, 'dark');
    await page.reload();
    await expect(page.locator('#swg-admin-root.swg-dark')).toBeVisible();
    await shot(page, 'overview-dark');
  });
});

test.describe('role matrix', () => {
  test('an author sees their own counts, no health card and no trash tile', async ({
    browser,
    baseURL,
  }) => {
    const context = await browser.newContext({ storageState: authFile('author'), baseURL });
    const page = await context.newPage();
    const monitor = monitorPage(page);
    await openOverview(page);
    await expect(tile(page, 'Your galleries')).toBeVisible();
    await expect(root(page).getByText('Plugin health')).toHaveCount(0);
    await expect(tile(page, 'In trash')).toHaveCount(0);
    await expect(root(page).getByRole('link', { name: 'Open System Status' })).toHaveCount(0);
    expect(monitor.requests.filter((u) => /swg(\/|%2F)v1(\/|%2F)status/.test(u))).toEqual([]);
    expect(monitor.failedRequests, 'failed requests').toEqual([]);
    // the author never sees the administrator's galleries in the "recent" list
    await expect(root(page).locator('.swg-overview')).not.toContainText(PREFIX);
    await context.close();
  });

  test('a contributor has neither the media shortcut nor a failing request', async ({
    browser,
    baseURL,
  }) => {
    const context = await browser.newContext({ storageState: authFile('contributor'), baseURL });
    const page = await context.newPage();
    const monitor = monitorPage(page);
    await openOverview(page);
    await expect(root(page).getByRole('link', { name: 'Open Media Overview' })).toHaveCount(0);
    expect(monitor.failedRequests, 'failed requests').toEqual([]);
    await context.close();
  });
});
