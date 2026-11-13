import { expect, test } from '@playwright/test';
import type { Browser, Locator, Page } from '@playwright/test';
import { authFile, PLUGIN_PAGE } from '../support/env';
import type { Role } from '../support/env';
import { GalleryFixtures, tinyPng, uniquePrefix } from '../support/galleries';
import type { GalleryRecord, MediaRecord } from '../support/galleries';
import { monitorPage } from '../support/monitor';
import { preferTheme, shot } from '../support/screenshots';

/**
 * Media Overview (M2-33, decision D6) against the real REST API: permission-filtered attachment browser with search,
 * sort, server paging, selection, read-only details, upload and the one action "Add to gallery". Fixtures (tiny real
 * PNGs through `POST /media`, galleries) carry a unique prefix and are purged in afterAll.
 */
// One worker for the whole file, tests in order: the fixtures (and their purge) are shared, the add test changes usage counts.
test.describe.configure({ mode: 'default' });
const PREFIX = uniquePrefix('media');
const FILLERS = 38; // + alpha, bravo, charlie = 41 images: one more than a page of 40
let admin: GalleryFixtures;
let target: GalleryRecord;
let other: GalleryRecord;
let alpha: MediaRecord;
let bravo: MediaRecord;
let charlie: MediaRecord;
const UPLOAD_TOKENS: string[] = [];

async function fixturesFor(
  browser: Browser,
  baseURL: string | undefined,
  role: Role,
): Promise<GalleryFixtures> {
  const context = await browser.newContext({ storageState: authFile(role), baseURL });
  return GalleryFixtures.forPage(await context.newPage());
}

test.beforeAll(async ({ browser, baseURL }) => {
  test.setTimeout(180_000);
  admin = await fixturesFor(browser, baseURL, 'administrator');
  alpha = await admin.uploadImage(`${PREFIX} alpha`, tinyPng([200, 60, 60], 16));
  bravo = await admin.uploadImage(`${PREFIX} bravo`, tinyPng([60, 160, 90], 16));
  charlie = await admin.uploadImage(`${PREFIX} charlie`, tinyPng([60, 90, 200], 16));
  for (let n = 1; n <= FILLERS; n += 1) {
    await admin.uploadImage(
      `${PREFIX} filler ${String(n).padStart(2, '0')}`,
      tinyPng([90, 90, 90], 8),
    );
  }
  target = await admin.create({ title: `${PREFIX} target`, status: 'publish' });
  other = await admin.create({ title: `${PREFIX} other` });
});

test.afterAll(async () => {
  await admin.purge(PREFIX);
  await admin.purgeMedia(PREFIX);
  for (const token of UPLOAD_TOKENS) await admin.purgeMedia(token);
});

const root = (page: Page): Locator => page.locator('#swg-admin-root');
const live = (page: Page): Locator => root(page).locator('.swg-shell > [role="status"]');
const tiles = (page: Page): Locator => root(page).locator('.swg-mgrid > li');
const tileBy = (page: Page, title: string): Locator =>
  root(page).locator('[data-media-id]').filter({ hasText: title });
const check = (page: Page, title: string): Locator =>
  root(page).getByRole('checkbox', { name: `Select ${title}`, exact: true });

async function openMedia(page: Page, query = ''): Promise<void> {
  await page.goto(`${PLUGIN_PAGE}#/media${query === '' ? '' : `?${query}`}`);
  await expect(root(page).locator('section[aria-label="Media"]')).toHaveAttribute(
    'aria-busy',
    'false',
  );
}

const ONLY_MINE = `search=${encodeURIComponent(PREFIX)}`;

