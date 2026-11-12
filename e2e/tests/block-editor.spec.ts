import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Browser, Page } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { authFile, SCREENSHOT_DIR } from '../support/env';
import type { Role } from '../support/env';
import {
  BlockFixtures,
  canvas,
  insertGalleryBlock,
  insertGalleryBlockByApi,
  openNewPost,
  openPost,
  rawContent,
  restNonce,
  restUrl,
} from '../support/block';
import type { BlockGallery } from '../support/block';
import { uniquePrefix } from '../support/galleries';
import { monitorPage } from '../support/monitor';

/**
 * The native Gutenberg block `sw-gallery/gallery` in the real block editor (M4-13, editor part). Fixtures (galleries
 * with tiny uploaded images) are created through the plugin's REST API; the published post is only checked for the
 * stored block comment, not for gallery markup (the frontend surfaces are covered by their own suites).
 */
const PREFIX = uniquePrefix('block');
/** Every worker creates and purges its own fixtures (module state is per worker), so parallel workers never delete each other's. */
let prefix = PREFIX;

let published: BlockGallery;
let draft: BlockGallery;
const SHOT = (name: string): string => resolve(SCREENSHOT_DIR, `block-${name}.png`);

async function fixturesAs(
  browser: Browser,
  baseURL: string | undefined,
  role: Role,
): Promise<{ fixtures: BlockFixtures; close: () => Promise<void> }> {
  const context = await browser.newContext({ storageState: authFile(role), baseURL });
  const page = await context.newPage();
  return { fixtures: await BlockFixtures.forPage(page), close: () => context.close() };
}

test.beforeAll(async ({ browser, baseURL }, testInfo) => {
  prefix = `${PREFIX} w${testInfo.workerIndex}`;
  await mkdir(SCREENSHOT_DIR, { recursive: true });
  const { fixtures, close } = await fixturesAs(browser, baseURL, 'editor');
  published = await fixtures.createGallery(`${prefix} summer`, 'publish', 2);
  draft = await fixtures.createGallery(`${prefix} draft`, 'draft', 1);
  await close();
});

test.afterAll(async ({ browser, baseURL }) => {
  for (const role of ['editor', 'contributor'] as const) {
    const { fixtures, close } = await fixturesAs(browser, baseURL, role);
    await fixtures.purge(prefix);
    await close();
  }
});

/** Makes sure the settings sidebar (block tab) is open. */
async function openSidebar(page: Page): Promise<void> {
  const toggle = page.getByRole('button', { name: 'Settings', exact: true });
  if ((await toggle.getAttribute('aria-pressed')) !== 'true') await toggle.click();
  const blockTab = page.getByRole('tab', { name: 'Block' });
  if (await blockTab.isVisible()) await blockTab.click();
}

const sidebar = (page: Page) => page.getByRole('region', { name: 'Editor settings' });

async function chooseInPlaceholder(page: Page, title: string): Promise<void> {
  const combobox = canvas(page).getByRole('combobox', { name: 'Gallery' });
  await combobox.click();
  await combobox.fill(title);
  await canvas(page).getByRole('option', { name: title }).click();
}

