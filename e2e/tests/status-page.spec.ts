import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { authFile, PLUGIN_PAGE, pluginVersion } from '../support/env';
import { GalleryFixtures } from '../support/galleries';
import { monitorPage } from '../support/monitor';
import { preferTheme, shot } from '../support/screenshots';

/** System Status page (M2-34) against the real `GET /status`. Read-only: nothing is created or changed. */
const PATH_LIKE =
  /(\/var\/www|\/home\/|\/usr\/|\/srv\/|\/opt\/|\/etc\/|\/tmp\/|[A-Za-z]:\\|wp-content|wp-includes|wp-admin|ABSPATH|DB_PASSWORD|AUTH_KEY)/;

const root = (page: Page): Locator => page.locator('#swg-admin-root');

async function openStatus(page: Page): Promise<void> {
  await page.goto(`${PLUGIN_PAGE}#/status`);
  await expect(root(page).locator('.swg-status__sections')).toBeVisible();
}

test.describe('as administrator', () => {
  test.use({ storageState: authFile('administrator') });

  test('renders every section with the real data, without console or network errors', async ({
    page,
    context,
  }) => {
    // Fixtures use their own page: navigating the monitored page away would abort its requests.
    const fx = await GalleryFixtures.forPage(await context.newPage());
    const monitor = monitorPage(page);
    await openStatus(page);
    const report = (await (await fx.rest('status')).json()) as {
      counts: { galleries: Record<string, number>; itemRows: { count: number } };
      versions: { plugin: string; php: string; wordpress: string };
    };
    const content = root(page).locator('.swg-status');
    await expect(content).toContainText('Schema is up to date');
    await expect(content).toContainText('Item table present');
    await expect(content).toContainText('Recent errors');
    await expect(content).toContainText('Admin app build is complete');
    await expect(content).toContainText('No render cache active');
    // counts equal the API's
    const published = root(page).locator('.swg-stat', { hasText: 'Published' }).first();
    await expect(published.locator('.swg-stat__value')).toHaveText(
      new Intl.NumberFormat('en-US').format(report.counts.galleries.publish ?? 0),
    );
    // versions: the plugin version comes from the plugin file, independent of the page
    expect(report.versions.plugin).toBe(pluginVersion());
    await expect(content.locator('.swg-status__dl')).toContainText(pluginVersion());
    await expect(content.locator('.swg-status__dl')).toContainText(report.versions.php);
    await expect(content.locator('.swg-status__dl')).toContainText(report.versions.wordpress);
    await expect(content).toContainText('Galleries are not exported in 1.0');
    expect(monitor.problems, 'console errors/warnings').toEqual([]);
    expect(monitor.failedRequests, 'failed requests').toEqual([]);
  });

  test('shows no file system path, host or secret, in the page and in the API', async ({
    page,
  }) => {
    await openStatus(page);
    const fx = await GalleryFixtures.forPage(page);
    await openStatus(page);
    expect((await root(page).locator('.swg-status').textContent()) ?? '').not.toMatch(PATH_LIKE);
    expect(await (await fx.rest('status')).text()).not.toMatch(PATH_LIKE);
  });

  test('refreshes on demand with one request and no polling', async ({ page }) => {
    await openStatus(page);
    const requests: string[] = [];
    page.on('request', (r) => {
      if (/swg(\/|%2F)v1(\/|%2F)status/.test(r.url())) requests.push(r.url());
    });
    // no timer may fire a request by itself: observe a few seconds of idle page time
    await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 3000)));
    expect(requests).toHaveLength(0);
    const refreshed = page.waitForResponse((r) => /swg(\/|%2F)v1(\/|%2F)status/.test(r.url()));
    await root(page).getByRole('button', { name: 'Refresh' }).click();
    expect((await refreshed).status()).toBe(200);
    expect(requests).toHaveLength(1);
  });

  test('copies a plain-text summary without paths or secrets', async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await openStatus(page);
    await root(page).getByRole('button', { name: 'Copy summary' }).click();
    await expect(root(page).locator('.swg-shell > [role="status"]')).toHaveText(
      'Diagnostic summary copied to the clipboard.',
    );
    const text = await page.evaluate(() => navigator.clipboard.readText());
    expect(text).toContain('sw-gallery diagnostic summary');
    expect(text).toContain(`Plugin: ${pluginVersion()}`);
    expect(text).toContain('Schema: stored');
    expect(text).not.toMatch(PATH_LIKE);
  });

  test('screenshots: light, dark, mobile', async ({ page }) => {
    await openStatus(page);
    await shot(page, 'status-light', true);
    await page.setViewportSize({ width: 375, height: 800 });
    await shot(page, 'status-mobile');
    await page.setViewportSize({ width: 1280, height: 900 });
    await preferTheme(page, 'dark');
    await page.reload();
    await expect(page.locator('#swg-admin-root.swg-dark')).toBeVisible();
    await shot(page, 'status-dark', true);
  });
});

test.describe('role matrix', () => {
  for (const role of ['editor', 'author', 'contributor'] as const) {
    test(`${role}: no System Status entry, no access on a deep link, API answers 403`, async ({
      browser,
      baseURL,
    }) => {
      const context = await browser.newContext({ storageState: authFile(role), baseURL });
      const page = await context.newPage();
      await page.goto(`${PLUGIN_PAGE}#/status`);
      await expect(root(page).getByRole('heading', { name: 'No access' })).toBeVisible();
      await expect(
        page
          .getByRole('navigation', { name: 'Gallery Management navigation' })
          .getByRole('link', { name: 'System Status' }),
      ).toHaveCount(0);
      const fx = await GalleryFixtures.forPage(page);
      expect((await fx.rest('status')).status()).toBe(403);
      await context.close();
    });
  }
});