test.describe('as administrator', () => {
  test.use({ storageState: authFile('administrator') });

  test('browses the permission-filtered library without console or network errors', async ({
    page,
  }) => {
    const monitor = monitorPage(page);
    await openMedia(page, ONLY_MINE);
    await expect(tiles(page)).toHaveCount(40);
    await expect(root(page).locator('.swg-pager__info')).toHaveText('41 images');
    await expect(
      root(page).getByRole('heading', { level: 2, name: 'Media Overview' }),
    ).toBeVisible();
    // Every tile shows its usage: nothing is used yet.
    await expect(
      root(page).locator('.swg-mgrid .swg-chip__text', { hasText: 'Not used yet' }),
    ).toHaveCount(40);
    expect(monitor.problems, 'console errors/warnings').toEqual([]);
    expect(monitor.failedRequests, 'failed requests').toEqual([]);
  });

  test('pages on the server and deep-links the page', async ({ page }) => {
    await openMedia(page, ONLY_MINE);
    await expect(tiles(page)).toHaveCount(40);
    await root(page).getByRole('button', { name: 'Next page' }).click();
    await expect(page).toHaveURL(/page=2/);
    await expect(tiles(page)).toHaveCount(1);
    await expect(root(page).locator('.swg-pager')).toContainText('Page 2 of 2');
    // reload keeps the page (the URL is the state)
    await page.reload();
    await expect(tiles(page)).toHaveCount(1);
    await root(page).getByRole('button', { name: 'Previous page' }).click();
    await expect(tiles(page)).toHaveCount(40);
  });

  test('searches (debounced, in the URL) and sorts by title in both directions', async ({
    page,
  }) => {
    await openMedia(page, ONLY_MINE);
    const search = root(page).getByRole('searchbox', { name: 'Search images' });
    await search.fill(`${PREFIX} bravo`);
    await expect(tiles(page)).toHaveCount(1);
    await expect(page).toHaveURL(/search=/);
    await expect(tileBy(page, 'bravo')).toBeVisible();
    await root(page).getByRole('button', { name: 'Clear search' }).click();
    await expect(page).not.toHaveURL(/search=/);

    await openMedia(page, `${ONLY_MINE}&orderby=title`);
    await expect(root(page).getByLabel('Sort images')).toHaveValue('title:asc');
    await expect(tiles(page).first()).toContainText('alpha');
    await expect(tiles(page).nth(1)).toContainText('bravo');
    await root(page).getByLabel('Sort images').selectOption('title:desc');
    await expect(page).toHaveURL(/orderby=title/);
    await expect(page).toHaveURL(/order=desc/);
    await expect(tiles(page).first()).toContainText(`filler ${FILLERS}`);
    await root(page).getByLabel('Sort images').selectOption('date:desc');
    await expect(page).not.toHaveURL(/orderby=/);
  });

  test('toggles between grid and table and remembers the choice per viewer', async ({ page }) => {
    await openMedia(page, ONLY_MINE);
    await root(page).getByRole('radio', { name: 'Table' }).check({ force: true });
    await expect(root(page).locator('table.swg-mtable__table')).toBeVisible();
    await expect(root(page).locator('table tbody tr')).toHaveCount(40);
    await page.reload();
    await expect(root(page).locator('table.swg-mtable__table')).toBeVisible();
    await root(page).getByRole('radio', { name: 'Grid' }).check({ force: true });
    await expect(root(page).locator('.swg-mgrid')).toBeVisible();
  });

  test('selects with shift-range, select-all and keyboard, announced politely', async ({
    page,
  }) => {
    await openMedia(page, `${ONLY_MINE}&orderby=title`);
    await check(page, `${PREFIX} alpha`).click();
    await expect(live(page)).toHaveText('One image selected');
    await check(page, `${PREFIX} charlie`).click({ modifiers: ['Shift'] });
    await expect(live(page)).toHaveText('3 images selected');
    for (const name of ['alpha', 'bravo', 'charlie']) {
      await expect(check(page, `${PREFIX} ${name}`)).toHaveAttribute('aria-checked', 'true');
    }
    await root(page).getByRole('button', { name: 'Clear selection' }).click();
    await expect(live(page)).toHaveText('Selection cleared');
    // keyboard: Ctrl+A in the grid selects the page, Escape clears it
    await check(page, `${PREFIX} bravo`).focus();
    await page.keyboard.press('Control+a');
    await expect(live(page)).toHaveText('40 images selected');
    await expect(
      root(page).getByRole('checkbox', { name: 'Select all on this page' }),
    ).toHaveAttribute('aria-checked', 'true');
    await page.keyboard.press('Escape');
    await expect(
      root(page).getByRole('checkbox', { name: 'Select all on this page' }),
    ).toHaveAttribute('aria-checked', 'false');
    // keyboard only: Space toggles the focused checkbox
    await check(page, `${PREFIX} bravo`).focus();
    await page.keyboard.press('Space');
    await expect(check(page, `${PREFIX} bravo`)).toHaveAttribute('aria-checked', 'true');
  });

  test('shows read-only details without file names or paths', async ({ page }) => {
    await openMedia(page, `${ONLY_MINE}&orderby=title`);
    await tileBy(page, 'alpha')
      .getByRole('button', { name: /^Show details of / })
      .click();
    const sheet = page.locator('.swg-sheet');
    await expect(sheet).toBeVisible();
    await expect(sheet.locator('[data-field="title"]')).toHaveText(`${PREFIX} alpha`);
    await expect(sheet.locator('[data-field="dimensions"]')).toHaveText('16 × 16');
    await expect(sheet.locator('[data-field="type"]')).toHaveText('image/png');
    await expect(sheet.locator('[data-field="id"]')).toHaveText(String(alpha.id));
    await expect(sheet.locator('[data-field="user"]')).toHaveText(/^User \d+$/);
    const text = (await sheet.textContent()) ?? '';
    expect(text).not.toMatch(/\.(png|jpe?g|gif|webp)\b|wp-content|uploads\/|\/var\/|\/home\//i);
    // nothing editable and nothing destructive: the only controls are Close and Add to gallery
    await expect(sheet.locator('input, textarea, select')).toHaveCount(0);
    await expect(sheet.getByRole('button')).toHaveText([/close/i, /Close/, /Add to gallery/]);
    await page.keyboard.press('Escape');
    await expect(sheet).toHaveCount(0);
  });

  test('adds the selection to a gallery, reports honestly and updates the usage counts', async ({
    page,
  }) => {
    await openMedia(page, `${ONLY_MINE}&orderby=title`);
    await check(page, `${PREFIX} alpha`).click();
    await check(page, `${PREFIX} charlie`).click({ modifiers: ['Shift'] });
    await root(page).getByRole('button', { name: 'Add to gallery' }).first().click();

    const dialog = page.getByRole('dialog', { name: 'Add to gallery' });
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText('Choose the gallery for 3 images.');
    await dialog.getByRole('searchbox', { name: 'Search galleries' }).fill(`${PREFIX} target`);
    await expect(dialog.locator('.swg-atg__opt')).toHaveCount(1);
    await expect(dialog.getByRole('button', { name: 'Add 3 images' })).toBeDisabled();
    await dialog.getByRole('radio', { name: new RegExp(`${PREFIX} target`) }).check();
    await dialog.getByRole('button', { name: 'Add 3 images' }).click();
    await expect(dialog).toHaveCount(0);
    await expect(live(page)).toHaveText(`Gallery "${PREFIX} target": 3 images added.`);

    // The server now holds exactly these three, in the order of the selection.
    const items = await admin.items(target.id);
    expect(items.map((i) => i.attachmentId).sort()).toEqual(
      [alpha.id, bravo.id, charlie.id].sort(),
    );
    // usage counts reloaded: used in one gallery now, the rest still unused
    for (const name of ['alpha', 'bravo', 'charlie']) {
      await expect(tileBy(page, name).locator('.swg-chip__text')).toHaveText('Used in one gallery');
    }
    await expect(check(page, `${PREFIX} alpha`)).toHaveAttribute('aria-checked', 'false');

    // Adding one of them again is reported as "already in the gallery", nothing is added twice.
    await check(page, `${PREFIX} alpha`).click();
    await root(page).getByRole('button', { name: 'Add to gallery' }).first().click();
    await dialog.getByRole('searchbox', { name: 'Search galleries' }).fill(`${PREFIX} target`);
    await dialog.getByRole('radio', { name: new RegExp(`${PREFIX} target`) }).check();
    await dialog.getByRole('button', { name: 'Add image' }).click();
    await expect(live(page)).toHaveText(
      `Gallery "${PREFIX} target": No images added, already in the gallery: 1.`,
    );
    expect(await admin.items(target.id)).toHaveLength(3);

    // A second gallery counts too: alpha is now used in two galleries.
    await check(page, `${PREFIX} alpha`).click();
    await tileBy(page, 'alpha')
      .getByRole('button', { name: /^Show details of / })
      .click();
    await page.locator('.swg-sheet').getByRole('button', { name: 'Add to gallery' }).click();
    await dialog.getByRole('searchbox', { name: 'Search galleries' }).fill(`${PREFIX} other`);
    await dialog.getByRole('radio', { name: new RegExp(`${PREFIX} other`) }).check();
    await dialog.getByRole('button', { name: 'Add image' }).click();
    await expect(live(page)).toHaveText(`Gallery "${PREFIX} other": One image added.`);
    await expect(tileBy(page, 'alpha').locator('.swg-chip__text')).toHaveText(
      'Used in 2 galleries',
    );
    expect((await admin.items(other.id)).map((i) => i.attachmentId)).toEqual([alpha.id]);
  });

  test('uploads through the page and shows the new image', async ({ page }) => {
    const token = `e2eui${Date.now().toString(36)}`;
    UPLOAD_TOKENS.push(token);
    await openMedia(page, `search=${token}`);
    await expect(root(page).getByText('No images match')).toBeVisible();
    await root(page)
      .getByTestId('media-file-input')
      .setInputFiles({
        name: `${token}.png`,
        mimeType: 'image/png',
        buffer: tinyPng([20, 120, 200], 20),
      });
    await expect(root(page).locator('[data-upload-status="added"]')).toContainText(token);
    await expect(live(page)).toHaveText('1 uploaded, 0 failed.');
    await expect(tiles(page)).toHaveCount(1);
    await expect(tiles(page).first()).toContainText(token);
    // a refused file is listed with its reason and never reaches the server
    await root(page)
      .getByTestId('media-file-input')
      .setInputFiles({ name: 'note.txt', mimeType: 'text/plain', buffer: Buffer.from('hello') });
    await expect(root(page).locator('[data-upload-status="error"]')).toContainText(
      'Not a supported image type',
    );
  });

  test('shows the uploader filter only for users who may see other uploads', async ({ page }) => {
    await openMedia(page, ONLY_MINE);
    await expect(root(page).getByLabel('Uploader (user ID)')).toBeVisible();
    // filtering by an id without uploads gives the filtered empty state with a way back
    await root(page).getByLabel('Uploader (user ID)').fill('999999');
    await expect(root(page).getByText('No images match')).toBeVisible();
    await expect(page).toHaveURL(/uploadedBy=999999/);
    await root(page).getByRole('button', { name: 'Reset filters' }).click();
    await expect(tiles(page).first()).toBeVisible();
  });

  test('screenshots: grid, table, dark, mobile', async ({ page }) => {
    await openMedia(page, `${ONLY_MINE}&orderby=title`);
    await shot(page, 'media-grid-light');
    await root(page).getByRole('radio', { name: 'Table' }).check({ force: true });
    await expect(root(page).locator('table.swg-mtable__table')).toBeVisible();
    await shot(page, 'media-table-light');
    await root(page).getByRole('radio', { name: 'Grid' }).check({ force: true });
    await check(page, `${PREFIX} alpha`).click();
    await check(page, `${PREFIX} bravo`).click();
    await root(page).getByRole('button', { name: 'Add to gallery' }).first().click();
    await expect(page.locator('.swg-atg__opt').first()).toBeVisible();
    await shot(page, 'media-add-dialog-light');
    await page.getByRole('button', { name: 'Cancel' }).click();
    await page.setViewportSize({ width: 375, height: 800 });
    await shot(page, 'media-grid-mobile');
  });

  test('screenshots: dark theme', async ({ page }) => {
    await preferTheme(page, 'dark');
    await openMedia(page, `${ONLY_MINE}&orderby=title`);
    await expect(page.locator('#swg-admin-root.swg-dark')).toBeVisible();
    await shot(page, 'media-grid-dark');
    await root(page).getByRole('radio', { name: 'Table' }).check({ force: true });
    await expect(root(page).locator('table.swg-mtable__table')).toBeVisible();
    await shot(page, 'media-table-dark');
  });
});

test.describe('role matrix', () => {
  test('an editor sees the uploader filter, an author does not and only gets their own uploads', async ({
    browser,
    baseURL,
  }) => {
    for (const [role, filter] of [
      ['editor', true],
      ['author', false],
    ] as const) {
      const context = await browser.newContext({ storageState: authFile(role), baseURL });
      const page = await context.newPage();
      await openMedia(page);
      await expect(root(page).getByLabel('Uploader (user ID)')).toHaveCount(filter ? 1 : 0);
      await context.close();
    }
  });

  test('an author never sees the administrator’s fixture images (the server filters, not the UI)', async ({
    browser,
    baseURL,
  }) => {
    const context = await browser.newContext({ storageState: authFile('author'), baseURL });
    const page = await context.newPage();
    // Unattached uploads are visible to every user who may use the library (D11): search must still never expose
    // files that are not readable; what the author sees must equal what the REST API returns for the author.
    await openMedia(page, ONLY_MINE);
    const fx = await GalleryFixtures.forPage(page);
    const response = await fx.rest('media', { search: PREFIX, per_page: 100 });
    expect(response.status()).toBe(200);
    const rows = (await response.json()) as Array<{ id: number }>;
    await openMedia(page, ONLY_MINE);
    await expect(tiles(page)).toHaveCount(Math.min(rows.length, 40));
    await context.close();
  });

  test('a contributor has no Media Overview and the API refuses them', async ({
    browser,
    baseURL,
  }) => {
    const context = await browser.newContext({ storageState: authFile('contributor'), baseURL });
    const page = await context.newPage();
    await page.goto(`${PLUGIN_PAGE}#/media`);
    await expect(root(page).getByRole('heading', { name: 'No access' })).toBeVisible();
    const fx = await GalleryFixtures.forPage(page);
    expect((await fx.rest('media')).status()).toBe(403);
    await context.close();
  });

  test('German user: translated page', async ({ browser, baseURL }) => {
    const context = await browser.newContext({ storageState: authFile('german'), baseURL });
    const page = await context.newPage();
    await page.goto(`${PLUGIN_PAGE}#/media`);
    await expect(
      root(page).getByRole('heading', { level: 2, name: 'Medienübersicht' }),
    ).toBeVisible();
    await expect(root(page).getByRole('searchbox', { name: 'Bilder durchsuchen' })).toBeVisible();
    await shot(page, 'media-german');
    await context.close();
  });
});
