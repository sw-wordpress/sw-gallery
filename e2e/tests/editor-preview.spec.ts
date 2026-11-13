import { expect, test } from '@playwright/test';
import type { Browser, FrameLocator, Locator, Page } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { authFile, PLUGIN_PAGE, SCREENSHOT_DIR } from '../support/env';
import type { Role } from '../support/env';
import { seriousViolations } from '../support/axe';
import { GalleryFixtures, tinyPng, uniquePrefix } from '../support/galleries';
import type { GalleryRecord, MediaRecord } from '../support/galleries';

/**
 * Editor live preview (M3-23, M3-21 editor part) against the real renderer: the staged, unsaved state is posted to
 * `POST /galleries/{id}/preview` and shown in a sandboxed iframe inside a full-viewport dialog (opened by the Preview button). Fixtures (galleries and tiny PNGs) come through the REST
 * API with a unique prefix and are purged in afterAll. The dialog is a Quasar portal outside `#swg-admin-root`; the iframe is a separate document: axe scans the parent page only.
 */
const PREFIX = uniquePrefix('preview');
const COLORS: Array<[number, number, number]> = [
  [200, 40, 40],
  [40, 160, 60],
  [50, 80, 220],
  [230, 190, 30],
  [150, 60, 180],
  [30, 180, 190],
  [120, 120, 120],
];
const FRAME_TITLE = 'Preview of the gallery as it appears on the website';

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
    images.push(await admin.uploadImage(`${PREFIX} img ${index + 1}`, tinyPng(color, 48)));
  }
});

test.afterAll(async () => {
  await admin.purge(PREFIX);
  await admin.purgeMedia(PREFIX);
});

const root = (page: Page): Locator => page.locator('#swg-admin-root');
const state = (page: Page): Locator => root(page).locator('.swg-ed__state');
const previewButton = (page: Page, name = 'Preview'): Locator =>
  root(page).locator('.swg-ed__actions').getByRole('button', { name, exact: true });
/** The dialog is a portal on `<body>`, not part of `#swg-admin-root`. */
const dialogBox = (page: Page): Locator => page.locator('.swg-pvd');
const dialog = (page: Page, name = 'Live preview'): Locator => page.getByRole('dialog', { name });
const frameElement = (page: Page): Locator => dialogBox(page).locator('iframe.swg-pvf');
const frame = (page: Page): FrameLocator => page.frameLocator('.swg-pvd iframe.swg-pvf');
const pane = (page: Page): Locator => dialogBox(page).locator('.swg-pv');

/** Opens the preview dialog with the Preview button (the editor must be open). */
async function showPreviewDialog(page: Page, name = 'Preview'): Promise<void> {
  await previewButton(page, name).click();
  await expect(dialogBox(page)).toBeVisible();
  // Settled (the slide-up has ended) and covering the whole viewport, WordPress chrome included.
  const size = page.viewportSize();
  await expect
    .poll(async () => {
      const box = await dialogBox(page).boundingBox();
      return box === null
        ? null
        : [Math.round(box.x), Math.round(box.y), Math.round(box.width), Math.round(box.height)];
    })
    .toEqual([0, 0, size?.width, size?.height]);
}

async function closePreviewDialog(page: Page): Promise<void> {
  await dialogBox(page).getByRole('button', { name: 'Close preview' }).click();
  await expect(dialogBox(page)).toHaveCount(0);
}

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

async function openEditor(page: Page, gallery: GalleryRecord): Promise<void> {
  await page.goto(`${PLUGIN_PAGE}#/galleries/${gallery.id}`);
  await expect(root(page).locator('.swg-ed__title')).toBeVisible();
  await expect(state(page)).toBeVisible();
}

async function openPreview(page: Page, gallery: GalleryRecord): Promise<void> {
  await openEditor(page, gallery);
  await showPreviewDialog(page);
  await expect(frameElement(page)).toBeVisible();
  await expect(frame(page).locator('.swg-gallery')).toBeVisible();
}

/** Counts the POSTs to the preview route (the REST root may be `/wp-json/` or `?rest_route=`). */
function countPreviewRequests(page: Page): { count: () => number; bodies: unknown[] } {
  const bodies: unknown[] = [];
  page.on('request', (request) => {
    if (
      request.method() === 'POST' &&
      /galleries\/\d+\/preview/.test(decodeURIComponent(request.url()))
    ) {
      bodies.push(request.postDataJSON());
    }
  });
  return { count: () => bodies.length, bodies };
}

