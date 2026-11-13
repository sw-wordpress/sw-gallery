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
    // This user has no manage_options: Settings and System Status are hidden (capability-based navigation).
    await expect(nav.getByRole('link')).toHaveText([/Übersicht$/, /Galerien$/, /Medienübersicht$/]);
    // One h1 per page: printed by the PHP admin page (screen-reader text), translated by gettext.
    await expect(page.getByRole('heading', { level: 1, name: 'Galerieverwaltung' })).toHaveCount(1);
    await expect(page.locator('#swg-admin-root .swg-rail__name strong')).toHaveText('Galerie');
    await expect(page.locator('#swg-admin-root').getByRole('heading', { level: 2 })).toHaveText(
      'Übersicht',
    );
    await expect(
      page.locator('#swg-admin-root').getByRole('heading', { level: 3, name: 'Zuletzt geändert' }),
    ).toBeVisible();
    await expect(page.getByRole('button', { name: 'Dunkelmodus' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Navigation einklappen' })).toBeVisible();
  });

  test('the English user is unaffected', async ({ browser, baseURL }) => {
    const context = await browser.newContext({ storageState: authFile('administrator') });
    const page = await context.newPage();
    await page.goto(`${baseURL}${PLUGIN_PAGE}`);
    await expect(page.getByRole('heading', { level: 1, name: 'Gallery Management' })).toHaveCount(
      1,
    );
    await expect(page.locator('#swg-admin-root .swg-rail__name strong')).toHaveText('Gallery');
    await context.close();
  });
});
