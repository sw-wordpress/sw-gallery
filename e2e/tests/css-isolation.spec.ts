import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { authFile, PLUGIN_PAGE, SCREENSHOT_DIR } from '../support/env';
import { monitorPage } from '../support/monitor';

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

/** Chrome whose markup must not change at all while the app switches theme or collapses its rail. */
const CHROME_MARKUP = ['#adminmenu', '#wpadminbar', '#wpfooter'];

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

/** Restyling check: every snapshot key of the dashboard baseline must be identical in `actual`. */
function expectSameAsDashboard(dashboard: Snapshot, actual: Snapshot, label: string): void {
  for (const key of Object.keys(dashboard)) {
    expect(dashboard[key], `${key} must exist on the dashboard`).not.toBe('missing');
    expect(actual[key], `${key} (${label} vs dashboard)`).toEqual(dashboard[key]);
  }
}

async function outsideMarkup(page: Page): Promise<Record<string, string>> {
  return page.evaluate((selectors) => {
    const result: Record<string, string> = {};
    for (const selector of selectors) {
      result[selector] = document.querySelector(selector)?.outerHTML ?? 'missing';
    }
    result['html class'] = document.documentElement.className;
    // Quasar's dark plugin swaps body--dark/body--light on <body>; no stylesheet targets them outside the app.
    result['body class (without body--dark/body--light)'] = document.body.className
      .split(/\s+/)
      .filter((c) => c !== 'body--dark' && c !== 'body--light')
      .join(' ');
    return result;
  }, CHROME_MARKUP);
}

async function chromeFonts(page: Page): Promise<Record<string, string>> {
  return page.evaluate((selectors) => {
    const result: Record<string, string> = {};
    for (const selector of selectors) {
      const el = document.querySelector(selector);
      result[selector] = el ? getComputedStyle(el).fontFamily : 'missing';
    }
    return result;
  }, CHROME);
}

async function dashboardBaseline(page: Page): Promise<Snapshot> {
  await page.goto('/wp-admin/');
  await expect(page.locator('#adminmenu')).toBeVisible();
  return snapshot(page);
}

test('Quasar does not restyle wp-admin chrome or bare elements outside the app root', async ({
  page,
}) => {
  await mkdir(SCREENSHOT_DIR, { recursive: true });

  const dashboard = await dashboardBaseline(page);
  await page.screenshot({ path: resolve(SCREENSHOT_DIR, 'dashboard.png'), fullPage: true });

  await page.goto(PLUGIN_PAGE);
  await expect(page.locator('#swg-admin-root .swg-shell')).toBeVisible();
  await expect(page.locator('script[type="module"][src*="sw-gallery"]')).toHaveCount(1);
  await expect(page.locator('link[rel="stylesheet"][href*="sw-gallery"]')).toHaveCount(1);
  const plugin = await snapshot(page);
  await page.screenshot({ path: resolve(SCREENSHOT_DIR, 'plugin-screen.png'), fullPage: true });

  expectSameAsDashboard(dashboard, plugin, 'plugin screen');
});

test('dark mode on and off leaves wp-admin chrome and bare elements untouched', async ({
  page,
}) => {
  const dashboard = await dashboardBaseline(page);
  await page.goto(PLUGIN_PAGE);
  const root = page.locator('#swg-admin-root');
  await expect(root).toHaveClass(/swg-root/);
  await expect(root).not.toHaveClass(/swg-dark/);
  const markupLight = await outsideMarkup(page);
  const shell = root.locator('.swg-shell');

  const toggle = page.getByRole('button', { name: 'Dark mode' });
  await toggle.click();
  await expect(root).toHaveClass(/swg-dark/);
  await expect(toggle).toHaveAttribute('aria-pressed', 'true');
  // The app really changed: its background is the dark token now.
  expect(await shell.evaluate((el) => getComputedStyle(el).backgroundColor)).toBe(
    'rgb(10, 15, 31)',
  );
  expectSameAsDashboard(dashboard, await snapshot(page), 'plugin screen, dark mode on');
  expect(await outsideMarkup(page), 'wp-admin markup with dark mode on').toEqual(markupLight);

  await toggle.click();
  await expect(root).not.toHaveClass(/swg-dark/);
  await expect(toggle).toHaveAttribute('aria-pressed', 'false');
  expect(await shell.evaluate((el) => getComputedStyle(el).backgroundColor)).toBe(
    'rgb(243, 244, 250)',
  );
  expectSameAsDashboard(dashboard, await snapshot(page), 'plugin screen, dark mode off again');
  expect(await outsideMarkup(page), 'wp-admin markup with dark mode off again').toEqual(
    markupLight,
  );
});