/** The request count stays at `expected` for `ms` (a re-render would arrive within the debounce window). */
async function expectNoMoreRequests(
  requests: { count: () => number },
  expected: number,
  ms = 1500,
): Promise<void> {
  expect(requests.count()).toBe(expected);
  await expect(async () => expect(requests.count()).not.toBe(expected)).not.toPass({
    timeout: ms,
  });
}

const isPreviewRoute = (url: URL): boolean =>
  /galleries\/\d+\/preview/.test(decodeURIComponent(url.href));

async function showLayoutPanel(page: Page): Promise<void> {
  await root(page)
    .locator('.swg-ed__side .swg-seg')
    .getByText('Layout & settings', { exact: true })
    .click();
  await expect(root(page).locator('.swg-lsp')).toBeVisible();
}

const columnsInput = (page: Page): Locator =>
  root(page).locator('.swg-lsp input[type="number"]').first();

const gridColumns = (page: Page): Promise<number> =>
  frame(page)
    .locator('ul.swg-items')
    .evaluate((el) => getComputedStyle(el).gridTemplateColumns.split(' ').length);

async function pickViewport(
  page: Page,
  label: 'Mobile' | 'Tablet' | 'Desktop' | 'Fit',
): Promise<void> {
  await pane(page).locator('.swg-seg').getByText(label, { exact: true }).click();
}

async function shot(page: Page, name: string, fullPage = false): Promise<void> {
  await mkdir(SCREENSHOT_DIR, { recursive: true });
  await page.evaluate(() => {
    window.scrollTo(0, 0);
    return document.fonts.ready;
  });
  await page.screenshot({ path: resolve(SCREENSHOT_DIR, `${name}.png`), fullPage });
}

