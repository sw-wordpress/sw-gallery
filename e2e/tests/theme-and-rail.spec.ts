import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { authFile, PLUGIN_PAGE, SCREENSHOT_DIR } from '../support/env';

test.use({ storageState: authFile('administrator') });

const root = (page: Page) => page.locator('#swg-admin-root');

async function open(page: Page): Promise<void> {
  await page.goto(PLUGIN_PAGE);
  await expect(root(page).locator('.swg-shell')).toBeVisible();
  await page.evaluate(() => document.fonts.ready);
}

async function shot(page: Page, name: string): Promise<void> {
  await mkdir(SCREENSHOT_DIR, { recursive: true });
  await page.evaluate(() => document.fonts.ready);
  await page.screenshot({ path: resolve(SCREENSHOT_DIR, `${name}.png`), fullPage: true });
}

test('the theme toggle switches light and dark and the choice survives a reload', async ({
  page,
}) => {
  await open(page);
  const toggle = page.getByRole('button', { name: 'Dark mode' });
  await expect(toggle).toHaveAttribute('aria-pressed', 'false');
  await expect(root(page)).not.toHaveClass(/swg-dark/);

  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-pressed', 'true');
  await expect(root(page)).toHaveClass(/swg-dark/);
  await expect(page.getByRole('status')).toHaveText('Dark theme on');

  await page.reload();
  await expect(root(page).locator('.swg-shell')).toBeVisible();
  await expect(root(page)).toHaveClass(/swg-dark/);
  await expect(page.getByRole('button', { name: 'Dark mode' })).toHaveAttribute(
    'aria-pressed',
    'true',
  );

  await page.getByRole('button', { name: 'Dark mode' }).click();
  await page.reload();
  await expect(root(page).locator('.swg-shell')).toBeVisible();
  await expect(root(page)).not.toHaveClass(/swg-dark/);
});

test('the rail collapses and expands, shows tooltips only when collapsed and persists across a reload', async ({
  page,
}) => {
  await open(page);
  const collapse = page.getByRole('button', { name: 'Collapse navigation' });
  await expect(collapse).toHaveAttribute('aria-expanded', 'true');
  const rail = root(page).locator('.swg-rail');
  const expandedWidth = (await rail.boundingBox())?.width ?? 0;

  await collapse.click();
  const expand = page.getByRole('button', { name: 'Expand navigation' });
  await expect(expand).toHaveAttribute('aria-expanded', 'false');
  await expect(root(page).locator('.swg-shell--collapsed')).toBeVisible();
  await expect.poll(async () => (await rail.boundingBox())?.width ?? 0).toBeLessThan(expandedWidth);
  // Links keep their accessible names while only the icons are visible.
  const nav = page.getByRole('navigation', { name: 'Gallery Management navigation' });
  await expect(nav.getByRole('link', { name: 'Galleries', exact: true })).toBeVisible();
  await nav.getByRole('link', { name: 'Galleries', exact: true }).hover();
  await expect(page.getByRole('tooltip')).toHaveText('Galleries');

  await page.reload();
  await expect(root(page).locator('.swg-shell')).toBeVisible();
  await expect(root(page).locator('.swg-shell--collapsed')).toBeVisible();
  await page.getByRole('button', { name: 'Expand navigation' }).click();
  await expect(root(page).locator('.swg-shell--collapsed')).toHaveCount(0);
  await page.reload();
  await expect(root(page).locator('.swg-shell')).toBeVisible();
  await expect(root(page).locator('.swg-shell--collapsed')).toHaveCount(0);
});

test('Quasar portal containers on <body> take no part in the page layout (light and dark)', async ({
  page,
}) => {
  async function measure(): Promise<{
    docHeight: number;
    portals: { id: string; height: number }[];
  }> {
    return page.evaluate(() => ({
      docHeight: document.documentElement.scrollHeight,
      portals: [...document.querySelectorAll<HTMLElement>('body > .swg-root')].map((el) => ({
        id: el.id,
        height: Math.round(el.getBoundingClientRect().height),
      })),
    }));
  }

  await open(page);
  const toggle = page.getByRole('button', { name: 'Dark mode' });
  await toggle.hover(); // opens a tooltip: Quasar creates a portal container on <body>
  await expect(page.getByRole('tooltip')).toBeVisible();
  const light = await measure();

  await toggle.click();
  await expect(root(page)).toHaveClass(/swg-dark/);
  await expect(toggle).toHaveAttribute('aria-pressed', 'true');
  await page.mouse.move(0, 0);
  await toggle.hover(); // a fresh tooltip in dark mode
  const dark = await measure();

  // Without the swg-portal rule every container inherited the root's minimum height (and, in dark mode, its
  // background) and stretched the WordPress page by one empty screen each.
  expect(light.portals.length).toBeGreaterThan(0);
  for (const portal of [...light.portals, ...dark.portals]) {
    expect(portal.height, `portal #${portal.id} must not take up page height`).toBeLessThanOrEqual(
      1,
    );
  }
  expect(dark.docHeight).toBe(light.docHeight);
  const viewport = page.viewportSize();
  expect(viewport).not.toBeNull();
  expect(light.docHeight).toBeLessThan((viewport?.height ?? 0) + 400);
});

