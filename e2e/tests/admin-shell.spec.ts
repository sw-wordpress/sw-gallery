import { expect, test } from '@playwright/test';
import { authFile, PLUGIN_PAGE } from '../support/env';
import type { Role } from '../support/env';
import { monitorPage } from '../support/monitor';

const SECTIONS = ['Overview', 'Galleries', 'Media Overview', 'Settings', 'System Status'] as const;

// Every role with edit_posts sees the menu entry (server capability: edit_posts).
const ROLES_WITH_ACCESS: Role[] = ['administrator', 'editor', 'author', 'contributor'];

for (const role of ROLES_WITH_ACCESS) {
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

    test('the app shell has five navigation entries and honest placeholders, without console or network errors', async ({
      page,
    }) => {
      // Monitored from the first request; nothing navigates away from the page, so aborted requests would be real problems.
      const monitor = monitorPage(page);
      await page.goto(PLUGIN_PAGE);

      const nav = page.getByRole('navigation', { name: 'Gallery Management navigation' });
      await expect(nav).toBeVisible();
      await expect(nav.getByRole('link')).toHaveCount(SECTIONS.length);
      await expect(nav.getByRole('link')).toHaveText(SECTIONS.map((s) => new RegExp(`${s}$`)));

      for (const section of SECTIONS) {
        await nav.getByRole('link', { name: section, exact: true }).click();
        await expect(page.locator('#swg-admin-root').getByRole('heading', { level: 2 })).toHaveText(
          `${section}: not yet available`,
        );
        await expect(
          page.getByText(
            'This section is planned but not implemented in this version. It shows no data and offers no actions.',
          ),
        ).toBeVisible();
        // The app marks the active entry with a class only; aria-current is missing (reported as finding).
        await expect(nav.getByRole('link', { name: section, exact: true })).toHaveClass(
          /swg-nav__item--active/,
        );
      }
      await page.waitForLoadState('networkidle');

      expect(monitor.problems, 'console errors/warnings').toEqual([]);
      expect(monitor.failedRequests, 'failed requests').toEqual([]);
    });
  });
}
