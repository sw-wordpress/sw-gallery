import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Browser, Locator, Page } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { authFile, PLUGIN_PAGE, SCREENSHOT_DIR } from '../support/env';
import type { Role } from '../support/env';
import { GalleryFixtures, uniquePrefix } from '../support/galleries';
import { monitorPage } from '../support/monitor';

/**
 * Gallery list (M2-22/M2-23) against the real REST API. Fixtures are created through the REST API with the nonce of
 * the logged-in page (support/galleries.ts) and carry a unique title prefix, so parallel files and repeated runs
 * never interfere; everything is purged again in afterAll.
 */
const PREFIX = uniquePrefix('list');
const MORE = uniquePrefix('paging');
const ROLE_PREFIX = uniquePrefix('roles');
const SEED = uniquePrefix('seed');
const PURGE = [PREFIX, MORE, ROLE_PREFIX, SEED];

/**
 * Read-only fixture set per worker (the file runs in parallel, so no test depends on another one): Alpha, Alpha two
 * (drafts), Beta (published), Secret (private) and Gone (trashed). Tests that change data create their own galleries.
 */
let seeded: Promise<void> | null = null;
function ensureSeed(): Promise<void> {
  seeded ??= (async () => {
    await admin.create({ title: `${SEED} Alpha` });
    await admin.create({ title: `${SEED} Alpha two` });
    await admin.create({ title: `${SEED} Beta`, status: 'publish' });
    await admin.create({ title: `${SEED} Secret`, status: 'private' });
    await admin.trash(await admin.create({ title: `${SEED} Gone` }));
  })();
  return seeded;
}

const root = (page: Page): Locator => page.locator('#swg-admin-root');
const list = (page: Page): Locator => root(page).locator('section[aria-label="Gallery list"]');
const cards = (page: Page): Locator => root(page).locator('.swg-gcard');
const card = (page: Page, title: string): Locator =>
  root(page).locator('.swg-gcard', { hasText: title });
const sheet = (page: Page): Locator => page.getByRole('dialog');
const live = (page: Page): Locator => root(page).locator('.swg-shell > [role="status"]');

async function openList(page: Page, hash = ''): Promise<void> {
  await page.goto(`${PLUGIN_PAGE}#/galleries${hash}`);
  await expect(root(page).getByRole('heading', { level: 2, name: 'Galleries' })).toBeVisible();
  await expect(list(page)).toHaveAttribute('aria-busy', 'false');
}

async function search(page: Page, text: string): Promise<void> {
  await page.getByLabel('Search galleries').fill(text);
  // Debounced (300 ms) and written to the URL.
  await expect(page).toHaveURL(
    new RegExp(`search=${encodeURIComponent(text).replace(/%20/g, '(%20|\\+)')}`),
  );
  await expect(list(page)).toHaveAttribute('aria-busy', 'false');
}

async function setFilter(page: Page, label: string): Promise<void> {
  await root(page)
    .getByRole('group', { name: 'Filter by status' })
    .getByText(label, { exact: true })
    .click();
  await expect(list(page)).toHaveAttribute('aria-busy', 'false');
}

async function rowAction(page: Page, title: string, action: string): Promise<void> {
  await page.getByRole('button', { name: `Actions for gallery ${title}` }).click();
  await page.getByRole('menuitem', { name: action }).click();
}

async function adminFixtures(
  browser: Browser,
  baseURL: string | undefined,
): Promise<GalleryFixtures> {
  const context = await browser.newContext({ storageState: authFile('administrator'), baseURL });
  const page = await context.newPage();
  const fixtures = await GalleryFixtures.forPage(page);
  // The request context stays valid after the page is closed only while the context lives: keep it open for the file.
  return fixtures;
}

async function fixturesAs(browser: Browser, baseURL: string | undefined, role: Role) {
  const context = await browser.newContext({ storageState: authFile(role), baseURL });
  const page = await context.newPage();
  return GalleryFixtures.forPage(page);
}

async function shot(page: Page, name: string): Promise<void> {
  await mkdir(SCREENSHOT_DIR, { recursive: true });
  await page.evaluate(() => document.fonts.ready);
  await page.screenshot({ path: resolve(SCREENSHOT_DIR, `${name}.png`), fullPage: true });
}

let admin: GalleryFixtures;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await adminFixtures(browser, baseURL);
});

