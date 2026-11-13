import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page, TestInfo } from '@playwright/test';
import { authFile, PLUGIN_PAGE } from '../support/env';
import { GalleryFixtures, tinyPng, uniquePrefix } from '../support/galleries';
import type { GalleryRecord, MediaRecord } from '../support/galleries';

test.use({ storageState: authFile('administrator') });
// One worker for the whole file: the fixtures (and their purge in afterAll) are shared by the light and the dark run.
test.describe.configure({ mode: 'serial' });

/**
 * Rule ids waived for serious/critical violations; every entry needs a justification. Currently none:
 * the former moderate findings `landmark-main-is-top-level` / `landmark-no-duplicate-main` are fixed (the app
 * shell is a labelled region, WordPress' own `#wpbody` is the document's main landmark).
 */
const WAIVED: Record<string, string> = {};

/**
 * Narrow, route-scoped waivers of MODERATE landmark findings in the gallery editor. `features/editor/GalleryEditor.vue`
 * is owned by another work stream in this wave and still uses a `<header>` and an `<aside>` inside the app region
 * (they become nested banner/complementary landmarks). Fix: turn both into `<div>`s (as PageHeader/SwgCard now are),
 * then delete this waiver. Serious and critical findings are never waived.
 */
const ROUTE_WAIVERS: Array<{ label: RegExp; rule: string }> = [
  { label: /gallery-editor$/, rule: 'landmark-banner-is-top-level' },
  { label: /gallery-editor$/, rule: 'landmark-complementary-is-top-level' },
];
const PREFIX = uniquePrefix('a11y');
let admin: GalleryFixtures;
let gallery: GalleryRecord;
let images: MediaRecord[] = [];

test.beforeAll(async ({ browser, baseURL }) => {
  const context = await browser.newContext({ storageState: authFile('administrator'), baseURL });
  admin = await GalleryFixtures.forPage(await context.newPage());
  images = [
    await admin.uploadImage(`${PREFIX} one`, tinyPng([200, 60, 60], 32)),
    await admin.uploadImage(`${PREFIX} two`, tinyPng([60, 90, 200], 32)),
  ];
  gallery = await admin.create({ title: `${PREFIX} gallery`, status: 'publish' });
  await admin.assign(gallery.id, [images[0]?.id ?? 0]);
});

test.afterAll(async () => {
  await admin.purge(PREFIX);
  await admin.purgeMedia(PREFIX);
});

/**
 * Real contrast of an element inside a Quasar dialog/menu portal: its computed text colour against the first opaque
 * background up its ancestor chain (the dialog card). axe 4.10 cannot see the card of a `q-dialog` in its
 * background stack (it measures against the darkened page behind the backdrop, e.g. white-on-indigo buttons as
 * 3.2:1), so findings of the `color-contrast` rule inside `.swg-portal` are verified with this computation
 * instead of being ignored. Returns `null` when the element is not inside a portal.
 */
async function portalContrast(page: Page, selector: string): Promise<number | null> {
  return page.evaluate((sel) => {
    const el = document.querySelector<HTMLElement>(sel);
    if (el === null || el.closest('.swg-portal') === null) return null;
    const parse = (value: string): [number, number, number, number] => {
      const m = /rgba?\(([^)]+)\)/.exec(value);
      const parts = (m?.[1] ?? '0,0,0,0').split(',').map((x) => Number.parseFloat(x));
      return [parts[0] ?? 0, parts[1] ?? 0, parts[2] ?? 0, parts[3] ?? 1];
    };
    const luminance = ([r, g, b]: [number, number, number, number]): number => {
      const lin = (c: number): number => {
        const v = c / 255;
        return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
      };
      return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
    };
    let background: [number, number, number, number] = [255, 255, 255, 1];
    for (let node: HTMLElement | null = el; node !== null; node = node.parentElement) {
      const bg = parse(getComputedStyle(node).backgroundColor);
      if (bg[3] >= 1) {
        background = bg;
        break;
      }
    }
    const fg = parse(getComputedStyle(el).color);
    const a = luminance(fg);
    const b = luminance(background);
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
  }, selector);
}

/** Every id of the document must be unique (FU-07: Quasar once produced two `#q-notify` containers). */
async function expectUniqueIds(page: Page): Promise<void> {
  const duplicates = await page.evaluate(() => {
    const seen = new Map<string, number>();
    document.querySelectorAll('[id]').forEach((el) => seen.set(el.id, (seen.get(el.id) ?? 0) + 1));
    return [...seen.entries()].filter(([, n]) => n > 1).map(([id, n]) => `${id} x${n}`);
  });
  expect(duplicates, 'duplicate ids in the DOM').toEqual([]);
}

