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
 * `POST /galleries/{id}/preview` and shown in a sandboxed iframe. Fixtures (galleries and tiny PNGs) come through the REST
 * API with a unique prefix and are purged in afterAll. The iframe is a separate document: axe scans the parent page only.
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
const previewTab = (page: Page): Locator => root(page).getByRole('tab', { name: 'Live preview' });
const frameElement = (page: Page): Locator => root(page).locator('iframe.swg-pvf');
const frame = (page: Page): FrameLocator => page.frameLocator('#swg-admin-root iframe.swg-pvf');
const pane = (page: Page): Locator => root(page).locator('.swg-pv');

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
  await previewTab(page).click();
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

async function shot(page: Page, name: string): Promise<void> {
  await mkdir(SCREENSHOT_DIR, { recursive: true });
  await page.evaluate(() => {
    window.scrollTo(0, 0);
    return document.fonts.ready;
  });
  await page.screenshot({ path: resolve(SCREENSHOT_DIR, `${name}.png`), fullPage: true });
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
    await previewTab(page).click();
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
    await expect(root(page).locator('.swg-pv__diag')).toHaveCount(0);
  });

  test('previews unsaved settings without changing the saved gallery, and says so', async ({
    page,
  }) => {
    const gallery = await newGallery('Staged', 4);
    const requests = countPreviewRequests(page);
    await openPreview(page, gallery);
    expect(await gridColumns(page)).toBe(3);
    await expect(pane(page).locator('.swg-pv__unsaved-settings')).toHaveCount(0);

    await showLayoutPanel(page);
    // Several quick edits: debounced into very few requests.
    for (const value of ['4', '5', '6', '2']) await columnsInput(page).fill(value);
    await expect.poll(() => gridColumns(page)).toBe(2);
    expect(requests.count()).toBeLessThanOrEqual(3);
    await expect(pane(page).locator('.swg-pv__unsaved-settings')).toBeVisible();
    expect(requests.bodies.at(-1)).toMatchObject({
      layout: 'grid',
      settings: { columns: 2 },
      page: 1,
    });

    // Nothing was saved.
    const saved = await admin.detail(gallery.id);
    expect(saved.settings).toEqual({});

    // Gallery title edits are not part of the render.
    const before = requests.count();
    await root(page)
      .locator('.swg-ed__side .swg-seg')
      .getByText('Details', { exact: true })
      .click();
    await root(page).locator('.swg-gdp').getByLabel('Title').fill(`${gallery.title} renamed`);
    // A re-render would arrive within the debounce window; it must stay away.
    await expect(async () => expect(requests.count()).toBeGreaterThan(before)).not.toPass({
      timeout: 1500,
    });

    // Saving re-renders once and removes the "unsaved" notice.
    await root(page).getByRole('button', { name: 'Save', exact: true }).click();
    await expect(state(page)).toContainText('All changes saved');
    await expect.poll(() => requests.count()).toBe(before + 1);
    await expect(pane(page).locator('.swg-pv__unsaved-settings')).toHaveCount(0);
    expect((await admin.detail(gallery.id)).settings).toEqual({ columns: 2 });
    expect(await gridColumns(page)).toBe(2);
  });

  test('an invalid value shows the validation message and keeps the last good preview', async ({
    page,
  }) => {
    const gallery = await newGallery('Invalid', 3);
    await openPreview(page, gallery);
    await showLayoutPanel(page);
    await columnsInput(page).fill('20');
    await expect(root(page).locator('.swg-lsp')).toContainText('Must be at most 12.');
    await expect(pane(page).locator('.swg-pv__error')).toContainText('Fix the marked settings.');
    await expect(pane(page).locator('.swg-pv__error')).toContainText(
      'Showing the last successful preview.',
    );
    await expect(pane(page).locator('.swg-pv__stage')).toHaveClass(/is-stale/);
    await expect(frame(page).locator('.swg-gallery img')).toHaveCount(3);
    await expect(root(page).locator('.swg-lsp [role="alert"]').first()).toBeVisible();
    await shot(page, 'editor-preview-error-validation');

    await columnsInput(page).fill('4');
    await expect(pane(page).locator('.swg-pv__error')).toHaveCount(0);
    await expect(pane(page).locator('.swg-pv__stage')).not.toHaveClass(/is-stale/);
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

  test('viewport switcher resizes the frame', async ({ page }) => {
    // Collapse the plugin navigation so that a tablet-wide frame fits next to the settings panel.
    await page.setViewportSize({ width: 1590, height: 900 });
    const gallery = await newGallery('Viewport', 4);
    await openPreview(page, gallery);
    await root(page).getByRole('button', { name: 'Collapse navigation' }).click();
    const width = async (): Promise<number> => (await frameElement(page).boundingBox())?.width ?? 0;
    // Room inside the preview stage (padding 12 px each side); the frame never exceeds it.
    const room = async (): Promise<number> =>
      (await pane(page)
        .locator('.swg-pv__stage')
        .evaluate((el) => el.clientWidth)) - 24;
    await expect.poll(room).toBeGreaterThan(768);

    await pickViewport(page, 'Mobile');
    await expect.poll(width).toBe(375);
    await expect(pane(page).locator('.swg-pv__width')).toContainText('375 px');
    await shot(page, 'editor-preview-mobile');
    await pickViewport(page, 'Tablet');
    await expect.poll(width).toBe(768);
    await pickViewport(page, 'Desktop');
    await expect.poll(width).toBe(Math.min(1280, await room()));
    await pickViewport(page, 'Fit');
    await expect.poll(width).toBe(await room());
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
    await previewTab(page).click();
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
    await expect(root(page).locator('.swg-pv img[src="x"]')).toHaveCount(0);
    await expect(page.locator('script:not([src])').filter({ hasText: '__pwned' })).toHaveCount(0);
  });

  test('side by side on wide screens: no tabs, images and preview together', async ({ page }) => {
    await page.setViewportSize({ width: 1700, height: 1000 });
    const gallery = await newGallery('Wide', 4);
    await openEditor(page, gallery);
    await expect(root(page).getByRole('tab')).toHaveCount(0);
    await expect(root(page).getByRole('listbox')).toBeVisible();
    await expect(frameElement(page)).toBeVisible();
    await expect(frame(page).locator('.swg-gallery img')).toHaveCount(4);
    const images = await root(page).locator('.swg-ed__images').boundingBox();
    const preview = await root(page).locator('.swg-ed__previewpanel').boundingBox();
    expect(images && preview && Math.abs(images.y - preview.y) < 120).toBe(true);
    await shot(page, 'editor-preview-wide');
  });

  test('keyboard: the tabs follow the WAI-ARIA pattern and the preview controls are reachable', async ({
    page,
  }) => {
    const gallery = await newGallery('Keyboard', 3);
    await openEditor(page, gallery);
    const images = root(page).getByRole('tab', { name: 'Images' });
    await images.focus();
    await page.keyboard.press('ArrowRight');
    await expect(previewTab(page)).toHaveAttribute('aria-selected', 'true');
    await expect(previewTab(page)).toBeFocused();
    await expect(frameElement(page)).toBeVisible();
    await page.keyboard.press('Tab');
    await expect(root(page).locator('#swg-ed-view-panel-preview')).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(pane(page).locator('.swg-seg input:checked')).toBeFocused();
    await page.keyboard.press('Home');
    await previewTab(page).focus();
    await page.keyboard.press('Home');
    await expect(images).toHaveAttribute('aria-selected', 'true');
    // The preview announces its updates through the editor's live region.
    await previewTab(page).click();
    await expect(root(page).locator('.swg-shell > [role="status"]')).toContainText(
      /Preview updated\. Page 1 of 1\./,
    );
  });

  test('axe: the editor with the preview pane open (light and dark; the frame is a separate document)', async ({
    page,
  }) => {
    const gallery = await newGallery('Axe', 4, 'publish');
    await openPreview(page, gallery);
    const options = { exclude: ['iframe.swg-pvf'] };
    expect(await seriousViolations(page, '#swg-admin-root', options), 'preview, light').toEqual([]);
    await shot(page, 'editor-preview-light');
    await page.getByRole('button', { name: 'Dark mode' }).click();
    await expect(root(page)).toHaveClass(/swg-dark/);
    await expect(frame(page).locator('.swg-gallery')).toBeVisible();
    expect(await seriousViolations(page, '#swg-admin-root', options), 'preview, dark').toEqual([]);
    const background = await frame(page)
      .locator('body')
      .evaluate((body) => getComputedStyle(body).backgroundColor);
    expect(background).toBe('rgb(19, 26, 46)');
    await shot(page, 'editor-preview-dark');
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
    await previewTab(contributorPage).click();
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
    await root(page).getByRole('tab', { name: 'Live-Vorschau' }).click();
    await expect(frameElement(page)).toBeVisible();
    await expect(frameElement(page)).toHaveAttribute(
      'title',
      'Vorschau der Galerie, wie sie auf der Website erscheint',
    );
    await expect(pane(page).locator('.swg-pv__pagetext')).toHaveText('Seite 1 von 1 · 3 Bilder');
    await expect(pane(page).getByRole('button', { name: 'Vorschau aktualisieren' })).toBeVisible();
    await shot(page, 'editor-preview-german');
  });
});
