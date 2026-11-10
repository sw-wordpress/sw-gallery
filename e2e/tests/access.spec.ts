import { expect, test } from '@playwright/test';
import { authFile, PLUGIN_PAGE } from '../support/env';
import { monitorPage } from '../support/monitor';

test.describe('subscriber', () => {
  test.use({ storageState: authFile('subscriber') });

  test('has no menu entry', async ({ page }) => {
    await page.goto('/wp-admin/');
    const menu = page.locator('#adminmenu');
    await expect(menu.getByRole('link', { name: 'Profile', exact: true })).toBeVisible();
    await expect(menu.getByRole('link', { name: 'Galleries' })).toHaveCount(0);
  });

  test('is refused on a direct request and loads no plugin assets', async ({ page }) => {
    const monitor = monitorPage(page);
    const response = await page.goto(PLUGIN_PAGE);

    expect(response?.status()).toBe(403);
    await expect(page.getByText('Sorry, you are not allowed to access this page.')).toBeVisible();
    await expect(page.locator('#swg-admin-root')).toHaveCount(0);
    await expect(page.locator('script[src*="sw-gallery"], link[href*="sw-gallery"]')).toHaveCount(
      0,
    );
    expect(monitor.requests.filter((url) => url.includes('/plugins/sw-gallery/'))).toEqual([]);
  });
});
