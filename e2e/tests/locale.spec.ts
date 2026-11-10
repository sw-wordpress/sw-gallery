import { expect, test } from '@playwright/test';
import { authFile, PLUGIN_PAGE } from '../support/env';

test.describe('user with locale de_DE', () => {
  test.use({ storageState: authFile('german') });

  test('sees German labels in the menu and the app', async ({ page }) => {
    await page.goto('/wp-admin/');
    await expect(page.locator('html')).toHaveAttribute('lang', /^de(-DE)?$/);
    await page.locator('#adminmenu').getByRole('link', { name: 'Galerien', exact: true }).click();
    await expect(page).toHaveURL(/page=swg-galleries$/);

    const nav = page.getByRole('navigation', { name: 'Navigation der Galerieverwaltung' });
    await expect(nav).toBeVisible();
    await expect(nav.getByRole('link')).toHaveText([
      /Übersicht$/,
      /Galerien$/,
      /Medienübersicht$/,
      /Einstellungen$/,
      /Systemstatus$/,
    ]);
    await expect(
      page.locator('#swg-admin-root').getByRole('heading', { level: 1, name: 'Galerieverwaltung' }),
    ).toBeVisible();
    await expect(page.locator('#swg-admin-root').getByRole('heading', { level: 2 })).toHaveText(
      'Übersicht: noch nicht verfügbar',
    );
  });

  test('the English user is unaffected', async ({ browser, baseURL }) => {
    const context = await browser.newContext({ storageState: authFile('administrator') });
    const page = await context.newPage();
    await page.goto(`${baseURL}${PLUGIN_PAGE}`);
    await expect(
      page
        .locator('#swg-admin-root')
        .getByRole('heading', { level: 1, name: 'Gallery Management' }),
    ).toBeVisible();
    await context.close();
  });
});