test.describe('as editor', () => {
  test.use({ storageState: authFile('editor') });

  test('insert, select a gallery, preview, tune, publish and reload', async ({ page }) => {
    const monitor = monitorPage(page);
    await openNewPost(page);
    await canvas(page).getByRole('textbox', { name: 'Add title' }).fill('Block E2E post');

    const block = await insertGalleryBlock(page);
    await expect(block.getByText('Choose one of your galleries to show it here.')).toBeVisible();
    await expect(block.getByRole('link', { name: 'Create a new gallery' })).toHaveAttribute(
      'href',
      /admin\.php\?page=swg-galleries#\/galleries$/,
    );
    await expect(block.getByRole('link', { name: 'Edit this gallery' })).toHaveCount(0);
    await page.screenshot({ path: SHOT('placeholder') });

    await chooseInPlaceholder(page, published.title);
    // The server-rendered result of the one renderer, not a client-side copy.
    const gallery = canvas(page).locator('.wp-block-sw-gallery-gallery .swg-gallery');
    await expect(gallery).toBeVisible();
    await expect(gallery.locator('li.swg-item')).toHaveCount(2);
    await expect(block.getByRole('link', { name: 'Create a new gallery' })).toHaveCount(0);

    await openSidebar(page);
    const panel = sidebar(page);
    await expect(panel.getByRole('combobox', { name: 'Gallery' })).toHaveValue(published.title);
    await expect(panel.getByRole('link', { name: 'Edit this gallery' })).toHaveAttribute(
      'href',
      new RegExp(`admin\\.php\\?page=swg-galleries#/galleries/${published.id}$`),
    );
    await expect(panel.getByRole('link', { name: 'Edit this gallery' })).toHaveAttribute(
      'target',
      '_blank',
    );

    await panel.getByRole('spinbutton', { name: 'Columns' }).fill('2');
    await panel.getByRole('spinbutton', { name: 'Gap between images (px)' }).fill('12');
    await expect(gallery).toHaveAttribute('style', /--swg-columns:2/);
    await expect(gallery).toHaveAttribute('style', /--swg-gap:12px/);
    await page.screenshot({ path: SHOT('selected-with-inspector') });
    await panel.screenshot({ path: SHOT('inspector') });

    await page.getByRole('button', { name: 'Publish', exact: true }).first().click();
    await page
      .getByRole('region', { name: 'Editor publish' })
      .getByRole('button', { name: 'Publish', exact: true })
      .click();
    await expect(page.getByText(/is now live|Post published/).first()).toBeVisible();
    const postId = Number(new URL(page.url()).searchParams.get('post'));
    expect(postId).toBeGreaterThan(0);

    // Stored: the gallery id and the validated overrides only, no media ids or markup.
    const raw = await rawContent(page, postId);
    const match = /<!-- wp:sw-gallery\/gallery (\{.*?\}) \/-->/.exec(raw);
    expect(match, raw).not.toBeNull();
    const stored = JSON.parse(match?.[1] ?? '{}') as Record<string, unknown>;
    expect(stored).toEqual({ galleryId: published.id, columns: 2, gap: 12 });
    expect(raw).not.toMatch(/attachment|wp-image|<img|"ids"|"images"/);

    // Reload: the block comes back with the same selection and overrides.
    await openPost(page, postId);
    const reloaded = canvas(page).locator('.wp-block-sw-gallery-gallery').first();
    await expect(reloaded.locator('.swg-gallery li.swg-item')).toHaveCount(2);
    await reloaded.click();
    await openSidebar(page);
    await expect(sidebar(page).getByRole('combobox', { name: 'Gallery' })).toHaveValue(
      published.title,
    );
    await expect(sidebar(page).getByRole('spinbutton', { name: 'Columns' })).toHaveValue('2');
    await expect(
      sidebar(page).getByRole('spinbutton', { name: 'Gap between images (px)' }),
    ).toHaveValue('12');

    // The published page renders the block (frontend gallery markup is covered by the frontend suites).
    const front = await page.request.get(`/?p=${postId}`);
    expect(front.status()).toBe(200);
    expect(await front.text()).toContain('wp-block-sw-gallery-gallery');

    const errors = monitor.problems.filter(
      (problem) => !/Failed to load resource|favicon|net::ERR_NETWORK_CHANGED/.test(problem),
    );
    expect(errors, 'console problems in the editor').toEqual([]);
  });

  test('a draft gallery is selectable and marked, and the preview says so', async ({ page }) => {
    await openNewPost(page);
    const block = await insertGalleryBlock(page);
    const combobox = block.getByRole('combobox', { name: 'Gallery' });
    await combobox.click();
    await combobox.fill(draft.title);
    await expect(
      canvas(page).getByRole('option', { name: `${draft.title} (draft)` }),
    ).toBeVisible();
    await canvas(page)
      .getByRole('option', { name: `${draft.title} (draft)` })
      .click();
    // Visitors cannot see a draft gallery, so the renderer's preview for the editor explains why.
    await expect(canvas(page).locator('[data-type="sw-gallery/gallery"]')).toBeVisible();
    await expect(canvas(page).locator('.swg-diagnostic, .swg-notice').first()).toBeVisible();
  });

  test('the gallery list failing is explained and can be retried', async ({ page }) => {
    let failing = true;
    await page.route(/swg(%2F|\/)v1(%2F|\/)galleries(&|%26)/, async (route) => {
      if (failing) await route.fulfill({ status: 500, json: { code: 'swg_internal_error' } });
      else await route.continue();
    });
    await openNewPost(page);
    const block = await insertGalleryBlock(page);
    await expect(block.getByText('The galleries could not be loaded.')).toBeVisible();
    await page.screenshot({ path: SHOT('error-state') });
    failing = false;
    await block.getByRole('button', { name: 'Try again' }).click();
    await expect(block.getByText('The galleries could not be loaded.')).toHaveCount(0);
    const combobox = block.getByRole('combobox', { name: 'Gallery' });
    await combobox.click();
    await combobox.fill(published.title);
    await expect(canvas(page).getByRole('option', { name: published.title })).toBeVisible();
  });

  test('axe: no serious or critical violations in the block inspector', async ({
    page,
  }, testInfo) => {
    await openNewPost(page);
    await insertGalleryBlock(page);
    await chooseInPlaceholder(page, published.title);
    await openSidebar(page);
    await expect(sidebar(page).getByRole('spinbutton', { name: 'Columns' })).toBeVisible();

    const results = await new AxeBuilder({ page })
      .include('.interface-complementary-area')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    await testInfo.attach('axe-violations.json', {
      body: JSON.stringify(results.violations, null, 2),
      contentType: 'application/json',
    });
    for (const violation of results.violations) {
      console.log(
        `axe [${violation.impact ?? 'n/a'}] ${violation.id}: ${violation.help} (${violation.nodes.length} node(s))`,
      );
    }
    const blocking = results.violations.filter(
      (v) => v.impact === 'serious' || v.impact === 'critical',
    );
    expect(blocking.map((v) => `${v.id}: ${v.help}`)).toEqual([]);
  });

  test('the editor preview REST route renders, hints and rejects hostile input', async ({
    page,
  }) => {
    await openNewPost(page);
    const nonce = await page.evaluate(
      () => (window as unknown as { wpApiSettings: { nonce: string } }).wpApiSettings.nonce,
    );
    const render = (query: string) =>
      page.request.get(
        restUrl('/wp/v2/block-renderer/sw-gallery/gallery', `context=edit&${query}`),
        {
          headers: { 'X-WP-Nonce': nonce },
        },
      );

    const hint = await render('attributes[galleryId]=0');
    expect(hint.status()).toBe(200);
    expect(((await hint.json()) as { rendered: string }).rendered).toContain('No gallery selected');

    const ok = await render(`attributes[galleryId]=${published.id}&attributes[columns]=3`);
    expect(ok.status()).toBe(200);
    const html = ((await ok.json()) as { rendered: string }).rendered;
    expect(html).toContain('wp-block-sw-gallery-gallery');
    expect(html).toContain('--swg-columns:3');

    const hostile = await render(
      `attributes[galleryId]=${published.id}&attributes[layout]=${encodeURIComponent('"><script>alert(1)</script>')}`,
    );
    expect(hostile.status()).toBe(200);
    expect(((await hostile.json()) as { rendered: string }).rendered).not.toContain('<script>');

    expect(
      (await render(`attributes[galleryId]=${published.id}&attributes[columns]=99`)).status(),
    ).toBe(400);
  });
});