test.describe('system preference dark', () => {
  test.use({ colorScheme: 'dark' });

  test('the app follows the system preference, wp-admin stays as it is', async ({ page }) => {
    const dashboard = await dashboardBaseline(page);
    await page.goto(PLUGIN_PAGE);
    await expect(page.locator('#swg-admin-root')).toHaveClass(/swg-dark/);
    await expect(page.getByRole('button', { name: 'Dark mode' })).toHaveAttribute(
      'aria-pressed',
      'true',
    );
    expectSameAsDashboard(dashboard, await snapshot(page), 'plugin screen, system dark');
  });
});

test('collapsing the rail does not touch anything outside the app root', async ({ page }) => {
  const dashboard = await dashboardBaseline(page);
  await page.goto(PLUGIN_PAGE);
  const markup = await outsideMarkup(page);
  await page.getByRole('button', { name: 'Collapse navigation' }).click();
  await expect(page.locator('.swg-shell--collapsed')).toBeVisible();
  expectSameAsDashboard(dashboard, await snapshot(page), 'plugin screen, rail collapsed');
  expect(await outsideMarkup(page)).toEqual(markup);
});

test('the bundled fonts are used inside the app only and never restyle wp-admin', async ({
  page,
  baseURL,
}) => {
  const requests: string[] = [];
  page.on('request', (request) => requests.push(request.url()));
  const monitor = monitorPage(page);

  await page.goto('/wp-admin/');
  await expect(page.locator('#adminmenu')).toBeVisible();
  const dashboardFonts = await chromeFonts(page);
  for (const [selector, family] of Object.entries(dashboardFonts)) {
    expect(family, `${selector} font on the dashboard`).not.toBe('missing');
    expect(family, `${selector} font on the dashboard`).not.toMatch(/Inter|JetBrains/i);
  }

  await page.goto(PLUGIN_PAGE);
  await expect(page.locator('#swg-admin-root .swg-shell')).toBeVisible();
  // Inside the app: Inter first, and the browser actually loaded the bundled face.
  const family = await page
    .locator('#swg-admin-root .swg-main')
    .evaluate((el) => getComputedStyle(el).fontFamily);
  expect(family).toMatch(/^"?Inter Variable"?,/);
  await page.evaluate(() => document.fonts.load('16px "Inter Variable"', 'Galerie'));
  const loaded = await page.evaluate(
    () =>
      Array.from(document.fonts).filter((f) => f.family.includes('Inter') && f.status === 'loaded')
        .length,
  );
  expect(loaded, 'loaded Inter faces').toBeGreaterThan(0);

  // The wp-admin chrome of the plugin page keeps its fonts.
  expect(await chromeFonts(page)).toEqual(dashboardFonts);

  // Self-hosted only: every font file comes from the plugin's own assets, none from a CDN.
  const fonts = requests.filter((url) => /\.woff2?(\?|$)/.test(url));
  expect(fonts.length, 'font requests').toBeGreaterThan(0);
  const origin = new URL(baseURL ?? '').origin;
  for (const url of fonts) {
    expect(new URL(url).origin, url).toBe(origin);
    expect(url, url).toContain('/plugins/sw-gallery/');
  }
  expect(monitor.failedRequests, 'failed requests').toEqual([]);
});

