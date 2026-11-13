import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { authFile, PLUGIN_PAGE } from '../support/env';
import { GalleryFixtures } from '../support/galleries';
import { monitorPage } from '../support/monitor';
import { preferTheme, shot } from '../support/screenshots';

/**
 * Settings page (M2-34) against the real `GET`/`PATCH /settings`. The settings are global state of the test site:
 * the file runs in order in one worker and restores the original values after every test.
 */
test.describe.configure({ mode: 'default' });
test.use({ storageState: authFile('administrator') });

interface Values {
  defaultLayout: string;
  itemsPerPage: number;
  lightbox: boolean;
  deleteDataOnUninstall: boolean;
}
let original: Values;
/** Values before the first test: restored in afterAll even when a failing test cannot clean up after itself. */
let initial: Values | undefined;
let fx: GalleryFixtures;

const root = (page: Page): Locator => page.locator('#swg-admin-root');
const live = (page: Page): Locator => root(page).locator('.swg-shell > [role="status"]');
const save = (page: Page): Locator => root(page).getByRole('button', { name: 'Save settings' });
const perPage = (page: Page): Locator => root(page).getByLabel('Images per page');

async function current(): Promise<Values> {
  return (await (await fx.rest('settings')).json()) as Values;
}

async function openSettings(page: Page): Promise<void> {
  await page.goto(`${PLUGIN_PAGE}#/settings`);
  await expect(root(page).locator('.swg-settings__form')).toBeVisible();
}

test.beforeEach(async ({ page }) => {
  fx = await GalleryFixtures.forPage(page);
  original = await current();
  initial ??= original;
});

test.afterAll(async ({ browser, baseURL }) => {
  if (initial === undefined) return;
  const context = await browser.newContext({ storageState: authFile('administrator'), baseURL });
  const cleanup = await GalleryFixtures.forPage(await context.newPage());
  await cleanup.restPatch('settings', { ...initial });
  await context.close();
});

test.afterEach(async () => {
  const { defaultLayout, itemsPerPage, lightbox, deleteDataOnUninstall } = original;
  await fx.restPatch('settings', { defaultLayout, itemsPerPage, lightbox, deleteDataOnUninstall });
});

test('shows the stored values with their defaults, without console or network errors', async ({
  page,
}) => {
  const monitor = monitorPage(page);
  await openSettings(page);
  await expect(perPage(page)).toHaveValue(String(original.itemsPerPage));
  await expect(root(page).getByLabel('Default layout')).toHaveValue(original.defaultLayout);
  await expect(root(page)).toContainText('Default: 24');
  await expect(root(page)).toContainText('Images in the Media Library are never deleted');
  await expect(save(page)).toBeDisabled();
  expect(monitor.problems, 'console errors/warnings').toEqual([]);
  expect(monitor.failedRequests, 'failed requests').toEqual([]);
});

test('validates inline with the server bounds', async ({ page }) => {
  await openSettings(page);
  for (const [value, message] of [
    ['0', 'Must be at least 1.'],
    ['201', 'Must be at most 200.'],
    ['', 'Enter a number.'],
  ] as const) {
    await perPage(page).fill(value);
    await expect(root(page).locator('.q-field__messages')).toContainText(message);
    await expect(save(page)).toBeDisabled();
  }
  expect((await current()).itemsPerPage).toBe(original.itemsPerPage);
});

test('saves only the changed fields and the values survive a reload', async ({ page }) => {
  await openSettings(page);
  const next = original.itemsPerPage === 37 ? 38 : 37;
  await perPage(page).fill(String(next));
  await root(page).locator('.q-toggle', { hasText: 'lightbox' }).click();
  await expect(root(page).getByText('You have unsaved changes.')).toBeVisible();
  const request = page.waitForRequest(
    (r) => r.method() === 'PATCH' && r.url().includes('settings'),
  );
  await save(page).click();
  const body = (await request).postDataJSON() as Record<string, unknown>;
  expect(body).toEqual({ itemsPerPage: next, lightbox: !original.lightbox });
  await expect(live(page)).toHaveText('Settings saved.');
  expect(await current()).toMatchObject({ itemsPerPage: next, lightbox: !original.lightbox });
  await page.reload();
  await expect(perPage(page)).toHaveValue(String(next));
});