async function sweep(
  page: Page,
  testInfo: TestInfo,
  label: string,
  withPortals = false,
): Promise<void> {
  // Dialogs and sheets fade in: axe would otherwise measure the contrast of half-transparent text.
  await expect(page.locator('[class*="-enter-active"], [class*="-leave-active"]')).toHaveCount(0);
  // Quasar fades the backdrop and the dialog in with plain CSS transitions (no class to wait for): wait until none runs.
  await expect
    .poll(() =>
      page.evaluate(() => document.getAnimations().filter((a) => a.playState === 'running').length),
    )
    .toBe(0);
  const builder = new AxeBuilder({ page })
    .include('#swg-admin-root')
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice']);
  if (withPortals) builder.include('body > .swg-portal');
  const results = await builder.analyze();
  await testInfo.attach(`axe-${label}.json`, {
    body: JSON.stringify(results.violations, null, 2),
    contentType: 'application/json',
  });
  for (const violation of results.violations) {
    console.log(
      `axe ${label} [${violation.impact ?? 'n/a'}] ${violation.id}: ${violation.help} (${violation.nodes.length} node(s)) ${violation.nodes
        .slice(0, 2)
        .map((n) => `${n.target.join(' ')} ${n.html.slice(0, 160)} ${n.any[0]?.message ?? ''}`)
        .join(' | ')}`,
    );
  }
  for (const v of results.violations.filter((x) => x.id in WAIVED)) {
    console.log(`axe WAIVED ${v.id}: ${WAIVED[v.id]}`);
  }
  // axe cannot see the dialog card behind text in a portal (see `portalContrast`): verify those nodes ourselves.
  for (const violation of results.violations.filter((x) => x.id === 'color-contrast')) {
    const real: string[] = [];
    for (const node of violation.nodes) {
      const ratio = await portalContrast(page, node.target.join(' '));
      if (ratio !== null) real.push(`${node.target.join(' ')} real=${ratio.toFixed(2)}`);
      if (ratio !== null && ratio >= 4.5)
        violation.nodes = violation.nodes.filter((n) => n !== node);
    }
    if (real.length > 0)
      console.log(`axe ${label} color-contrast re-checked in portal: ${real.join(' | ')}`);
    if (violation.nodes.length === 0)
      results.violations = results.violations.filter((v) => v !== violation);
  }
  // Moderate and minor findings are listed above too: the gate is "nothing at all except documented waivers".
  const blocking = results.violations.filter(
    (v) =>
      !(v.id in WAIVED) &&
      !ROUTE_WAIVERS.some((w) => w.label.test(label) && w.rule === v.id && v.impact === 'moderate'),
  );
  expect(
    blocking.map((v) => `${label} ${v.impact}: ${v.id}: ${v.help}`),
    `axe violations on ${label}`,
  ).toEqual([]);
  await expectUniqueIds(page);
}

async function open(page: Page, hash: string, ready: string): Promise<void> {
  await page.goto(`${PLUGIN_PAGE}#/${hash}`);
  // A hash-only change does not reload the document: force the route through the router as well.
  await page.evaluate((h) => {
    window.location.hash = `#/${h}`;
  }, hash);
  await expect(page.locator(ready).first()).toBeVisible();
  // let skeletons and fonts settle
  await expect(page.locator('#swg-admin-root [aria-busy="true"]')).toHaveCount(0);
}

for (const theme of ['light', 'dark'] as const) {
  test(`axe: every admin route and overlay has no violations (${theme})`, async ({
    page,
  }, testInfo) => {
    test.setTimeout(120_000);
    await page.addInitScript((value) => {
      window.localStorage.setItem('swg-theme', value);
    }, theme);
    await page.goto(PLUGIN_PAGE);
    await expect(page.locator('#swg-admin-root .swg-shell')).toBeVisible();
    await expect(
      page.locator(`#swg-admin-root${theme === 'dark' ? '.swg-dark' : ':not(.swg-dark)'}`),
    ).toBeVisible();

    const routes: Array<[string, string, string]> = [
      ['overview', '', '.swg-overview .swg-stat'],
      ['galleries', 'galleries', '.swg-galleries [data-gallery-id]'],
      ['gallery-editor', `galleries/${gallery.id}`, '.swg-ed'],
      ['media', 'media', '.swg-media [data-media-id]'],
      ['settings', 'settings', '.swg-settings__form'],
      ['status', 'status', '.swg-status__sections'],
    ];
    for (const [label, hash, ready] of routes) {
      await open(page, hash, ready);
      await sweep(page, testInfo, `${theme}-${label}`);
    }

    // The table views and open overlays (dialogs live in body-level portals).
    await open(page, 'media', '.swg-media [data-media-id]');
    await page.getByRole('radio', { name: 'Table' }).check({ force: true });
    await expect(page.locator('.swg-mtable__table')).toBeVisible();
    await sweep(page, testInfo, `${theme}-media-table`);
    await page
      .getByRole('button', { name: /^Show details of / })
      .first()
      .click();
    await expect(page.locator('.swg-sheet')).toBeVisible();
    await sweep(page, testInfo, `${theme}-media-details`, true);
    await page.getByRole('button', { name: 'Add to gallery' }).last().click();
    await expect(page.locator('.swg-atg__opt').first()).toBeVisible();
    await sweep(page, testInfo, `${theme}-media-add-dialog`, true);

    await open(page, 'settings', '.swg-settings__form');
    await page.locator('.q-toggle', { hasText: 'Delete all plugin data' }).click();
    await expect(page.locator('.swg-cd')).toBeVisible();
    await sweep(page, testInfo, `${theme}-settings-confirm`, true);
  });
}
