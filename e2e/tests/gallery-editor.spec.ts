import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Browser, Locator, Page } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { authFile, PLUGIN_PAGE, SCREENSHOT_DIR } from '../support/env';
import type { Role } from '../support/env';
import { GalleryFixtures, tinyPng, uniquePrefix } from '../support/galleries';
import type { GalleryRecord, MediaRecord } from '../support/galleries';
import { monitorPage } from '../support/monitor';

/**
 * Gallery editor (M2-24..M2-32) against the real REST API and the real `wp.media` modal. Fixtures (galleries and
 * tiny real PNGs uploaded through `POST /media`) are created per worker through the REST API with the nonce of
 * the logged-in page, carry a unique prefix and are purged again in afterAll.
 */
const PREFIX = uniquePrefix('editor');
const PURGE = [PREFIX];
const COLORS: Array<[number, number, number]> = [
  [200, 40, 40],
  [40, 160, 60],
  [50, 80, 220],
  [230, 190, 30],
  [150, 60, 180],
  [30, 180, 190],
];

/** Attachments uploaded through the editor's upload zone (they carry the file name as title, no prefix). */
const PURGE_MEDIA: number[] = [];
let admin: GalleryFixtures;
let images: MediaRecord[] = [];

async function fixturesFor(
  browser: Browser,
  baseURL: string | undefined,
  role: Role,
): Promise<GalleryFixtures> {
  const context = await browser.newContext({ storageState: authFile(role), baseURL });
  const page = await context.newPage();
  return GalleryFixtures.forPage(page);
}

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await fixturesFor(browser, baseURL, 'administrator');
  images = [];
  for (const [index, color] of COLORS.entries()) {
    images.push(await admin.uploadImage(`${PREFIX} img ${index + 1}`, tinyPng(color)));
  }
});

test.afterAll(async () => {
  for (const prefix of PURGE) await admin.purge(prefix);
  await admin.purgeMedia(PREFIX);
  for (const id of PURGE_MEDIA) await admin.deleteMedia(id);
});

const root = (page: Page): Locator => page.locator('#swg-admin-root');
const title = (page: Page): Locator => root(page).locator('.swg-ed__title');
const state = (page: Page): Locator => root(page).locator('.swg-ed__state');
const live = (page: Page): Locator => root(page).locator('.swg-shell > [role="status"]');
const grid = (page: Page): Locator => root(page).getByRole('listbox');
const tiles = (page: Page): Locator => grid(page).getByRole('option');
const save = (page: Page): Locator => root(page).getByRole('button', { name: 'Save', exact: true });
const details = (page: Page): Locator => root(page).locator('.swg-gdp');
const alertDialog = (page: Page): Locator => page.getByRole('alertdialog');

async function newGallery(
  name: string,
  imageCount = 4,
  status?: 'publish',
): Promise<GalleryRecord> {
  const gallery = await admin.create({ title: `${PREFIX} ${name}`, ...(status ? { status } : {}) });
  if (imageCount > 0) {
    await admin.assign(
      gallery.id,
      images.slice(0, imageCount).map((image) => image.id),
    );
  }
  return gallery;
}

async function openEditor(page: Page, gallery: GalleryRecord | number): Promise<void> {
  const id = typeof gallery === 'number' ? gallery : gallery.id;
  await page.goto(`${PLUGIN_PAGE}#/galleries/${id}`);
  await expect(title(page)).toBeVisible();
  await expect(state(page)).toBeVisible();
}

async function attachmentOrder(id: number): Promise<number[]> {
  return (await admin.items(id)).map((item) => item.attachmentId);
}

async function shot(page: Page, name: string): Promise<void> {
  await mkdir(SCREENSHOT_DIR, { recursive: true });
  await page.evaluate(() => document.fonts.ready);
  await page.screenshot({ path: resolve(SCREENSHOT_DIR, `${name}.png`), fullPage: true });
}

