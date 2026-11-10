import { expect, test } from '@playwright/test';
import { PLUGIN_PAGE, requireEnv } from '../support/env';
import { login } from '../support/login';
import { monitorPage } from '../support/monitor';

// The "wordpress-nobuild" service of tests/e2e-env serves the same site with assets/admin hidden behind an empty tmpfs.
test('without a build the page shows the safe diagnostic and loads no plugin script', async ({
  browser,
}) => {
  const baseURL = requireEnv('SWG_E2E_BASE_URL_NOBUILD');
  const context = await browser.newContext();
  const page = await context.newPage();
  const monitor = monitorPage(page);

  await login(page, baseURL, 'administrator');
  const response = await page.goto(`${baseURL}${PLUGIN_PAGE}`);

  expect(response?.status()).toBe(200);
  await expect(page.locator('.notice-error')).toContainText(
    'The gallery admin app is not built. Run "npm run build" in apps/admin or install the release package.',
  );
  await expect(page.locator('#swg-admin-root')).toBeEmpty();
  await expect(page.locator('script[src*="sw-gallery"], link[href*="sw-gallery"]')).toHaveCount(0);
  expect(monitor.requests.filter((url) => url.includes('/plugins/sw-gallery/'))).toEqual([]);
  // The notice must not leak server paths.
  await expect(page.locator('.notice-error')).not.toContainText('/var/www');
  await context.close();
});