test('the skip link moves focus to the main region', async ({ page }) => {
  await open(page);
  // WordPress prints its own skip links first; focus the app's link directly instead of counting Tab presses.
  const skip = page.getByRole('link', { name: 'Skip to content' });
  await skip.focus();
  await expect(skip).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.locator('#swg-main')).toBeFocused();
});

test('the app works when localStorage throws on every access', async ({ page }) => {
  await page.addInitScript(() => {
    const fail = (): never => {
      throw new DOMException('storage blocked', 'SecurityError');
    };
    Storage.prototype.getItem = fail;
    Storage.prototype.setItem = fail;
    Storage.prototype.removeItem = fail;
    Storage.prototype.clear = fail;
    Storage.prototype.key = fail;
  });
  // WordPress core scripts (data, preferences) are not storage-proof and throw too: only errors raised by the plugin's own code count.
  const pluginErrors: string[] = [];
  page.on('pageerror', (error) => {
    if ((error.stack ?? '').includes('/plugins/sw-gallery/')) pluginErrors.push(error.message);
  });
  page.on('console', (message) => {
    if (message.type() === 'error' && message.location().url.includes('/plugins/sw-gallery/')) {
      pluginErrors.push(message.text());
    }
  });
  await open(page);

  const nav = page.getByRole('navigation', { name: 'Gallery Management navigation' });
  await expect(nav.getByRole('link')).toHaveCount(5);
  // Toggles still work for the current page view; they just cannot be persisted.
  await page.getByRole('button', { name: 'Dark mode' }).click();
  await expect(root(page)).toHaveClass(/swg-dark/);
  await page.getByRole('button', { name: 'Collapse navigation' }).click();
  await expect(root(page).locator('.swg-shell--collapsed')).toBeVisible();
  await nav.getByRole('link', { name: 'Galleries', exact: true }).click();
  await expect(root(page).getByRole('heading', { level: 2 })).toHaveText('Galleries');

  await page.reload();
  await expect(root(page).locator('.swg-shell')).toBeVisible();
  await expect(root(page)).not.toHaveClass(/swg-dark/);
  await expect(root(page).locator('.swg-shell--collapsed')).toHaveCount(0);
  expect(pluginErrors, 'errors raised by the plugin').toEqual([]);
});

test('screenshots for design review: light, dark, collapsed rail and mobile', async ({ page }) => {
  await open(page);
  await shot(page, 'admin-light');

  await page.getByRole('button', { name: 'Collapse navigation' }).click();
  await expect(root(page).locator('.swg-shell--collapsed')).toBeVisible();
  await shot(page, 'admin-collapsed');
  await page.getByRole('button', { name: 'Expand navigation' }).click();

  await page.getByRole('button', { name: 'Dark mode' }).click();
  await expect(root(page)).toHaveClass(/swg-dark/);
  await shot(page, 'admin-dark');

  await page.setViewportSize({ width: 375, height: 812 });
  await expect(root(page).locator('.swg-rail__name')).toBeHidden();
  await shot(page, 'admin-mobile-dark');
  await page.getByRole('button', { name: 'Dark mode' }).click();
  await expect(root(page)).not.toHaveClass(/swg-dark/);
  await shot(page, 'admin-mobile');
  // No horizontal page scroll at phone width.
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  );
  expect(overflow, 'horizontal overflow at 375 px').toBeLessThanOrEqual(0);
});

test.describe('German user', () => {
  test.use({ storageState: authFile('german') });

  test('screenshot of the German interface', async ({ page }) => {
    await open(page);
    await expect(page.getByRole('button', { name: 'Dunkelmodus' })).toBeVisible();
    await shot(page, 'admin-german');
  });
});
