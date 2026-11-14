import { expect, test } from '@playwright/test';
import { authFile, PLUGIN_PAGE } from '../support/env';
import { shot } from '../support/screenshots';

/**
 * RTL (M2-18). The e2e stack has no RTL language pack (provisioning only installs de_DE and needs the network), so
 * the RTL state is EMULATED: the plugin page is served with `isRtl: true` in the app bootstrap and `dir="rtl"` on
 * `<html>`, exactly what WordPress prints for an RTL locale. The wp-admin chrome itself is not mirrored (WordPress
 * would load its `-rtl.css` files); only the app is verified here.
 */
test.use({ storageState: authFile('administrator') });

test('the app mirrors in an RTL locale: root dir, rail on the right, logical spacing', async ({
  page,
}) => {
  await page.route(/page=swg-galleries/, async (route) => {
    if (route.request().resourceType() !== 'document') {
      await route.continue();
      return;
    }
    const response = await route.fetch();
    const html = (await response.text())
      .replace(/("isRtl":)false/, '$1true')
      .replace(/<html\b/, '<html dir="rtl"');
    await route.fulfill({ response, body: html });
  });
  await page.goto(`${PLUGIN_PAGE}#/galleries`);
  const root = page.locator('#swg-admin-root');
  await expect(root).toHaveAttribute('dir', 'rtl');
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl'); // Quasar did not overwrite it
  const rail = root.locator('.swg-rail');
  const main = root.locator('#swg-main');
  await expect(main).toBeVisible();
  const [railBox, mainBox] = await Promise.all([rail.boundingBox(), main.boundingBox()]);
  expect(railBox!.x).toBeGreaterThan(mainBox!.x); // the rail sits at the inline start = right
  // No horizontal overflow in RTL.
  const overflow = await page.evaluate(
    () => (document.scrollingElement as HTMLElement).scrollWidth - window.innerWidth,
  );
  expect(overflow).toBeLessThanOrEqual(0);
  await shot(page, 'rtl-galleries');
});