test.afterAll(async () => {
  for (const prefix of PURGE) await admin.purge(prefix);
});

test.describe('as administrator', () => {
  test.use({ storageState: authFile('administrator') });

  test('first-run empty state leads to creating the first gallery', async ({ page }) => {
    const title = `${PREFIX} First`;
    let created = false;
    // Deterministic first-run state without touching other data: the list is empty until the gallery was created.
    await page.route(
      (url) => url.href.includes('swg/v1/galleries') || url.search.includes('swg%2Fv1%2Fgalleries'),
      async (route) => {
        const request = route.request();
        if (request.method() === 'POST') created = true;
        if (request.method() === 'GET' && !created) {
          await route.fulfill({
            status: 200,
            contentType: 'application/json',
            headers: { 'X-WP-Total': '0', 'X-WP-TotalPages': '0' },
            body: '[]',
          });
          return;
        }
        await route.continue();
      },
    );
    await openList(page);
    await expect(
      root(page).getByRole('heading', { level: 3, name: 'No galleries yet' }),
    ).toBeVisible();
    await shot(page, 'galleries-empty-light');

    await root(page).getByRole('button', { name: 'Create your first gallery' }).click();
    await expect(sheet(page).getByRole('heading', { name: 'New gallery' })).toBeVisible();
    await expect(sheet(page).getByLabel('Title')).toBeFocused();
    // Title is required.
    await sheet(page).getByRole('button', { name: 'Create gallery' }).click();
    await expect(sheet(page).getByText('Enter a title.')).toBeVisible();
    await sheet(page).getByLabel('Title').fill(title);
    await sheet(page).getByLabel('Description').fill('Created by the E2E suite');
    await sheet(page).getByRole('button', { name: 'Create gallery' }).click();

    await expect(card(page, title)).toBeVisible();
    await expect(card(page, title).getByText('Draft')).toBeVisible();
    await expect(live(page)).toHaveText(`Gallery "${title}" was created as a draft.`);
    await expect(
      page.locator('.q-notification', { hasText: 'was created as a draft' }),
    ).toBeVisible();
    await expect(sheet(page)).toHaveCount(0);
  });

  test('search and status filter use the server and the URL', async ({ page }) => {
    await ensureSeed();

    await openList(page);
    await search(page, SEED);
    // "All" = everything except the trash.
    await expect(cards(page)).toHaveCount(4);

    await setFilter(page, 'Published');
    await expect(page).toHaveURL(/status=publish/);
    await expect(cards(page)).toHaveCount(1);
    await expect(card(page, `${SEED} Beta`).getByText('Published')).toBeVisible();

    await setFilter(page, 'Drafts');
    await expect(cards(page)).toHaveCount(2);
    await setFilter(page, 'Private');
    await expect(cards(page)).toHaveCount(1);
    await expect(card(page, `${SEED} Secret`).getByText('Private', { exact: true })).toBeVisible();
    await setFilter(page, 'Trash');
    await expect(cards(page)).toHaveCount(1);
    await expect(card(page, `${SEED} Gone`).getByText('In trash')).toBeVisible();
    await setFilter(page, 'All');
    await expect(page).not.toHaveURL(/status=/);
    await expect(cards(page)).toHaveCount(4);

    await search(page, `${SEED} Alpha`);
    await expect(cards(page)).toHaveCount(2);
    await search(page, `${SEED} no-such-gallery`);
    await expect(
      root(page).getByRole('heading', { name: 'No galleries match your search' }),
    ).toBeVisible();
    await root(page).getByRole('button', { name: 'Clear search' }).first().click();
    await expect(
      root(page).getByRole('heading', { name: 'No galleries match your search' }),
    ).toHaveCount(0);
    await expect(page).not.toHaveURL(/search=/);
  });

  test('duplicate suggests "<title> (copy)" and creates a draft', async ({ page }) => {
    const source = await admin.create({ title: `${PREFIX} Dup source`, status: 'publish' });
    await openList(page);
    await search(page, `${PREFIX} Dup source`);
    await rowAction(page, source.title, 'Duplicate');
    await expect(sheet(page).getByRole('heading', { name: 'Duplicate gallery' })).toBeVisible();
    await expect(sheet(page).getByLabel('Title of the copy')).toHaveValue(`${source.title} (copy)`);
    await sheet(page).getByRole('button', { name: 'Duplicate' }).click();
    const copy = card(page, `${source.title} (copy)`);
    await expect(copy).toBeVisible();
    await expect(copy.getByText('Draft')).toBeVisible();
    await expect(live(page)).toContainText('was created as a copy');
  });

  test('trash names the gallery and keeps images; restore returns it as a draft', async ({
    page,
  }) => {
    const gallery = await admin.create({ title: `${PREFIX} To trash`, status: 'publish' });
    await openList(page);
    await search(page, gallery.title);
    await rowAction(page, gallery.title, 'Move to trash');

    const dialog = page.getByRole('alertdialog');
    await expect(
      dialog.getByRole('heading', { name: 'Move this gallery to the trash?' }),
    ).toBeVisible();
    await expect(dialog.getByText(gallery.title, { exact: true })).toBeVisible();
    await expect(dialog.getByText('Its images stay in the Media Library.')).toBeVisible();
    await shot(page, 'galleries-trash-confirm');
    await expect(dialog.getByRole('button', { name: 'Cancel' })).toBeFocused();
    await dialog.getByRole('button', { name: 'Move to trash' }).click();
    await expect(card(page, gallery.title)).toHaveCount(0);
    await expect(live(page)).toHaveText(`Gallery "${gallery.title}" was moved to the trash.`);

    await setFilter(page, 'Trash');
    await expect(card(page, gallery.title).getByText('In trash')).toBeVisible();
    await rowAction(page, gallery.title, 'Restore as draft');
    await expect(card(page, gallery.title)).toHaveCount(0);
    await expect(live(page)).toHaveText(`Gallery "${gallery.title}" was restored as a draft.`);
    await setFilter(page, 'All');
    await expect(card(page, gallery.title).getByText('Draft')).toBeVisible();
  });

  test('delete permanently needs the typed title and never touches images', async ({ page }) => {
    const gallery = await admin.create({ title: `${PREFIX} To delete` });
    await admin.trash(gallery);
    await openList(page, '?status=trash');
    await search(page, gallery.title);
    await rowAction(page, gallery.title, 'Delete permanently');

    const dialog = page.getByRole('alertdialog');
    await expect(
      dialog.getByText('The images themselves are never deleted and stay in the Media Library.'),
    ).toBeVisible();
    const confirm = dialog.getByRole('button', { name: 'Delete permanently' });
    await expect(confirm).toBeDisabled();
    await dialog.getByRole('textbox').fill(gallery.title.slice(0, -1));
    await expect(confirm).toBeDisabled();
    await dialog.getByRole('textbox').fill(gallery.title);
    await expect(confirm).toBeEnabled();
    await confirm.click();
    await expect(card(page, gallery.title)).toHaveCount(0);
    await expect(live(page)).toHaveText(`Gallery "${gallery.title}" was deleted permanently.`);
    expect((await admin.get(gallery.id)).status()).toBe(404);
  });

  test('copy shortcode writes [sw_gallery id="N"] to the clipboard', async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    const gallery = await admin.create({ title: `${PREFIX} Shortcode` });
    await openList(page);
    await search(page, gallery.title);
    await rowAction(page, gallery.title, 'Copy shortcode');
    const shortcode = `[sw_gallery id="${gallery.id}"]`;
    await expect(live(page)).toHaveText(`Shortcode ${shortcode} copied to the clipboard.`);
    await expect(page.locator('.q-notification', { hasText: shortcode })).toBeVisible();
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(shortcode);
  });

  test('copy shortcode shows a selected fallback when the Clipboard API is unavailable', async ({
    page,
  }) => {
    await page.addInitScript(() => {
      Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
    });
    const gallery = await admin.create({ title: `${PREFIX} Fallback` });
    await openList(page);
    await search(page, gallery.title);
    await rowAction(page, gallery.title, 'Copy shortcode');
    const dialog = page.getByRole('dialog', { name: 'Copy shortcode' });
    const input = dialog.getByLabel('Shortcode');
    await expect(input).toHaveValue(`[sw_gallery id="${gallery.id}"]`);
    await expect(input).toBeFocused();
    const selected = await input.evaluate((el: HTMLInputElement) =>
      el.value.slice(el.selectionStart ?? 0, el.selectionEnd ?? 0),
    );
    expect(selected).toBe(`[sw_gallery id="${gallery.id}"]`);
    await dialog.getByRole('button', { name: 'Close' }).click();
    await expect(dialog).toHaveCount(0);
  });

  test('edit details uses the revision: a change elsewhere is reported and the input is kept', async ({
    page,
  }) => {
    const gallery = await admin.create({ title: `${PREFIX} Conflict` });
    await openList(page);
    await search(page, gallery.title);
    await rowAction(page, gallery.title, 'Edit details');
    await expect(sheet(page).getByLabel('Title')).toHaveValue(gallery.title);

    // Someone else saves in the meantime (second client, same user): the sheet's revision is now stale.
    expect(
      await admin.update(gallery.id, gallery.revision, { description: 'edited elsewhere' }),
    ).toBe(200);

    await sheet(page).getByLabel('Title').fill(`${gallery.title} mine`);
    await sheet(page).getByRole('button', { name: 'Save changes' }).click();
    await expect(sheet(page).getByRole('alert')).toContainText('changed elsewhere');
    await expect(sheet(page).getByLabel('Title')).toHaveValue(`${gallery.title} mine`);
    // Retry with the fresh revision succeeds.
    await sheet(page).getByRole('button', { name: 'Save changes' }).click();
    await expect(card(page, `${gallery.title} mine`)).toBeVisible();
    await expect(sheet(page)).toHaveCount(0);
  });

  test('the list renders without console errors or failed requests', async ({ page }) => {
    await ensureSeed();
    const monitor = monitorPage(page);
    await openList(page);
    await search(page, SEED);
    await page.waitForLoadState('networkidle');
    expect(monitor.problems, 'console errors/warnings').toEqual([]);
    expect(monitor.failedRequests, 'failed requests').toEqual([]);
  });

  test('a deep link restores status, search and page after a reload', async ({ page }) => {
    await ensureSeed();
    await page.goto(`${PLUGIN_PAGE}#/galleries?status=trash&search=${encodeURIComponent(SEED)}`);
    await expect(list(page)).toHaveAttribute('aria-busy', 'false');
    const trash = root(page)
      .getByRole('group', { name: 'Filter by status' })
      .getByRole('radio', { name: 'Trash' });
    await expect(trash).toBeChecked();
    await expect(page.getByLabel('Search galleries')).toHaveValue(SEED);
    await expect(card(page, `${SEED} Gone`)).toBeVisible();
    await page.reload();
    await expect(list(page)).toHaveAttribute('aria-busy', 'false');
    await expect(trash).toBeChecked();
    await expect(card(page, `${SEED} Gone`)).toBeVisible();
  });

  test('the view choice (grid or table) is remembered', async ({ page }) => {
    await ensureSeed();
    await openList(page);
    await search(page, SEED);
    await expect(root(page).locator('ul.swg-ggrid')).toBeVisible();
    await root(page)
      .getByRole('group', { name: 'View' })
      .getByText('Table', { exact: true })
      .click();
    const table = root(page).getByRole('table', { name: 'Galleries' });
    await expect(table).toBeVisible();
    await expect(table.getByRole('columnheader')).toHaveCount(6);
    await expect(
      table.getByRole('rowheader', { name: `${SEED} Alpha`, exact: true }),
    ).toBeVisible();
    await page.reload();
    await expect(root(page).getByRole('table', { name: 'Galleries' })).toBeVisible();
  });
});

