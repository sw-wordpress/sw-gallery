import { expect, test } from '@playwright/test';
import { authFile, PLUGIN_PAGE, pluginVersion } from '../support/env';
import { monitorPage } from '../support/monitor';

test.use({ storageState: authFile('administrator') });

test('plugin assets are not loaded outside the plugin screen', async ({ page }) => {
  const monitor = monitorPage(page);
  for (const path of ['/wp-admin/', '/wp-admin/upload.php', '/wp-admin/edit.php']) {
    await page.goto(path);
    await expect(page.locator('#wpadminbar')).toBeVisible();
    // The Media Library screen loads the one small delete-warning script (M2-38) and nothing else.
    const allowed = path === '/wp-admin/upload.php' ? 'script[src*="media-library.js"]' : null;
    const own = page.locator('script[src*="sw-gallery"], link[href*="sw-gallery"]');
    if (allowed === null) await expect(own).toHaveCount(0);
    else await expect(own.and(page.locator(`:not(${allowed})`))).toHaveCount(0);
    await expect(page.locator('#swg-admin-root')).toHaveCount(0);
  }
  expect(
    monitor.requests.filter(
      (url) => url.includes('/plugins/sw-gallery/') && !url.includes('/media-library/'),
    ),
  ).toEqual([]);
});

test('every plugin script and stylesheet on its screen carries ver=<plugin version>', async ({
  page,
}) => {
  const version = pluginVersion();
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

  expect(urls.length, 'at least one script and one stylesheet').toBeGreaterThanOrEqual(2);
  expect(urls.some((url) => /\.js(\?|$)/.test(url))).toBe(true);
  expect(urls.some((url) => /\.css(\?|$)/.test(url))).toBe(true);
  for (const url of urls) {
    expect(new URL(url).searchParams.get('ver'), url).toBe(version);
  }
  // The entry script is an ES module.
  await expect(page.locator('script[type="module"][src*="sw-gallery"]')).toHaveCount(1);
});
