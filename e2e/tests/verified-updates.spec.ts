import { expect, test } from '@playwright/test';
import { authFile, credentials, pluginVersion, PLUGIN_PAGE } from '../support/env';

test.use({ storageState: authFile('administrator') });
test('verified updates live only in the native plugin row and never install automatically', async ({
  page,
}) => {
  const github: string[] = [];
  page.on('request', (request) => {
    if (/github\.com|githubusercontent\.com/.test(request.url())) github.push(request.url());
  });
  await page.goto('/wp-admin/plugins.php');
  const row = page.locator('tr[data-plugin="sw-gallery/sw-gallery.php"]');
  await expect(row).toBeVisible();
  await expect(page.locator('#footer-left')).not.toContainText('Open source by Stefan Warnat');
  await expect(row).toContainText('Manual updates only');
  await expect(row.locator('#swg-verified-updates-status')).toContainText('no public release key');
  await expect(page.locator('script[src*="sw-gallery-updates.js"]')).toHaveAttribute(
    'src',
    new RegExp(`ver=${pluginVersion().replaceAll('.', '\\.')}`),
  );
  await row.getByRole('button', { name: 'Check for updates now', exact: true }).click();
  await expect(row.locator('#swg-verified-updates-result')).toContainText('blocked');
  await expect(
    row.getByRole('button', { name: 'Check for updates now', exact: true }),
  ).toBeEnabled();
  expect(github).toEqual([]);
  await page.goto(PLUGIN_PAGE);
  await expect(page.locator('#swg-admin-root')).toBeVisible();
  await expect(page.locator('#swg-author-credit')).toBeVisible();
  await expect(page.locator('#footer-left')).toHaveText(
    'Open source by Stefan Warnat – free to use, free to change, better together.',
  );
  await expect(page.locator('#swg-verified-updates-check')).toHaveCount(0);
  await expect(page.locator('script[src*="sw-gallery-updates.js"]')).toHaveCount(0);
  await page.goto(`${PLUGIN_PAGE}#/galleries`);
  await expect(page.locator('#swg-author-credit')).toBeHidden();
});

test.describe('German plugin management', () => {
  test.use({ storageState: { cookies: [], origins: [] } });
  test('translates the update action and blocked status', async ({ page }) => {
    await page.goto('/wp-login.php');
    await page.getByLabel('Username or Email Address').fill('updates-german');
    await page.getByLabel('Password', { exact: true }).fill(credentials('german').password);
    await page.getByRole('button', { name: 'Log In' }).click();
    await expect(page.locator('#wpadminbar')).toBeVisible();
    await page.goto('/wp-admin/plugins.php');
    const row = page.locator('tr[data-plugin="sw-gallery/sw-gallery.php"]');
    await expect(row).toContainText('Nur manuelle Aktualisierung');
    await expect(
      row.getByRole('button', { name: 'Jetzt auf Updates prüfen', exact: true }),
    ).toBeVisible();
    await expect(row.locator('#swg-verified-updates-status')).toContainText(
      'kein öffentlicher Release-Schlüssel',
    );
    await row.getByRole('button', { name: 'Jetzt auf Updates prüfen', exact: true }).click();
    await expect(row.locator('#swg-verified-updates-result')).toContainText(
      'GitHub-Updates sind gesperrt',
    );
    await page.goto(PLUGIN_PAGE);
    await expect(page.locator('#swg-author-credit')).toBeVisible();
    await expect(page.locator('#footer-left')).toHaveText(
      'Open Source von Stefan Warnat – frei nutzbar, frei veränderbar, gemeinsam besser.',
    );
  });
});