test.describe('paging', () => {
  test.use({ storageState: authFile('administrator') });

  test('25 galleries are served in pages of 20 and the page is in the URL', async ({ page }) => {
    await admin.createMany(MORE, 25);
    await openList(page);
    await search(page, MORE);
    const pager = root(page).getByRole('navigation', { name: 'Gallery pages' });
    await expect(cards(page)).toHaveCount(20);
    await expect(pager).toContainText('25 galleries');
    await expect(pager).toContainText('Page 1 of 2');
    await expect(pager.getByRole('button', { name: 'Previous page' })).toBeDisabled();

    await pager.getByRole('button', { name: 'Next page' }).click();
    await expect(page).toHaveURL(/page=2/);
    await expect(cards(page)).toHaveCount(5);
    await expect(pager).toContainText('Page 2 of 2');
    await expect(pager.getByRole('button', { name: 'Next page' })).toBeDisabled();

    await page.reload();
    await expect(list(page)).toHaveAttribute('aria-busy', 'false');
    await expect(cards(page)).toHaveCount(5);

    await pager.getByRole('button', { name: 'Previous page' }).click();
    await expect(page).not.toHaveURL(/#.*[?&]page=/);
    await expect(cards(page)).toHaveCount(20);

    // A page past the end steps back to the last page.
    await page.goto(`${PLUGIN_PAGE}#/galleries?page=9&search=${encodeURIComponent(MORE)}`);
    await expect(page).toHaveURL(/page=2/);
    await expect(cards(page)).toHaveCount(5);
  });
});

test.describe('roles', () => {
  test('an author sees only own galleries, an editor sees all', async ({ browser, baseURL }) => {
    const author = await fixturesAs(browser, baseURL, 'author');
    await admin.create({ title: `${ROLE_PREFIX} by admin` });
    await author.create({ title: `${ROLE_PREFIX} by author` });

    for (const [role, expected] of [
      ['author', [`${ROLE_PREFIX} by author`]],
      ['editor', [`${ROLE_PREFIX} by admin`, `${ROLE_PREFIX} by author`]],
    ] as const) {
      const context = await browser.newContext({ storageState: authFile(role), baseURL });
      const page = await context.newPage();
      await openList(page);
      await search(page, ROLE_PREFIX);
      await expect(cards(page)).toHaveCount(expected.length);
      for (const title of expected) await expect(card(page, title)).toBeVisible();
      await context.close();
    }
  });

  test('a contributor cannot publish: the status options are disabled', async ({
    browser,
    baseURL,
  }) => {
    const contributor = await fixturesAs(browser, baseURL, 'contributor');
    const gallery = await contributor.create({ title: `${ROLE_PREFIX} contributor draft` });
    const context = await browser.newContext({ storageState: authFile('contributor'), baseURL });
    const page = await context.newPage();
    await openList(page);
    await search(page, gallery.title);
    await rowAction(page, gallery.title, 'Edit details');
    await sheet(page).getByLabel('Status').click();
    await expect(page.getByRole('option', { name: /^Draft/ })).not.toHaveAttribute(
      'aria-disabled',
      'true',
    );
    await expect(page.getByRole('option', { name: /^Pending review/ })).not.toHaveAttribute(
      'aria-disabled',
      'true',
    );
    await expect(page.getByRole('option', { name: /^Published/ })).toHaveAttribute(
      'aria-disabled',
      'true',
    );
    await expect(page.getByRole('option', { name: /^Private/ })).toHaveAttribute(
      'aria-disabled',
      'true',
    );
    await context.close();
  });

  test('a contributor sees only own galleries', async ({ browser, baseURL }) => {
    const own = uniquePrefix('contrib');
    PURGE.push(own);
    const contributor = await fixturesAs(browser, baseURL, 'contributor');
    await contributor.create({ title: `${own} mine` });
    await admin.create({ title: `${own} foreign` });
    const context = await browser.newContext({ storageState: authFile('contributor'), baseURL });
    const page = await context.newPage();
    await openList(page);
    await search(page, own);
    await expect(card(page, `${own} foreign`)).toHaveCount(0);
    await expect(card(page, `${own} mine`)).toBeVisible();
    await context.close();
  });

  test('a subscriber has no access to the screen', async ({ browser, baseURL }) => {
    const context = await browser.newContext({ storageState: authFile('subscriber'), baseURL });
    const page = await context.newPage();
    await page.goto('/wp-admin/');
    await expect(page.locator('#adminmenu').getByRole('link', { name: 'Galleries' })).toHaveCount(
      0,
    );
    const response = await page.goto(`${PLUGIN_PAGE}#/galleries`);
    expect(response?.status()).toBe(403);
    await expect(page.locator('#swg-admin-root')).toHaveCount(0);
    await context.close();
  });
});

test.describe('accessibility and screenshots', () => {
  test.use({ storageState: authFile('administrator') });

  async function seededList(page: Page): Promise<void> {
    await ensureSeed();
    await openList(page);
    await search(page, SEED);
    await expect(cards(page).first()).toBeVisible();
  }

  async function axe(page: Page, include: string, skipContrast = false): Promise<string[]> {
    const builder = new AxeBuilder({ page }).include(include);
    if (skipContrast) builder.disableRules('color-contrast');
    const results = await builder
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'])
      .analyze();
    for (const v of results.violations) {
      console.log(
        `axe [${v.impact ?? 'n/a'}] ${v.id}: ${v.help} (${v.nodes.length} node(s)) in ${include}: ${v.nodes.map((n) => n.target.join(' ') + ' ' + (n.any[0]?.message ?? '') + ' ' + n.html.slice(0, 240)).join(' | ')}`,
      );
    }
    return results.violations
      .filter((v) => v.impact === 'serious' || v.impact === 'critical')
      .map((v) => `${v.id}: ${v.help}`);
  }

  test('axe: the list (grid and table, light and dark) has no serious or critical violations', async ({
    page,
  }) => {
    await seededList(page);
    expect(await axe(page, '#swg-admin-root'), 'grid, light').toEqual([]);
    await shot(page, 'galleries-grid-light');

    await root(page)
      .getByRole('group', { name: 'View' })
      .getByText('Table', { exact: true })
      .click();
    await expect(root(page).getByRole('table')).toBeVisible();
    expect(await axe(page, '#swg-admin-root'), 'table, light').toEqual([]);
    await shot(page, 'galleries-table-light');

    await page.getByRole('button', { name: 'Dark mode' }).click();
    await expect(root(page)).toHaveClass(/swg-dark/);
    expect(await axe(page, '#swg-admin-root'), 'table, dark').toEqual([]);
    await shot(page, 'galleries-table-dark');

    await root(page)
      .getByRole('group', { name: 'View' })
      .getByText('Grid', { exact: true })
      .click();
    await expect(root(page).locator('ul.swg-ggrid')).toBeVisible();
    expect(await axe(page, '#swg-admin-root'), 'grid, dark').toEqual([]);
    await shot(page, 'galleries-grid-dark');
  });

  test('axe: the row menu and the create sheet have no serious or critical violations', async ({
    page,
  }) => {
    await seededList(page);
    await page
      .getByRole('button', { name: `Actions for gallery ${SEED} Alpha`, exact: true })
      .click();
    await expect(page.getByRole('menuitem', { name: 'Duplicate' })).toBeVisible();
    expect(await axe(page, '.q-menu'), 'row menu').toEqual([]);
    await page.keyboard.press('Escape');
    await root(page).getByRole('button', { name: 'New gallery' }).click();
    await expect(sheet(page).getByRole('heading', { name: 'New gallery' })).toBeVisible();
    // Let the slide-in transition finish: axe samples colours at the element's position.
    const card = page.locator('.swg-sheet');
    await expect
      .poll(async () => {
        const box = await card.boundingBox();
        return box === null ? -1 : Math.round(box.x + box.width);
      })
      .toBe(page.viewportSize()?.width);
    await expect(page.locator('.q-dialog__inner')).toHaveCSS('opacity', '1');
    await expect(page.locator('.q-dialog__backdrop')).toHaveCSS('opacity', '1');
    // axe misreads the colour of a fixed-position dialog button as the dimmed page behind it (it reports #8c919c, the
    // backdrop over the page, for a button that is solid indigo): the contrast rule is replaced by an explicit
    // check of the real computed colours for this scan only. White on #4f46e5 is 6.3:1.
    const submit = sheet(page).getByRole('button', { name: 'Create gallery' });
    await expect(submit).toHaveCSS('background-color', 'rgb(79, 70, 229)');
    await expect(submit).toHaveCSS('color', 'rgb(255, 255, 255)');
    expect(await axe(page, '.q-dialog', true), 'create sheet').toEqual([]);
    await shot(page, 'galleries-create-sheet');
  });

  test('keyboard: the row menu opens with Enter, arrows move, Escape returns focus', async ({
    page,
  }) => {
    await seededList(page);
    const trigger = page.getByRole('button', {
      name: `Actions for gallery ${SEED} Alpha`,
      exact: true,
    });
    await trigger.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('menuitem', { name: 'Edit details' })).toBeVisible();
    await page.getByRole('menuitem', { name: 'Edit details' }).focus();
    await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('menuitem', { name: 'Duplicate' })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(page.locator('.q-menu')).toHaveCount(0);
    await expect(trigger).toBeFocused();
  });

  test('the Quasar portals of the list take no part in the page layout', async ({ page }) => {
    await seededList(page);
    await page
      .getByRole('button', { name: `Actions for gallery ${SEED} Alpha`, exact: true })
      .click();
    await expect(page.getByRole('menuitem', { name: 'Duplicate' })).toBeVisible();
    await page.keyboard.press('Escape');
    const heights = await page.evaluate(() => ({
      doc: document.documentElement.scrollHeight,
      portals: [...document.querySelectorAll<HTMLElement>('body > .swg-root')].map((el) =>
        Math.round(el.getBoundingClientRect().height),
      ),
    }));
    expect(heights.portals.length).toBeGreaterThan(0);
    for (const height of heights.portals) expect(height).toBeLessThanOrEqual(1);
  });
});