async function axe(page: Page, include: string): Promise<string[]> {
  const results = await new AxeBuilder({ page })
    .include(include)
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

/** Quasar slides dialogs in; axe must not measure colours halfway through the transition. */
async function dialogSettled(page: Page): Promise<void> {
  await page.waitForFunction(
    () => {
      const sheet = document.querySelector('.q-dialog__inner > *');
      if (
        sheet === null ||
        document.querySelector('[class*="-enter-active"], [class*="-leave-active"]')
      ) {
        return false;
      }
      const holder = window as unknown as { swgLastX?: number };
      const x = sheet.getBoundingClientRect().left;
      const stable = holder.swgLastX === x;
      holder.swgLastX = x;
      return stable;
    },
    undefined,
    { polling: 100 },
  );
}

/**
 * Opens the details of the first image by keyboard with the page scrolled to the top. Quasar locks the page scroll
 * while a dialog is open; axe mis-measures the background of fixed dialogs when the page was scrolled before.
 */
async function openFirstDetails(page: Page): Promise<void> {
  await page.evaluate(() => {
    window.scrollTo(0, 0);
    document.querySelector<HTMLElement>('[role="listbox"]')?.focus({ preventScroll: true });
  });
  await page.keyboard.press('Home');
  await page.keyboard.press('Enter');
  await expect(page.getByRole('dialog')).toBeVisible();
  await dialogSettled(page);
}

async function showLayoutPanel(page: Page): Promise<void> {
  await root(page).locator('.swg-seg').getByText('Layout & settings', { exact: true }).click();
  await expect(root(page).locator('.swg-lsp')).toBeVisible();
}

test.describe('as administrator', () => {
  test.use({ storageState: authFile('administrator') });

  test('opens from the list by the title link and by "Edit images", with breadcrumb back', async ({
    page,
  }) => {
    const gallery = await newGallery('Open from list', 2);
    await page.goto(`${PLUGIN_PAGE}#/galleries?search=${encodeURIComponent(gallery.title)}`);
    const card = root(page).locator('.swg-gcard', { hasText: gallery.title });
    await expect(card).toBeVisible();
    await card.getByRole('link', { name: gallery.title }).click();
    await expect(page).toHaveURL(new RegExp(`#/galleries/${gallery.id}$`));
    await expect(title(page)).toHaveText(gallery.title);
    await expect(tiles(page)).toHaveCount(2);

    await root(page).getByRole('navigation', { name: 'Breadcrumb' }).getByRole('link').click();
    await expect(root(page).getByRole('heading', { level: 2, name: 'Galleries' })).toBeVisible();
    await root(page)
      .getByRole('button', { name: `Actions for gallery ${gallery.title}` })
      .click();
    await page.getByRole('menuitem', { name: 'Edit images' }).click();
    await expect(page).toHaveURL(new RegExp(`#/galleries/${gallery.id}$`));
    await expect(title(page)).toHaveText(gallery.title);
  });

  test('a missing gallery shows the same not-found state as an unavailable one', async ({
    page,
  }) => {
    await page.goto(`${PLUGIN_PAGE}#/galleries/99999999`);
    await expect(root(page).getByRole('heading', { name: 'Gallery not found' })).toBeVisible();
    await expect(
      root(page).getByText('does not exist or you are not allowed to see it'),
    ).toBeVisible();
    await root(page).getByRole('link', { name: 'Back to galleries' }).click();
    await expect(root(page).getByRole('heading', { level: 2, name: 'Galleries' })).toBeVisible();
  });

  test('adds two images through the real wp.media modal; they are assigned immediately', async ({
    page,
  }) => {
    const gallery = await newGallery('Picker', 0);
    await openEditor(page, gallery);
    await expect(
      root(page).getByRole('heading', { name: 'This gallery has no images yet' }),
    ).toBeVisible();

    await root(page).getByRole('button', { name: 'Add images' }).first().click();
    const modal = page.locator('.media-modal');
    await expect(modal).toBeVisible();
    const library = modal.locator('.media-router .media-menu-item', { hasText: 'Media Library' });
    if ((await library.count()) > 0) await library.click();
    await modal.locator('#media-search-input').fill(PREFIX);
    const first = modal.locator(`li.attachment[aria-label="${images[0]?.title}"]`);
    const second = modal.locator(`li.attachment[aria-label="${images[1]?.title}"]`);
    await first.click();
    await second.click();
    await expect(modal.locator('.media-toolbar-primary .media-button-select')).toBeEnabled();
    await shot(page, 'gallery-editor-media-modal');
    await modal.locator('.media-toolbar-primary .media-button-select').click();

    await expect(tiles(page)).toHaveCount(2);
    await expect(state(page)).toContainText('All changes saved');
    await expect(page.locator('.q-notification', { hasText: '2 images added' })).toBeVisible();
    // applied immediately: no Save needed
    expect(await attachmentOrder(gallery.id)).toEqual([images[0]?.id, images[1]?.id]);
  });

  test('uploads by button and by dropping files: real progress rows, partial failure, retry', async ({
    page,
  }) => {
    const gallery = await newGallery('Upload', 0);
    await openEditor(page, gallery);
    const fileInput = root(page).locator('input[type="file"]');
    await fileInput.setInputFiles([
      { name: 'ok-one.png', mimeType: 'image/png', buffer: tinyPng([10, 10, 10]) },
      // passes the client hint (type) and the signature check of the server, but is cut off: not decodable
      { name: 'broken.png', mimeType: 'image/png', buffer: tinyPng([1, 2, 3]).subarray(0, 45) },
      // refused by the client hint before any request
      { name: 'notes.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4') },
    ]);
    const rows = root(page).locator('.swg-up__row');
    await expect(rows).toHaveCount(3);
    await expect(rows.nth(0)).toContainText('Added to the gallery');
    await expect(rows.nth(1)).toContainText('The image could not be read');
    await expect(rows.nth(2)).toContainText('Not a supported image type');
    await expect(
      root(page).getByRole('button', { name: 'Retry upload of broken.png' }),
    ).toBeVisible();
    // pdf was never requested, so it has no retry
    await expect(root(page).getByRole('button', { name: 'Retry upload of notes.pdf' })).toHaveCount(
      0,
    );
    await expect(page.locator('.q-notification', { hasText: '1 of 3 files added' })).toBeVisible();
    await expect(tiles(page)).toHaveCount(1);
    expect(await admin.items(gallery.id)).toHaveLength(1);

    // drag and drop of files onto the images area
    const dropped = tinyPng([90, 20, 140]).toString('base64');
    await page.evaluate(async (data) => {
      const bytes = Uint8Array.from(atob(data), (c) => c.charCodeAt(0));
      const transfer = new DataTransfer();
      transfer.items.add(new File([bytes], 'dropped.png', { type: 'image/png' }));
      const area = document.querySelector('.swg-ed__images');
      area?.dispatchEvent(
        new DragEvent('dragenter', { dataTransfer: transfer, bubbles: true, cancelable: true }),
      );
      area?.dispatchEvent(
        new DragEvent('drop', { dataTransfer: transfer, bubbles: true, cancelable: true }),
      );
    }, dropped);
    await expect(tiles(page)).toHaveCount(2);
    expect(await admin.items(gallery.id)).toHaveLength(2);
    await root(page).getByRole('button', { name: 'Remove broken.png from the list' }).click();
    await expect(rows.filter({ hasText: 'broken.png' })).toHaveCount(0);
    await shot(page, 'gallery-editor-upload');
    // clean up the uploads of this test (they carry no prefix title)
    for (const item of await admin.items(gallery.id)) {
      if (!images.some((image) => image.id === item.attachmentId)) {
        PURGE_MEDIA.push(item.attachmentId);
      }
    }
  });

  test('reorders with the mouse and saves the complete order', async ({ page }) => {
    const gallery = await newGallery('Mouse order');
    await openEditor(page, gallery);
    const before = await attachmentOrder(gallery.id);
    await tiles(page)
      .nth(3)
      .dragTo(tiles(page).nth(0), { targetPosition: { x: 6, y: 40 } });
    await expect(live(page)).toHaveText('Moved to position 1 of 4');
    await expect(state(page)).toContainText('One unsaved change');
    // not persisted before Save
    expect(await attachmentOrder(gallery.id)).toEqual(before);
    await save(page).click();
    await expect(state(page)).toContainText('All changes saved');
    expect(await attachmentOrder(gallery.id)).toEqual([before[3], before[0], before[1], before[2]]);
  });

  test('reorders by keyboard and with the buttons, announces the position, undo works', async ({
    page,
  }) => {
    const gallery = await newGallery('Keyboard order');
    await openEditor(page, gallery);
    const before = await attachmentOrder(gallery.id);
    await grid(page).focus();
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('Control+ArrowRight');
    await expect(live(page)).toHaveText('Moved to position 3 of 4');
    await page.keyboard.press('Control+Home');
    await expect(live(page)).toHaveText('Moved to position 1 of 4');
    await expect(grid(page)).toBeFocused();

    await root(page).getByRole('button', { name: 'Undo' }).click();
    await root(page).getByRole('button', { name: 'Undo' }).click();
    await expect(state(page)).toContainText('All changes saved');

    // buttons act on the selection
    await tiles(page).nth(2).click();
    await root(page).getByRole('button', { name: 'Move to end' }).click();
    await expect(live(page)).toHaveText('Moved to position 4 of 4');
    await save(page).click();
    await expect(state(page)).toContainText('All changes saved');
    expect(await attachmentOrder(gallery.id)).toEqual([before[0], before[1], before[3], before[2]]);
  });

  test('selects with click, shift and keyboard, removes after a confirmation naming the count', async ({
    page,
  }) => {
    const gallery = await newGallery('Remove');
    await openEditor(page, gallery);
    await tiles(page).nth(0).click();
    await tiles(page)
      .nth(2)
      .click({ modifiers: ['Shift'] });
    await expect(root(page).locator('.swg-ed__sel')).toHaveText('3 images selected');
    await page.keyboard.press('Escape');
    await expect(root(page).locator('.swg-ed__sel')).toHaveText('No images selected');
    await grid(page).focus();
    await page.keyboard.press('Control+a');
    await expect(root(page).locator('.swg-ed__sel')).toHaveText('4 images selected');
    await page.keyboard.press('Escape');
    await tiles(page).nth(1).click();
    await tiles(page).nth(3).click();
    await root(page).getByRole('button', { name: 'Remove', exact: true }).click();

    const dialog = alertDialog(page);
    await expect(dialog.getByRole('heading', { name: 'Remove 2 images?' })).toBeVisible();
    await expect(dialog.getByText(images[1]?.title ?? '')).toBeVisible();
    await expect(dialog.getByText('The images stay in the Media Library.')).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Cancel' })).toBeFocused();
    await dialog.getByRole('button', { name: 'Remove 2 images' }).click();
    await expect(tiles(page)).toHaveCount(2);
    await expect(live(page)).toHaveText('2 images removed (unsaved)');
    expect(await admin.items(gallery.id)).toHaveLength(4);
    await save(page).click();
    await expect(state(page)).toContainText('All changes saved');
    expect(await attachmentOrder(gallery.id)).toEqual([images[0]?.id, images[2]?.id]);
    // images only leave the gallery, never the Media Library
    expect(await admin.mediaExists(images[1]?.id ?? 0)).toBe(true);
    expect(await admin.mediaExists(images[3]?.id ?? 0)).toBe(true);
  });

  test('image details: global texts stay read-only, local overrides distinguish inherit and blank', async ({
    page,
  }) => {
    const gallery = await newGallery('Details', 2);
    await openEditor(page, gallery);
    await tiles(page).nth(0).dblclick();
    const sheet = page.getByRole('dialog');
    await expect(sheet.getByText('Global (Media Library)').first()).toBeVisible();
    await expect(sheet.getByText(images[0]?.title ?? '').first()).toBeVisible();
    await expect(sheet.getByText('Uses the global text.').first()).toBeVisible();

    const toggle = (field: string): Locator =>
      sheet.getByRole('switch', { name: `Use gallery-specific text: ${field}` });
    await toggle('Title').click();
    await sheet.getByLabel('Gallery-specific Title').fill('Local title');
    await toggle('Alternative text').click();
    await sheet.getByLabel('Gallery-specific Alternative text').fill('');
    await expect(sheet.getByText('nothing is shown in this gallery')).toBeVisible();
    await toggle('Caption').click();
    await sheet.getByLabel('Gallery-specific Caption').fill('Local caption');
    await shot(page, 'gallery-editor-details-panel');
    await sheet.getByRole('button', { name: 'Done' }).click();
    await expect(tiles(page).nth(0)).toHaveAttribute('aria-label', /^Local title/);
    await save(page).click();
    await expect(state(page)).toContainText('All changes saved');
    expect((await admin.items(gallery.id))[0]?.overrides).toEqual({
      title: 'Local title',
      alt: '',
      caption: 'Local caption',
    });
    expect((await admin.items(gallery.id))[1]?.overrides).toEqual({
      title: null,
      alt: null,
      caption: null,
    });

    // round trip: reload shows the stored state; switching back to "inherit" stores null again
    await page.reload();
    await expect(title(page)).toBeVisible();
    await tiles(page).nth(0).dblclick();
    await expect(toggle('Title')).toHaveAttribute('aria-checked', 'true');
    await expect(sheet.getByLabel('Gallery-specific Alternative text')).toHaveValue('');
    await toggle('Alternative text').click();
    await toggle('Caption').click();
    await sheet.getByRole('button', { name: 'Done' }).click();
    await save(page).click();
    await expect(state(page)).toContainText('All changes saved');
    expect((await admin.items(gallery.id))[0]?.overrides).toEqual({
      title: 'Local title',
      alt: null,
      caption: null,
    });
  });

  test('Escape closes the details and the focus returns to the grid', async ({ page }) => {
    const gallery = await newGallery('Details focus', 1);
    await openEditor(page, gallery);
    await grid(page).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(grid(page)).toBeFocused();
  });

  test('layout, responsive columns and sort mode are staged and saved as the complete map', async ({
    page,
  }) => {
    const gallery = await newGallery('Layout', 2);
    await openEditor(page, gallery);
    await showLayoutPanel(page);
    const panel = root(page).locator('.swg-lsp');
    await expect(panel.getByText('Default: 3')).toBeVisible();
    const numbers = panel.locator('input[type="number"]');
    await numbers.nth(0).fill('20');
    await expect(panel.getByText('Must be at most 12.')).toBeVisible();
    await expect(save(page)).toBeDisabled();
    await expect(state(page)).toContainText('Fix the invalid settings to save.');
    await numbers.nth(0).fill('5');
    await expect(panel.getByText('Must be at most 12.')).toHaveCount(0);
    await panel.getByRole('switch', { name: /Override: Columns, Medium/ }).click();
    await numbers.nth(1).fill('2');
    await panel.getByLabel('Order on the website').click();
    await page.getByRole('option', { name: 'Title A–Z' }).click();
    await shot(page, 'gallery-editor-settings-panel');
    await save(page).click();
    await expect(state(page)).toContainText('All changes saved');
    const saved = await admin.detail(gallery.id);
    expect(saved.settings).toEqual({ columns: { base: 5, md: 2 } });
    expect(saved.sortMode).toBe('title_asc');
    expect(saved.layout).toBe('grid');

    await page.reload();
    await showLayoutPanel(page);
    await expect(root(page).locator('.swg-lsp input[type="number"]').first()).toHaveValue('5');
    await expect(root(page).locator('.swg-lsp').getByLabel('Order on the website')).toHaveValue(
      'Title A–Z',
    );
  });

  test('edits gallery details, cover and publishes; undo and discard work', async ({ page }) => {
    const gallery = await newGallery('Publish me', 3);
    await openEditor(page, gallery);
    await details(page).getByLabel('Title').fill(`${gallery.title} renamed`);
    await details(page).getByLabel('Description').fill('Line one\nLine two');
    await details(page).getByLabel('Cover image').click();
    await page.getByRole('option', { name: /2\. / }).click();
    await expect(state(page)).toContainText('3 unsaved changes');

    await root(page).getByRole('button', { name: 'Discard changes' }).click();
    await expect(alertDialog(page).getByText('3 changes are discarded')).toBeVisible();
    await alertDialog(page).getByRole('button', { name: 'Discard changes' }).click();
    await expect(title(page)).toHaveText(gallery.title);
    await expect(state(page)).toContainText('All changes saved');
    await root(page).getByRole('button', { name: 'Undo' }).click();
    await expect(title(page)).toHaveText(`${gallery.title} renamed`);

    await root(page).getByRole('button', { name: 'Publish' }).click();
    await expect(page.locator('.q-notification', { hasText: 'Gallery published.' })).toBeVisible();
    await expect(root(page).getByRole('button', { name: 'Publish' })).toHaveCount(0);
    const saved = await admin.detail(gallery.id);
    expect(saved.status).toBe('publish');
    expect(saved.title).toBe(`${gallery.title} renamed`);
    expect(saved.description).toBe('Line one\nLine two');
    expect(saved.coverAttachmentId).toBe(images[1]?.id);
  });

  test('asks before leaving with unsaved changes and warns the browser on unload', async ({
    page,
  }) => {
    const gallery = await newGallery('Guard', 2);
    await openEditor(page, gallery);
    await details(page).getByLabel('Title').fill(`${gallery.title} edited`);
    await root(page).getByRole('link', { name: 'Galleries', exact: true }).first().click();
    const dialog = alertDialog(page);
    await expect(dialog.getByRole('heading', { name: 'Leave without saving?' })).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Keep editing' })).toBeFocused();
    await dialog.getByRole('button', { name: 'Keep editing' }).click();
    await expect(page).toHaveURL(new RegExp(`#/galleries/${gallery.id}$`));
    await expect(title(page)).toHaveText(`${gallery.title} edited`);

    const dialogs: string[] = [];
    page.on('dialog', (d) => {
      dialogs.push(d.type());
      void d.dismiss();
    });
    await page.close({ runBeforeUnload: true });
    await expect.poll(() => dialogs).toEqual(['beforeunload']);
  });

  test('leaving with the confirmation discards the staged changes', async ({ page }) => {
    const gallery = await newGallery('Guard leave', 1);
    await openEditor(page, gallery);
    await details(page).getByLabel('Title').fill('Never saved');
    await root(page).getByRole('link', { name: 'Galleries', exact: true }).first().click();
    await alertDialog(page).getByRole('button', { name: 'Leave without saving' }).click();
    await expect(root(page).getByRole('heading', { level: 2, name: 'Galleries' })).toBeVisible();
    expect((await admin.detail(gallery.id)).title).toBe(gallery.title);
  });

  test('conflict: a change elsewhere opens the dialog; re-applying keeps the staged texts', async ({
    page,
  }) => {
    const gallery = await newGallery('Conflict', 3);
    await openEditor(page, gallery);
    await details(page).getByLabel('Title').fill(`${gallery.title} mine`);
    await tiles(page).nth(0).dblclick();
    await page
      .getByRole('dialog')
      .getByRole('switch', { name: /Use gallery-specific text: Caption/ })
      .click();
    await page.getByRole('dialog').getByLabel('Gallery-specific Caption').fill('My caption');
    await page.getByRole('dialog').getByRole('button', { name: 'Done' }).click();

    // someone else saves in the meantime and adds an image
    const fresh = await admin.detail(gallery.id);
    expect(
      await admin.update(gallery.id, fresh.revision, { description: 'Changed by someone else' }),
    ).toBe(200);
    await admin.assign(gallery.id, [images[4]?.id ?? 0]);

    await save(page).click();
    const dialog = alertDialog(page);
    await expect(
      dialog.getByRole('heading', { name: 'The gallery was changed elsewhere' }),
    ).toBeVisible();
    await expect(dialog.getByText('Details changed: description.')).toBeVisible();
    await expect(dialog.getByText('One image was added.')).toBeVisible();
    await expect(dialog.getByText('You have 2 unsaved changes.')).toBeVisible();
    await shot(page, 'gallery-editor-conflict');
    await dialog.getByRole('button', { name: 'Reload and re-apply my changes' }).click();
    await expect(dialog).toHaveCount(0);
    await expect(title(page)).toHaveText(`${gallery.title} mine`);
    await expect(tiles(page)).toHaveCount(4);
    await expect(details(page).getByLabel('Description')).toHaveValue('Changed by someone else');
    await expect(root(page).getByText('re-applied to the current version')).toBeVisible();
    await save(page).click();
    await expect(state(page)).toContainText('All changes saved');
    const saved = await admin.detail(gallery.id);
    expect(saved.title).toBe(`${gallery.title} mine`);
    expect(saved.description).toBe('Changed by someone else');
    expect((await admin.items(gallery.id))[0]?.overrides.caption).toBe('My caption');
  });

  test('conflict: "Discard my changes" takes the server version', async ({ page }) => {
    const gallery = await newGallery('Conflict discard', 1);
    await openEditor(page, gallery);
    await details(page).getByLabel('Title').fill('Mine');
    const fresh = await admin.detail(gallery.id);
    expect(
      await admin.update(gallery.id, fresh.revision, { title: `${gallery.title} theirs` }),
    ).toBe(200);
    await save(page).click();
    await alertDialog(page).getByRole('button', { name: 'Discard my changes' }).click();
    await expect(title(page)).toHaveText(`${gallery.title} theirs`);
    await expect(state(page)).toContainText('All changes saved');
  });

  test('the editor renders without console errors or failed requests', async ({ page }) => {
    const gallery = await newGallery('Monitor', 3);
    const monitor = monitorPage(page);
    await openEditor(page, gallery);
    await expect(tiles(page)).toHaveCount(3);
    await page.waitForLoadState('networkidle');
    expect(monitor.problems, 'console errors/warnings').toEqual([]);
    expect(monitor.failedRequests, 'failed requests').toEqual([]);
  });
});