test.describe('other roles', () => {
  test.describe('contributor', () => {
    test.use({ storageState: authFile('contributor') });

    test('sees only their own galleries in the selector', async ({ page, browser, baseURL }) => {
      const { fixtures, close } = await fixturesAs(browser, baseURL, 'contributor');
      const own = await fixtures.createGallery(`${prefix} own`, 'draft', 0);
      await close();

      await openNewPost(page);
      const block = await insertGalleryBlock(page);
      const combobox = block.getByRole('combobox', { name: 'Gallery' });
      await combobox.click();
      await combobox.fill(prefix);
      await expect(
        canvas(page).getByRole('option', { name: `${own.title} (draft)` }),
      ).toBeVisible();
      await expect(canvas(page).getByRole('option', { name: `${published.title}` })).toHaveCount(0);
    });
  });

  test.describe('subscriber', () => {
    test.use({ storageState: authFile('subscriber') });

    test('cannot use the editor, the selector routes or the preview', async ({ page }) => {
      const response = await page.goto('/wp-admin/post-new.php');
      expect(response?.status()).toBe(403);

      const nonce = await restNonce(page);
      const headers = { 'X-WP-Nonce': nonce };
      expect((await page.request.get(restUrl('/swg/v1/galleries'), { headers })).status()).toBe(
        403,
      );
      expect((await page.request.get(restUrl('/swg/v1/layouts'), { headers })).status()).toBe(403);
      const preview = await page.request.get(
        restUrl(
          '/wp/v2/block-renderer/sw-gallery/gallery',
          `context=edit&attributes[galleryId]=${published.id}`,
        ),
        { headers },
      );
      expect(preview.status()).toBe(403);
    });
  });

  test.describe('german editor', () => {
    test.use({ storageState: authFile('german') });

    test('the block UI is translated through the script translations', async ({ page }) => {
      await openNewPost(page);
      const block = await insertGalleryBlockByApi(page);
      await expect(
        block.getByText('Wähle eine deiner Galerien, um sie hier anzuzeigen.'),
      ).toBeVisible();
      await expect(block.getByRole('link', { name: 'Neue Galerie erstellen' })).toBeVisible();
    });
  });
});
