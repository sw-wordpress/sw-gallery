import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { authFile, PLUGIN_PAGE, SCREENSHOT_DIR } from '../support/env';

test.use({ storageState: authFile('administrator') });

const PROPERTIES = [
  'fontFamily',
  'fontSize',
  'fontWeight',
  'lineHeight',
  'letterSpacing',
  'color',
  'backgroundColor',
  'marginTop',
  'marginRight',
  'marginBottom',
  'marginLeft',
  'paddingTop',
  'paddingRight',
  'paddingBottom',
  'paddingLeft',
  'boxSizing',
  'display',
  'borderTopWidth',
  'textDecorationLine',
  'listStyleType',
] as const;

// Existing wp-admin chrome that is identical on every screen (the "current" menu item and page headings differ legitimately).
const CHROME = [
  'html',
  'body',
  '#wpwrap',
  '#wpcontent',
  '#adminmenu',
  '#adminmenu #menu-posts > a',
  '#adminmenu #menu-media > a',
  '#adminmenu #menu-posts .wp-menu-name',
  '#wpadminbar',
  '#wpadminbar #wp-admin-bar-site-name > a',
  '#wpfooter',
];

// Bare elements injected into wp-admin content (outside the app root): any Quasar element selector that leaked would hit them.
const PROBE_TAGS = [
  'h1',
  'h2',
  'h3',
  'p',
  'a',
  'button',
  'input',
  'label',
  'ul',
  'li',
  'table',
  'img',
  'svg',
  'small',
  'b',
  'code',
  'hr',
  'textarea',
  'select',
];

type Snapshot = Record<string, Record<string, string> | 'missing'>;

async function snapshot(page: Page): Promise<Snapshot> {
  return page.evaluate(
    ({ chrome, probes, properties }) => {
      const read = (el: Element): Record<string, string> => {
        const style = getComputedStyle(el) as unknown as Record<string, string>;
        return Object.fromEntries(properties.map((p) => [p, String(style[p])]));
      };
      const result: Snapshot = {};
      for (const selector of chrome) {
        const el = document.querySelector(selector);
        result[selector] = el ? read(el) : 'missing';
      }
      const holder = document.createElement('div');
      holder.id = 'swg-e2e-probe';
      holder.innerHTML = probes.map((tag) => `<${tag} data-probe="${tag}"></${tag}>`).join('');
      document.querySelector('#wpbody-content')?.appendChild(holder);
      for (const tag of probes) {
        const el = holder.querySelector(`[data-probe="${tag}"]`);
        result[`probe <${tag}>`] = el ? read(el) : 'missing';
      }
      holder.remove();
      return result;
    },
    { chrome: CHROME, probes: PROBE_TAGS, properties: [...PROPERTIES] },
  );
}

test('Quasar does not restyle wp-admin chrome or bare elements outside the app root', async ({
  page,
}) => {
  await mkdir(SCREENSHOT_DIR, { recursive: true });

  await page.goto('/wp-admin/');
  await expect(page.locator('#adminmenu')).toBeVisible();
  const dashboard = await snapshot(page);
  await page.screenshot({ path: resolve(SCREENSHOT_DIR, 'dashboard.png'), fullPage: true });

  await page.goto(PLUGIN_PAGE);
  await expect(page.locator('#swg-admin-root .swg-shell')).toBeVisible();
  await expect(page.locator('script[type="module"][src*="sw-gallery"]')).toHaveCount(1);
  await expect(page.locator('link[rel="stylesheet"][href*="sw-gallery"]')).toHaveCount(1);
  const plugin = await snapshot(page);
  await page.screenshot({ path: resolve(SCREENSHOT_DIR, 'plugin-screen.png'), fullPage: true });

  for (const key of Object.keys(dashboard)) {
    expect(dashboard[key], `${key} must exist on the dashboard`).not.toBe('missing');
    expect(plugin[key], `${key} (plugin screen vs dashboard)`).toEqual(dashboard[key]);
  }
});

test('the app itself is styled (Quasar CSS is actually applied inside the root)', async ({
  page,
}) => {
  await page.goto(PLUGIN_PAGE);
  const nav = page.getByRole('navigation', { name: 'Gallery Management navigation' });
  await expect(nav).toBeVisible();
  const item = nav.getByRole('link').first();
  expect(await item.evaluate((el) => getComputedStyle(el).display)).toBe('flex');
  expect(await item.evaluate((el) => getComputedStyle(el).minHeight)).not.toBe('0px');
  await expect(page.locator('#swg-admin-root')).toHaveClass(/swg-root/);
});
