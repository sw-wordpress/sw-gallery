import { expect, test } from '@playwright/test';
import { authFile, PLUGIN_PAGE } from '../support/env';
import type { Role } from '../support/env';
import { monitorPage } from '../support/monitor';

const ALL_SECTIONS = [
  'Overview',
  'Galleries',
  'Media Overview',
  'Settings',
  'System Status',
] as const;
// Settings and System Status need manage_options, the Media Overview needs upload_files (UX hints from
// window.swgAdmin.capabilities; the server enforces).
const WITHOUT_MANAGE = ALL_SECTIONS.filter((s) => s !== 'Settings' && s !== 'System Status');
const WITHOUT_UPLOAD = WITHOUT_MANAGE.filter((s) => s !== 'Media Overview');

// Every role with edit_posts sees the menu entry (server capability: edit_posts).
const ROLES_WITH_ACCESS: Array<[Role, readonly string[]]> = [
  ['administrator', ALL_SECTIONS],
  ['editor', WITHOUT_MANAGE],
  ['author', WITHOUT_MANAGE],
  ['contributor', WITHOUT_UPLOAD],
];

for (const [role, sections] of ROLES_WITH_ACCESS) {
  test.describe(`admin shell as ${role}`, () => {
    test.use({ storageState: authFile(role) });

    test('the menu entry leads to the plugin screen', async ({ page }) => {
      await page.goto('/wp-admin/');
      await page
        .locator('#adminmenu')
        .getByRole('link', { name: 'Galleries', exact: true })
        .click();
      await expect(page).toHaveURL(new RegExp(`${PLUGIN_PAGE.replace('?', '\\?')}$`));
      await expect(
        page.getByRole('navigation', { name: 'Gallery Management navigation' }),
      ).toBeVisible();
    });

    test(`the app shell offers ${sections.length} navigation entries and a real page each, without console or network errors`, async ({
      page,
    }) => {
      // Monitored from the first request; nothing navigates away from the page, so aborted requests would be real problems.
      const monitor = monitorPage(page);
      await page.goto(PLUGIN_PAGE);

      const nav = page.getByRole('navigation', { name: 'Gallery Management navigation' });
      await expect(nav).toBeVisible();
      await expect(nav.getByRole('link')).toHaveCount(sections.length);
      await expect(nav.getByRole('link')).toHaveText(sections.map((s) => new RegExp(`${s}$`)));

      const root = page.locator('#swg-admin-root');
      for (const section of sections) {
        const link = nav.getByRole('link', { name: section, exact: true });
        await link.click();
        // The PHP page owns the only h1; the app's page title is an h2.
        await expect(root.getByRole('heading', { level: 2 }).first()).toHaveText(section);
        // Every section is a real page: no placeholder, no empty shell.
        await expect(root.locator('.swg-placeholder')).toHaveCount(0);
        await expect(
          root.locator('[role="region"][aria-busy="true"], [aria-busy="true"]'),
        ).toHaveCount(0);
        await expect(link).toHaveAttribute('aria-current', 'page');
        await expect(link).toHaveClass(/swg-nav-link--active/);
        await expect(nav.locator('[aria-current="page"]')).toHaveCount(1);
      }
      await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
      await page.waitForLoadState('networkidle');

      expect(monitor.problems, 'console errors/warnings').toEqual([]);
      expect(monitor.failedRequests, 'failed requests').toEqual([]);
    });

    if (role !== 'administrator') {
      test('a deep link into a hidden section shows the no-access state', async ({ page }) => {
        await page.goto(`${PLUGIN_PAGE}#/settings`);
        await expect(
          page.locator('#swg-admin-root').getByRole('heading', { name: 'No access' }),
        ).toBeVisible();
      });

      if (role === 'contributor') {
        test('has no Media Overview (no upload_files): a deep link shows no-access', async ({
          page,
        }) => {
          await page.goto(`${PLUGIN_PAGE}#/media`);
          await expect(
            page.locator('#swg-admin-root').getByRole('heading', { name: 'No access' }),
          ).toBeVisible();
        });
      }

      test('the status page is hidden as well and sends no request', async ({ page }) => {
        const requests: string[] = [];
        page.on('request', (request) => requests.push(request.url()));
        await page.goto(`${PLUGIN_PAGE}#/status`);
        await expect(
          page.locator('#swg-admin-root').getByRole('heading', { name: 'No access' }),
        ).toBeVisible();
        expect(
          requests.filter((url) =>
            /swg\/v1\/(status|settings)|swg%2Fv1%2F(status|settings)/.test(url),
          ),
        ).toEqual([]);
      });
    }
  });
}