test.describe('accessibility and screenshots', () => {
  test.use({ storageState: authFile('administrator') });

  test('axe: the editor (light and dark, with details and settings) has no serious or critical violations', async ({
    page,
  }) => {
    const gallery = await newGallery('Axe', 4);
    await openEditor(page, gallery);
    await tiles(page).nth(1).click();
    await expect(tiles(page).nth(1)).toHaveAttribute('aria-selected', 'true');
    expect(await axe(page, '#swg-admin-root'), 'editor, light').toEqual([]);
    await shot(page, 'gallery-editor-light');

    await showLayoutPanel(page);
    expect(await axe(page, '#swg-admin-root'), 'settings, light').toEqual([]);
    await root(page).locator('.swg-seg').getByText('Details', { exact: true }).click();

    await openFirstDetails(page);
    expect(await axe(page, '.q-dialog'), 'details sheet, light').toEqual([]);
    await page.keyboard.press('Escape');

    await page.getByRole('button', { name: 'Dark mode' }).click();
    await expect(root(page)).toHaveClass(/swg-dark/);
    expect(await axe(page, '#swg-admin-root'), 'editor, dark').toEqual([]);
    await shot(page, 'gallery-editor-dark');
    await showLayoutPanel(page);
    expect(await axe(page, '#swg-admin-root'), 'settings, dark').toEqual([]);
    await openFirstDetails(page);
    expect(await axe(page, '.q-dialog'), 'details sheet, dark').toEqual([]);
    await shot(page, 'gallery-editor-details-dark');
  });

  test('screenshot: the editor on a 375 px phone does not scroll horizontally', async ({
    page,
  }) => {
    const gallery = await newGallery('Mobile', 4);
    await page.setViewportSize({ width: 375, height: 800 });
    await openEditor(page, gallery);
    await expect(tiles(page).first()).toBeVisible();
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );
    expect(overflow).toBeLessThanOrEqual(0);
    await shot(page, 'gallery-editor-mobile');
  });
});