test.describe('mobile', () => {
  test.use({ storageState: authFile('administrator'), viewport: { width: 375, height: 800 } });

  test('the list fits a 375 px viewport without horizontal scrolling', async ({ page }) => {
    await ensureSeed();
    await openList(page);
    await search(page, SEED);
    await expect(cards(page).first()).toBeVisible();
    const overflowing = (): Promise<string[]> =>
      page.evaluate(() => {
        const width = document.documentElement.clientWidth;
        return [...document.querySelectorAll<HTMLElement>('#swg-admin-root *')]
          .filter(
            (el) =>
              el.getBoundingClientRect().right > width + 1 && !el.closest('.swg-gtable__scroll'),
          )
          .map(
            (el) =>
              `${el.tagName.toLowerCase()}.${el.className} right=${Math.round(el.getBoundingClientRect().right)}`,
          )
          .slice(0, 12)
          .concat([
            `scroller overflow-x=${getComputedStyle(document.querySelector('.swg-gtable__scroll') ?? document.body).overflowX} client=${document.querySelector('.swg-gtable__scroll')?.clientWidth}`,
          ]);
      });
    const scrollOverflow = (): Promise<number> =>
      page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
      );
    expect(await scrollOverflow(), (await overflowing()).join('\n')).toBeLessThanOrEqual(0);
    await shot(page, 'galleries-mobile');
    await root(page)
      .getByRole('group', { name: 'View' })
      .getByText('Table', { exact: true })
      .click();
    await expect(root(page).getByRole('table')).toBeVisible();
    await shot(page, 'galleries-mobile-table');
    expect(await scrollOverflow(), (await overflowing()).join('\n')).toBeLessThanOrEqual(0);
  });
});