test.describe('editor live preview as administrator', () => {
  test.use({ storageState: authFile('administrator') });

  test('renders the real gallery in a sandboxed frame and never in the admin DOM', async ({
    page,
  }) => {
    const gallery = await newGallery('Render', 4, 'publish');
    const response = page.waitForResponse(
      (r) => r.request().method() === 'POST' && isPreviewRoute(new URL(r.url())),
    );
    await openEditor(page, gallery);
    await expect(frameElement(page)).toBeHidden();
    await showPreviewDialog(page);
    const answer = await response;
    expect(answer.status()).toBe(200);
    expect(answer.headers()['cache-control']).toContain('no-store');

    const el = frameElement(page);
    await expect(el).toBeVisible();
    await expect(el).toHaveAttribute('sandbox', 'allow-scripts');
    await expect(el).toHaveAttribute('title', FRAME_TITLE);
    const srcdoc = (await el.getAttribute('srcdoc')) ?? '';
    expect(srcdoc).toContain('Content-Security-Policy');
    expect(srcdoc).toMatch(/<link rel="stylesheet" href="[^"]*layouts-[\w-]+\.css\?ver=[^"]+">/);

    const gallery$ = frame(page).locator('.swg-gallery');
    await expect(gallery$).toHaveClass(/swg-layout-grid/);
    await expect(gallery$.locator('img')).toHaveCount(4);
    await expect(frame(page).locator('ul.swg-items')).toHaveCSS('display', 'grid');
    for (const img of await gallery$.locator('img').all()) {
      await expect
        .poll(() => img.evaluate((i: HTMLImageElement) => i.naturalWidth))
        .toBeGreaterThan(0);
    }
    await expect(pane(page).locator('.swg-pv__pagetext')).toHaveText('Page 1 of 1 · 4 images');
    // The renderer markup exists only inside the frame document.
    await expect(page.locator('.swg-gallery')).toHaveCount(0);
    await expect(pane(page).locator('.swg-pv__diag')).toHaveCount(0);
  });

  test('previews unsaved settings without changing the saved gallery, and says so', async ({
    page,
  }) => {
    const gallery = await newGallery('Staged', 4);
    const requests = countPreviewRequests(page);
    await openEditor(page, gallery);
    // Several quick edits in the settings panel, before the preview is opened: nothing is requested yet.
    await showLayoutPanel(page);
    for (const value of ['4', '5', '6', '2']) await columnsInput(page).fill(value);
    await expect(page.locator('.swg-pvd')).toHaveCount(0);
    await expectNoMoreRequests(requests, 0, 1200);

    await showPreviewDialog(page);
    await expect(frame(page).locator('.swg-gallery')).toBeVisible();
    await expect.poll(() => gridColumns(page)).toBe(2);
    expect(requests.count()).toBe(1);
    await expect(pane(page).locator('.swg-pv__unsaved-settings')).toBeVisible();
    expect(requests.bodies.at(-1)).toMatchObject({
      layout: 'grid',
      settings: { columns: 2 },
      page: 1,
    });
    // The staged value is shown, nothing was saved.
    expect((await admin.detail(gallery.id)).settings).toEqual({});

    // Gallery title edits are not part of the render.
    await closePreviewDialog(page);
    await root(page)
      .locator('.swg-ed__side .swg-seg')
      .getByText('Details', { exact: true })
      .click();
    await root(page).locator('.swg-gdp').getByLabel('Title').fill(`${gallery.title} renamed`);
    await showPreviewDialog(page);
    await expect(frame(page).locator('.swg-gallery')).toBeVisible();
    expect(requests.count()).toBe(2);
    await expect(async () => expect(requests.count()).toBeGreaterThan(2)).not.toPass({
      timeout: 1500,
    });

    // Saving (behind the closed dialog) and opening again: one render, no "unsaved" notice.
    await closePreviewDialog(page);
    await root(page).getByRole('button', { name: 'Save', exact: true }).click();
    await expect(state(page)).toContainText('All changes saved');
    await showPreviewDialog(page);
    await expect(frame(page).locator('.swg-gallery')).toBeVisible();
    await expect.poll(() => requests.count()).toBe(3);
    await expect(pane(page).locator('.swg-pv__unsaved-settings')).toHaveCount(0);
    expect((await admin.detail(gallery.id)).settings).toEqual({ columns: 2 });
    expect(await gridColumns(page)).toBe(2);
  });

  test('an invalid value shows the validation message in the dialog and in the settings', async ({
    page,
  }) => {
    const gallery = await newGallery('Invalid', 3);
    await openEditor(page, gallery);
    await showLayoutPanel(page);
    await columnsInput(page).fill('20');
    await showPreviewDialog(page);
    await expect(pane(page).locator('.swg-pv__error')).toContainText('Fix the marked settings.');
    await expect(frameElement(page)).toHaveCount(0);
    await shot(page, 'editor-preview-error-validation');

    // The settings panel is behind the modal: close it to see and fix the marked field.
    await closePreviewDialog(page);
    await expect(root(page).locator('.swg-lsp')).toContainText('Must be at most 12.');
    await expect(root(page).locator('.swg-lsp [role="alert"]').first()).toBeVisible();
    await columnsInput(page).fill('4');
    await showPreviewDialog(page);
    await expect(pane(page).locator('.swg-pv__error')).toHaveCount(0);
    await expect.poll(() => gridColumns(page)).toBe(4);
    expect((await admin.detail(gallery.id)).settings).toEqual({});
  });

  test('server and network failures keep the last preview greyed out and offer a retry', async ({
    page,
  }) => {
    const gallery = await newGallery('Failure', 3);
    await openPreview(page, gallery);

    await page.route(isPreviewRoute, (route) =>
      route.fulfill({
        status: 500,
        contentType: 'application/json',
        body: JSON.stringify({
          code: 'swg_internal_error',
          message: 'secret internals',
          data: { status: 500 },
        }),
      }),
    );
    await pane(page).getByRole('button', { name: 'Refresh preview' }).click();
    const error = pane(page).locator('.swg-pv__error');
    await expect(error).toContainText('The server could not render the preview.');
    await expect(error).not.toContainText('secret internals');
    await expect(pane(page).locator('.swg-pv__stage')).toHaveClass(/is-stale/);
    await expect(frame(page).locator('.swg-gallery img')).toHaveCount(3);
    await shot(page, 'editor-preview-error-server');

    await page.unroute(isPreviewRoute);
    await page.route(isPreviewRoute, (route) => route.abort('failed'));
    await error.getByRole('button', { name: 'Try again' }).click();
    await expect(error).toContainText('The server could not be reached.');

    await page.unroute(isPreviewRoute);
    await error.getByRole('button', { name: 'Try again' }).click();
    await expect(pane(page).locator('.swg-pv__error')).toHaveCount(0);
    await expect(pane(page).locator('.swg-pv__stage')).not.toHaveClass(/is-stale/);
  });

  test('viewport buttons set real frame widths inside the dialog, Fit fills the stage', async ({
    page,
  }) => {
    await page.setViewportSize({ width: 1600, height: 900 });
    const gallery = await newGallery('Viewport', 4);
    await openPreview(page, gallery);
    const width = async (): Promise<number> => (await frameElement(page).boundingBox())?.width ?? 0;
    // Room inside the preview stage (padding 12 px each side).
    const room = async (): Promise<number> =>
      (await pane(page)
        .locator('.swg-pv__stage')
        .evaluate((el) => el.clientWidth)) - 24;
    await expect.poll(room).toBeGreaterThan(1280);

    await expect.poll(width).toBe(await room());
    await pickViewport(page, 'Mobile');
    await expect.poll(width).toBe(375);
    await expect(pane(page).locator('.swg-pv__width')).toContainText('375 px');
    await shot(page, 'editor-preview-mobile');
    await pickViewport(page, 'Tablet');
    await expect.poll(width).toBe(768);
    await shot(page, 'editor-preview-tablet');
    await pickViewport(page, 'Desktop');
    await expect.poll(width).toBe(1280);
    await pickViewport(page, 'Fit');
    await expect.poll(width).toBe(await room());
    // The frame is as tall as the stage (the dialog), and the page behind does not scroll.
    const stageHeight = await pane(page)
      .locator('.swg-pv__stage')
      .evaluate((el) => el.clientHeight);
    expect((await frameElement(page).boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(
      Math.min(stageHeight - 24, 480) - 1,
    );
    // The frame follows the width: a phone-sized frame shows the same gallery.
    await pickViewport(page, 'Mobile');
    await expect(frame(page).locator('.swg-gallery img')).toHaveCount(4);
  });

  test('the lightbox works inside the frame and no link navigates the admin page away', async ({
    page,
  }) => {
    const gallery = await newGallery('Lightbox', 3, 'publish');
    await openPreview(page, gallery);
    const editorUrl = page.url();
    await frame(page).locator('a.swg-link').first().click();
    const dialog = frame(page).getByRole('dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog).toHaveAttribute('aria-modal', 'true');
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    expect(page.url()).toBe(editorUrl);

    // A plain link inside the frame is neutralised: neither the frame nor the admin window navigates.
    await frame(page)
      .locator('body')
      .evaluate((body) => {
        const link = document.createElement('a');
        link.id = 'swg-e2e-link';
        link.href = 'https://example.com/';
        link.textContent = 'external';
        body.append(link);
      });
    await frame(page).locator('#swg-e2e-link').click();
    await expect(async () => expect(page.url()).not.toBe(editorUrl)).not.toPass({ timeout: 800 });
    await expect(frame(page).locator('#swg-e2e-link')).toBeVisible();
    await expect(frame(page).locator('.swg-gallery')).toBeVisible();
  });

  test('pages through more images than fit on a preview page', async ({ page }) => {
    const gallery = await newGallery('Paging', 7);
    await openPreview(page, gallery);
    await pane(page).getByLabel('Images per page').selectOption('6');
    await expect(pane(page).locator('.swg-pv__pagetext')).toHaveText('Page 1 of 2 · 7 images');
    await expect(frame(page).locator('.swg-gallery img')).toHaveCount(6);
    await expect(pane(page).getByRole('button', { name: 'Previous preview page' })).toBeDisabled();

    await pane(page).getByRole('button', { name: 'Next preview page' }).click();
    await expect(pane(page).locator('.swg-pv__pagetext')).toHaveText('Page 2 of 2 · 7 images');
    await expect(frame(page).locator('.swg-gallery img')).toHaveCount(1);
    await expect(pane(page).getByRole('button', { name: 'Next preview page' })).toBeDisabled();

    await pane(page).getByRole('button', { name: 'Previous preview page' }).click();
    await expect(pane(page).locator('.swg-pv__pagetext')).toHaveText('Page 1 of 2 · 7 images');
    await expect(frame(page).locator('.swg-gallery img')).toHaveCount(6);
  });

  test('a draft gallery previews for its editor', async ({ page }) => {
    const gallery = await newGallery('Draft', 3);
    expect(gallery.status).toBe('draft');
    await openPreview(page, gallery);
    await expect(frame(page).locator('.swg-gallery img')).toHaveCount(3);
  });

  test('an empty gallery shows the diagnostic, not a blank frame', async ({ page }) => {
    const gallery = await newGallery('Empty', 0);
    await openEditor(page, gallery);
    await showPreviewDialog(page);
    await expect(pane(page).locator('.swg-pv__empty')).toContainText('Nothing to preview yet');
    await expect(frameElement(page)).toHaveCount(0);
    const diagnostics = pane(page).locator('details.swg-pv__diag');
    await expect(diagnostics).toBeVisible();
    await expect(diagnostics).toHaveAttribute('open', '');
    await expect(diagnostics).toContainText('Notes for editors (1)');
    await expect(diagnostics).toContainText('no images');
  });

  test('hostile gallery-local texts stay text and nothing executes', async ({ page }) => {
    const gallery = await newGallery('Hostile', 2, 'publish');
    const [first] = await admin.items(gallery.id);
    if (first === undefined) throw new Error('no item');
    await admin.setItemTexts(gallery.id, first.id, {
      title: '<img src=x onerror="window.__pwned=1">',
      caption: '<img src=x onerror="window.__pwned=2"><script>window.__pwned=3</script> caption',
      alt: '" onmouseover="window.__pwned=4" x="',
    });
    await openPreview(page, gallery);
    const inFrame = frame(page);
    await expect(inFrame.locator('.swg-gallery img')).toHaveCount(2);
    await expect(inFrame.locator('.swg-gallery img[src="x"]')).toHaveCount(0);
    await expect(inFrame.locator('.swg-caption img')).toHaveCount(0);
    await expect(inFrame.locator('.swg-caption script')).toHaveCount(0);
    await inFrame.locator('.swg-gallery img').first().hover();
    const pwned = (): Promise<unknown> =>
      page.evaluate(() => (window as unknown as { __pwned?: unknown }).__pwned);
    await expect(async () => expect(await pwned()).toBeDefined()).not.toPass({ timeout: 800 });
    expect(await pwned()).toBeUndefined();
    expect(
      await inFrame
        .locator('body')
        .evaluate(() => (window as unknown as { __pwned?: unknown }).__pwned),
    ).toBeUndefined();
    // Nothing of it leaked into the admin document.
    await expect(page.locator('.swg-pv img[src="x"]')).toHaveCount(0);
    await expect(page.locator('script:not([src])').filter({ hasText: '__pwned' })).toHaveCount(0);
  });

  test('opens in a modal that fills the viewport; images grid keeps the full main column', async ({
    page,
  }) => {
    await page.setViewportSize({ width: 1700, height: 1000 });
    const gallery = await newGallery('Modal', 4);
    await openEditor(page, gallery);
    // No tabs, no side-by-side pane: the images keep the whole main column.
    await expect(root(page).getByRole('tab')).toHaveCount(0);
    await expect(root(page).locator('.swg-pv')).toHaveCount(0);
    await expect(root(page).getByRole('listbox')).toBeVisible();
    await showPreviewDialog(page);
    await expect(dialog(page)).toBeVisible();
    await expect(frame(page).locator('.swg-gallery img')).toHaveCount(4);
    // Nothing of the WordPress chrome is clickable behind it: the admin menu is covered.
    const top = await page.evaluate(
      () => document.elementFromPoint(10, 100)?.closest('.swg-pvd') !== null,
    );
    expect(top).toBe(true);
    // The dialog body scrolls, not the page behind it.
    await expect(dialog(page)).toHaveAttribute('aria-modal', 'true');
    await shot(page, 'editor-preview-dialog-desktop');
  });

  test('the dialog also fills a phone-sized viewport', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 700 });
    const gallery = await newGallery('Phone', 3);
    await openEditor(page, gallery);
    await showPreviewDialog(page);
    await expect(frame(page).locator('.swg-gallery img')).toHaveCount(3);
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
    ).toBe(true);
    await shot(page, 'editor-preview-dialog-mobile-viewport');
  });

  test('closes with Escape and with the button; focus returns to the Preview button', async ({
    page,
  }) => {
    const gallery = await newGallery('Close', 3);
    await openEditor(page, gallery);
    await showPreviewDialog(page);
    await expect(frame(page).locator('.swg-gallery')).toBeVisible();
    await expect(dialog(page)).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(dialogBox(page)).toHaveCount(0);
    await expect(previewButton(page)).toBeFocused();

    await previewButton(page).click();
    await expect(frameElement(page)).toBeVisible();
    await dialogBox(page).getByRole('button', { name: 'Close preview' }).click();
    await expect(dialogBox(page)).toHaveCount(0);
    await expect(frameElement(page)).toHaveCount(0);
    await expect(previewButton(page)).toBeFocused();
  });

  test('sends no request while closed, cancels on close and renders afresh on every opening', async ({
    page,
  }) => {
    const gallery = await newGallery('Requests', 3);
    const requests = countPreviewRequests(page);
    await openEditor(page, gallery);
    await expectNoMoreRequests(requests, 0, 1500);

    await showPreviewDialog(page);
    await expect(frame(page).locator('.swg-gallery')).toBeVisible();
    expect(requests.count()).toBe(1);
    const firstDoc = await frameElement(page).getAttribute('srcdoc');
    await closePreviewDialog(page);
    await expectNoMoreRequests(requests, 1, 1500);

    await showPreviewDialog(page);
    await expect(frame(page).locator('.swg-gallery')).toBeVisible();
    expect(requests.count()).toBe(2);
    // A new frame document (per-render token) was created.
    expect(await frameElement(page).getAttribute('srcdoc')).not.toBe(firstDoc);

    // A request still in flight is cancelled by closing the dialog.
    await closePreviewDialog(page);
    await page.route(isPreviewRoute, (route) => route.abort('failed'));
    const failed = page.waitForEvent('requestfailed', (r) => isPreviewRoute(new URL(r.url())));
    await showPreviewDialog(page);
    await failed;
    await closePreviewDialog(page);
    await page.unroute(isPreviewRoute);
    const before = requests.count();
    await expectNoMoreRequests(requests, before, 1500);
  });

  test('is disabled with a reason for a gallery that cannot be edited (trashed)', async ({
    page,
  }) => {
    const gallery = await newGallery('Locked', 2);
    await admin.trash(await admin.detail(gallery.id));
    const requests = countPreviewRequests(page);
    await openEditor(page, gallery);
    await expect(previewButton(page)).toBeDisabled();
    await expect(root(page).locator('.swg-ed__previewwrap')).toHaveAttribute(
      'title',
      'You are not allowed to edit this gallery, so there is no preview.',
    );
    await expectNoMoreRequests(requests, 0, 800);
  });

  test('keyboard: focus is trapped in the dialog and the preview controls are reachable', async ({
    page,
  }) => {
    const gallery = await newGallery('Keyboard', 3);
    await openEditor(page, gallery);
    await previewButton(page).focus();
    await page.keyboard.press('Enter');
    await expect(dialogBox(page)).toBeVisible();
    await expect(frame(page).locator('.swg-gallery')).toBeVisible();
    // Tabbing around never leaves the dialog.
    const insideDialog = (): Promise<string> =>
      page.evaluate(() =>
        // `body` = focus is on the browser chrome (nothing of the page behind the modal is reachable).
        document.activeElement?.closest('[role="dialog"]') ||
        document.activeElement === document.body
          ? 'in'
          : `out: ${document.activeElement?.outerHTML.slice(0, 160)}`,
      );
    for (const key of ['Tab', 'Shift+Tab']) {
      for (let i = 0; i < 14; i += 1) {
        await page.keyboard.press(key);
        await expect.poll(insideDialog).toBe('in');
      }
    }
    // A viewport button is operable with the keyboard.
    await pane(page).locator('.swg-seg input:checked').focus();
    await page.keyboard.press('ArrowLeft');
    await expect(pane(page).locator('.swg-seg input:checked')).toBeFocused();
    // The preview announces its updates through the editor's live region.
    await expect(root(page).locator('.swg-shell > [role="status"]')).toContainText(
      /Preview updated\. Page 1 of 1\./,
    );
    await page.keyboard.press('Escape');
    await expect(previewButton(page)).toBeFocused();
  });

  test('axe: the open preview dialog (light and dark; the frame is a separate document)', async ({
    page,
  }) => {
    const gallery = await newGallery('Axe', 4, 'publish');
    await openPreview(page, gallery);
    const options = { exclude: ['iframe.swg-pvf'] };
    expect(await seriousViolations(page, '.swg-pvd', options), 'dialog, light').toEqual([]);
    expect(await seriousViolations(page, 'body', options), 'page and dialog, light').toEqual([]);
    await shot(page, 'editor-preview-dialog-light');
    // The theme toggle sits behind the modal: close, switch, reopen.
    await closePreviewDialog(page);
    await page.getByRole('button', { name: 'Dark mode' }).click();
    await expect(root(page)).toHaveClass(/swg-dark/);
    await showPreviewDialog(page);
    await expect(frame(page).locator('.swg-gallery')).toBeVisible();
    expect(await seriousViolations(page, '.swg-pvd', options), 'dialog, dark').toEqual([]);
    expect(await seriousViolations(page, 'body', options), 'page and dialog, dark').toEqual([]);
    const background = await frame(page)
      .locator('body')
      .evaluate((body) => getComputedStyle(body).backgroundColor);
    expect(background).toBe('rgb(19, 26, 46)');
    await shot(page, 'editor-preview-dialog-dark');
  });
});