test.describe('German', () => {
  test.use({ storageState: authFile('german') });

  test('the editor is translated', async ({ page, browser, baseURL }) => {
    const german = await fixturesFor(browser, baseURL, 'german');
    const gallery = await german.create({ title: `${PREFIX} Deutsch` });
    await openEditor(page, gallery);
    await expect(root(page).getByText('Alle Änderungen gespeichert')).toBeVisible();
    await expect(root(page).getByRole('button', { name: 'Speichern', exact: true })).toBeVisible();
    await expect(
      root(page).getByRole('heading', { name: 'Diese Galerie enthält noch keine Bilder' }),
    ).toBeVisible();
    await shot(page, 'gallery-editor-german');
    await german.purge(PREFIX);
  });
});

test.describe('roles', () => {
  test('an editor publishes; a contributor cannot publish and has the picker disabled', async ({
    browser,
    baseURL,
  }) => {
    const editor = await fixturesFor(browser, baseURL, 'editor');
    const created = await editor.create({ title: `${PREFIX} by editor` });
    const editorContext = await browser.newContext({ storageState: authFile('editor'), baseURL });
    const editorPage = await editorContext.newPage();
    await openEditor(editorPage, created);
    await root(editorPage).getByRole('button', { name: 'Publish' }).click();
    await expect(state(editorPage)).toContainText('All changes saved');
    expect((await editor.detail(created.id)).status).toBe('publish');
    await editorContext.close();

    const contributor = await fixturesFor(browser, baseURL, 'contributor');
    const own = await contributor.create({ title: `${PREFIX} by contributor` });
    const context = await browser.newContext({ storageState: authFile('contributor'), baseURL });
    const page = await context.newPage();
    await openEditor(page, own);
    await expect(root(page).getByRole('button', { name: 'Publish' })).toHaveCount(0);
    await expect(root(page).getByRole('button', { name: 'Add images' }).first()).toBeDisabled();
    await expect(
      root(page).getByText('Your account is not allowed to use the Media Library').first(),
    ).toBeVisible();
    await expect(root(page).getByRole('button', { name: 'Upload images' })).toBeDisabled();
    await details(page).getByLabel('Status').click();
    await expect(page.getByRole('option', { name: /^Published/ })).toHaveAttribute(
      'aria-disabled',
      'true',
    );
    await expect(page.getByRole('option', { name: /^Draft/ })).not.toHaveAttribute(
      'aria-disabled',
      'true',
    );
    await page.keyboard.press('Escape');
    await details(page).getByLabel('Title').fill(`${own.title} edited`);
    await save(page).click();
    await expect(state(page)).toContainText('All changes saved');
    expect((await contributor.detail(own.id)).title).toBe(`${own.title} edited`);
    await context.close();
    await editor.purge(PREFIX);
    await contributor.purge(PREFIX);
  });
});