test.describe('German', () => {
  test.use({ storageState: authFile('german') });

  test('the list is translated and dates follow the locale', async ({ page, browser, baseURL }) => {
    const german = await fixturesAs(browser, baseURL, 'german');
    const gallery = await german.create({ title: `${ROLE_PREFIX} Sommer` });
    await page.goto(`${PLUGIN_PAGE}#/galleries`);
    await expect(root(page).getByRole('heading', { level: 2, name: 'Galerien' })).toBeVisible();
    await page.getByLabel('Galerien durchsuchen').fill(gallery.title);
    await expect(card(page, gallery.title)).toBeVisible();
    await expect(card(page, gallery.title).getByText('Entwurf')).toBeVisible();
    await expect(
      card(page, gallery.title).getByText(/Geändert \d{1,2}\.\d{1,2}\.\d{4}/),
    ).toBeVisible();
    await expect(root(page).getByRole('button', { name: 'Neue Galerie' })).toBeVisible();
    await expect(
      root(page).getByRole('group', { name: 'Nach Status filtern' }).getByText('Papierkorb'),
    ).toBeVisible();
    await shot(page, 'galleries-german');
  });
});

test('dark mode of the first-run empty state is readable (screenshot)', async ({
  browser,
  baseURL,
}) => {
  const context = await browser.newContext({ storageState: authFile('administrator'), baseURL });
  const page = await context.newPage();
  await page.route(
    (url) => url.href.includes('swg/v1/galleries') || url.search.includes('swg%2Fv1%2Fgalleries'),
    (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        headers: { 'X-WP-Total': '0', 'X-WP-TotalPages': '0' },
        body: '[]',
      }),
  );
  await openList(page);
  await page.getByRole('button', { name: 'Dark mode' }).click();
  await expect(root(page)).toHaveClass(/swg-dark/);
  await expect(root(page).getByRole('heading', { name: 'No galleries yet' })).toBeVisible();
  const violations = await new AxeBuilder({ page })
    .include('#swg-admin-root')
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'best-practice'])
    .analyze();
  expect(
    violations.violations
      .filter((v) => v.impact === 'serious' || v.impact === 'critical')
      .map((v) => `${v.id}: ${v.help}`),
  ).toEqual([]);
  await shot(page, 'galleries-empty-dark');
  await context.close();
});