test.describe('editor live preview by role', () => {
  test('a contributor previews an own (empty) gallery; an author previews own images', async ({
    browser,
    baseURL,
  }) => {
    const contributor = await fixturesFor(browser, baseURL, 'contributor');
    const own = await contributor.create({ title: `${PREFIX} by contributor` });
    const contributorContext = await browser.newContext({
      storageState: authFile('contributor'),
      baseURL,
    });
    const contributorPage = await contributorContext.newPage();
    await openEditor(contributorPage, own);
    await showPreviewDialog(contributorPage);
    await expect(pane(contributorPage).locator('.swg-pv__empty')).toContainText(
      'Nothing to preview yet',
    );
    await expect(pane(contributorPage).locator('.swg-pv__error')).toHaveCount(0);
    await expect(pane(contributorPage).locator('details.swg-pv__diag')).toBeVisible();
    await contributorContext.close();

    const author = await fixturesFor(browser, baseURL, 'author');
    const media = await author.uploadImage(`${PREFIX} author img`, tinyPng([10, 120, 200], 48));
    const authored = await author.create({ title: `${PREFIX} by author` });
    await author.assign(authored.id, [media.id]);
    const authorContext = await browser.newContext({ storageState: authFile('author'), baseURL });
    const authorPage = await authorContext.newPage();
    await openPreview(authorPage, authored);
    await expect(frame(authorPage).locator('.swg-gallery img')).toHaveCount(1);
    await authorContext.close();
  });
});