test('turning the uninstall deletion on needs the typed word, turning it off does not', async ({
  page,
}) => {
  await openSettings(page);
  const toggle = root(page).locator('.q-toggle', { hasText: 'Delete all plugin data' });
  await toggle.click();
  const dialog = page.getByRole('alertdialog');
  await expect(dialog).toContainText('Images in the Media Library are never deleted');
  const confirm = dialog.getByRole('button', { name: 'Turn on' });
  await expect(confirm).toBeDisabled();
  await dialog.getByLabel('Type DELETE to confirm').fill('delete');
  await expect(confirm).toBeDisabled();
  await dialog.getByLabel('Type DELETE to confirm').fill('DELETE');
  await expect(confirm).toBeEnabled();
  await shot(page, 'settings-confirm');
  await confirm.click();
  // staged: nothing changed on the server before "Save"
  expect((await current()).deleteDataOnUninstall).toBe(false);
  const request = page.waitForRequest((r) => r.method() === 'PATCH');
  await save(page).click();
  expect(((await request).postDataJSON() as Record<string, unknown>).confirmDeleteData).toBe(true);
  await expect(live(page)).toHaveText('Settings saved.');
  expect((await current()).deleteDataOnUninstall).toBe(true);
  // off again: no confirmation, no confirmDeleteData
  await toggle.click();
  await expect(page.getByRole('alertdialog')).toHaveCount(0);
  const off = page.waitForRequest((r) => r.method() === 'PATCH');
  await save(page).click();
  expect(((await off).postDataJSON() as Record<string, unknown>).confirmDeleteData).toBeUndefined();
  await expect(live(page)).toHaveText('Settings saved.');
  expect((await current()).deleteDataOnUninstall).toBe(false);
});

test('the server refuses to turn the deletion on without confirmation', async () => {
  const response = await fx.restPatch('settings', { deleteDataOnUninstall: true });
  expect(response.status()).toBe(422);
  expect(((await response.json()) as { code: string }).code).toBe('swg_confirmation_required');
  expect((await current()).deleteDataOnUninstall).toBe(false);
});

test('asks before leaving with unsaved changes', async ({ page }) => {
  await openSettings(page);
  await perPage(page).fill('55');
  await page
    .getByRole('navigation', { name: 'Gallery Management navigation' })
    .getByRole('link', { name: 'Galleries' })
    .click();
  const dialog = page.getByRole('alertdialog', { name: 'Leave without saving?' });
  await expect(dialog).toBeVisible();
  await dialog.getByRole('button', { name: 'Keep editing' }).click();
  await expect(perPage(page)).toHaveValue('55');
  await page
    .getByRole('navigation', { name: 'Gallery Management navigation' })
    .getByRole('link', { name: 'Galleries' })
    .click();
  await page.getByRole('alertdialog').getByRole('button', { name: 'Leave without saving' }).click();
  await expect(page).toHaveURL(/#\/galleries$/);
});

test('screenshots: light, dark, mobile', async ({ page }) => {
  await openSettings(page);
  await shot(page, 'settings-light');
  await page.setViewportSize({ width: 375, height: 800 });
  await shot(page, 'settings-mobile', true);
  await page.setViewportSize({ width: 1280, height: 900 });
  await preferTheme(page, 'dark');
  await page.reload();
  await expect(page.locator('#swg-admin-root.swg-dark')).toBeVisible();
  await shot(page, 'settings-dark');
});

test.describe('role matrix', () => {
  for (const role of ['editor', 'author', 'contributor'] as const) {
    test(`${role}: no Settings entry, no access on a deep link, API answers 403`, async ({
      browser,
      baseURL,
    }) => {
      const context = await browser.newContext({ storageState: authFile(role), baseURL });
      const page = await context.newPage();
      await page.goto(`${PLUGIN_PAGE}#/settings`);
      await expect(root(page).getByRole('heading', { name: 'No access' })).toBeVisible();
      await expect(
        page
          .getByRole('navigation', { name: 'Gallery Management navigation' })
          .getByRole('link', { name: 'Settings' }),
      ).toHaveCount(0);
      const other = await GalleryFixtures.forPage(page);
      expect((await other.rest('settings')).status()).toBe(403);
      expect((await other.restPatch('settings', { itemsPerPage: 5 })).status()).toBe(403);
      await context.close();
    });
  }
});