test('the app itself is styled (Quasar CSS is actually applied inside the root)', async ({
  page,
}) => {
  await page.goto(PLUGIN_PAGE);
  const nav = page.getByRole('navigation', { name: 'Gallery Management navigation' });
  await expect(nav).toBeVisible();
  const item = nav.getByRole('link').first();
  expect(await item.evaluate((el) => getComputedStyle(el).display)).toBe('flex');
  expect(await item.evaluate((el) => getComputedStyle(el).borderRadius)).toBe('12px');
  await expect(page.locator('#swg-admin-root')).toHaveClass(/swg-root/);
});

// M2-16: screens that have nothing to do with the plugin must not load a single plugin asset, so nothing of the app can
// restyle them. Checked on the dashboard and the posts list (a heavy core screen with tables, buttons and forms).
for (const screen of ['/wp-admin/', '/wp-admin/edit.php', '/wp-admin/options-general.php']) {
  test(`foreign admin screen ${screen} loads no plugin asset and carries no app styles`, async ({
    page,
  }) => {
    const requests: string[] = [];
    page.on('request', (request) => requests.push(request.url()));
    const monitor = monitorPage(page);

    await page.goto(screen);
    await expect(page.locator('#adminmenu')).toBeVisible();

    // No plugin stylesheet, script, font or image was requested or embedded.
    expect(
      requests.filter((url) => url.includes('/plugins/sw-gallery/')),
      'requests to the plugin directory',
    ).toEqual([]);
    await expect(page.locator('script[src*="sw-gallery"], link[href*="sw-gallery"]')).toHaveCount(
      0,
    );
    await expect(page.locator('#swg-admin-root, .swg-root, .swg-portal')).toHaveCount(0);
    expect(
      await page.evaluate(() => (window as unknown as { swgAdmin?: unknown }).swgAdmin),
      'the bootstrap object exists only on the plugin screen',
    ).toBeUndefined();

    // No rule of any loaded stylesheet belongs to Quasar or the plugin (Quasar and swg classes, body theme classes).
    const leaked = await page.evaluate(() => {
      const found: string[] = [];
      for (const sheet of Array.from(document.styleSheets)) {
        let rules: CSSRuleList;
        try {
          rules = sheet.cssRules;
        } catch {
          continue; // cross-origin sheet: not ours (the plugin serves from this origin)
        }
        for (const rule of Array.from(rules)) {
          const selector = (rule as CSSStyleRule).selectorText ?? '';
          if (/\.q-|\.swg-|\.body--(dark|light)/.test(selector)) {
            found.push(selector.slice(0, 80));
          }
        }
      }
      return found;
    });
    expect(leaked, 'Quasar or plugin selectors in the page styles').toEqual([]);

    // WordPress' own look is intact: core colours and fonts, none of the app's tokens.
    const look = await page.evaluate(() => {
      const css = (selector: string, property: string): string => {
        const el = document.querySelector(selector);
        return el
          ? ((getComputedStyle(el) as unknown as Record<string, string>)[property] ?? 'missing')
          : 'missing';
      };
      return {
        menuBackground: css('#adminmenu', 'backgroundColor'),
        barBackground: css('#wpadminbar', 'backgroundColor'),
        bodyFontSize: css('body', 'fontSize'),
        bodyFont: css('body', 'fontFamily'),
        bodyBackground: css('body', 'backgroundColor'),
        appTokenOnRoot: getComputedStyle(document.documentElement).getPropertyValue('--q-primary'),
      };
    });
    expect(look.menuBackground).toBe('rgb(29, 35, 39)');
    expect(look.barBackground).toBe('rgb(29, 35, 39)');
    expect(look.bodyFontSize).toBe('13px');
    expect(look.bodyFont).not.toMatch(/Inter|JetBrains/i);
    expect(look.bodyBackground).toBe('rgb(240, 240, 241)');
    expect(look.appTokenOnRoot.trim()).toBe('');
    expect(monitor.failedRequests, 'failed requests').toEqual([]);
  });
}