test.describe('editor live preview in German', () => {
  test.use({ storageState: authFile('german') });

  test('is translated', async ({ page, browser, baseURL }) => {
    const german = await fixturesFor(browser, baseURL, 'german');
    const gallery = await german.create({ title: `${PREFIX} Deutsch` });
    await german.assign(
      gallery.id,
      images.slice(0, 3).map((image) => image.id),
    );
    await page.goto(`${PLUGIN_PAGE}#/galleries/${gallery.id}`);
    await expect(root(page).locator('.swg-ed__title')).toBeVisible();
    await showPreviewDialog(page, 'Vorschau');
    await expect(dialog(page, 'Live-Vorschau')).toBeVisible();
    await expect(dialogBox(page)).toContainText('Schließen Sie die Vorschau');
    await expect(frameElement(page)).toBeVisible();
    await expect(frameElement(page)).toHaveAttribute(
      'title',
      'Vorschau der Galerie, wie sie auf der Website erscheint',
    );
    await expect(pane(page).locator('.swg-pv__pagetext')).toHaveText('Seite 1 von 1 · 3 Bilder');
    await expect(pane(page).getByRole('button', { name: 'Vorschau aktualisieren' })).toBeVisible();
    await shot(page, 'editor-preview-dialog-german');
  });
});
