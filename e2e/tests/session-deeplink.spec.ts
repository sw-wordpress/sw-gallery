import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { BlockFixtures, canvas, insertGalleryBlockByApi, openNewPost } from '../support/block';
import type { BlockGallery } from '../support/block';
import { authFile, PLUGIN_PAGE } from '../support/env';
import { uniquePrefix } from '../support/galleries';

/** Editor deep link (M2-19): `#/galleries/<id>` survives reload/back/forward and is what the block links to. */
const PREFIX = uniquePrefix('deeplink');
test.use({ storageState: authFile('administrator') });

let gallery: BlockGallery;
let fixtures: BlockFixtures;

test.beforeAll(async ({ browser, baseURL }) => {
  const context = await browser.newContext({ storageState: authFile('administrator'), baseURL });
  fixtures = await BlockFixtures.forPage(await context.newPage());
  gallery = await fixtures.createGallery(`${PREFIX} linked`, 'publish', 1);
});

test.afterAll(async () => {
  await fixtures.purge(PREFIX);
});

const root = (page: Page): Locator => page.locator('#swg-admin-root');
const heading = (page: Page): Locator => root(page).locator('.swg-ed__title');

test('reload, back and forward keep the editor route stable', async ({ page }) => {
  await page.goto(`${PLUGIN_PAGE}#/galleries`);
  await expect(root(page).getByRole('heading', { level: 2, name: 'Galleries' })).toBeVisible();
  await page.goto(`${PLUGIN_PAGE}#/galleries/${gallery.id}`);
  await expect(heading(page)).toHaveText(gallery.title);
  await page.reload();
  await expect(heading(page)).toHaveText(gallery.title);
  await expect(page).toHaveURL(new RegExp(`#/galleries/${gallery.id}$`));
  await page.goBack();
  await expect(root(page).getByRole('heading', { level: 2, name: 'Galleries' })).toBeVisible();
  await page.goForward();
  await expect(heading(page)).toHaveText(gallery.title);
  await page.goto(`${PLUGIN_PAGE}#/galleries/99999999`);
  await expect(root(page).getByRole('heading', { name: 'Gallery not found' })).toBeVisible();
});

test('"Edit this gallery" in the block editor opens exactly that gallery in the admin app', async ({
  page,
}) => {
  await openNewPost(page);
  await insertGalleryBlockByApi(page);
  await page.evaluate((galleryId) => {
    const wp = (
      window as unknown as {
        wp: {
          data: {
            select: (s: string) => { getBlocks: () => Array<{ clientId: string }> };
            dispatch: (s: string) => {
              updateBlockAttributes: (id: string, a: Record<string, unknown>) => void;
              selectBlock: (id: string) => void;
            };
          };
        };
      }
    ).wp;
    const block = wp.data.select('core/block-editor').getBlocks()[0];
    if (block === undefined) throw new Error('no block');
    wp.data.dispatch('core/block-editor').updateBlockAttributes(block.clientId, { galleryId });
    wp.data.dispatch('core/block-editor').selectBlock(block.clientId);
  }, gallery.id);
  const toggle = page.getByRole('button', { name: 'Settings', exact: true });
  if ((await toggle.getAttribute('aria-pressed')) !== 'true') await toggle.click();
  const blockTab = page.getByRole('tab', { name: 'Block' });
  if (await blockTab.isVisible()) await blockTab.click();
  await expect(canvas(page).locator('.wp-block-sw-gallery-gallery').first()).toBeVisible();

  const link = page
    .getByRole('region', { name: 'Editor settings' })
    .getByRole('link', { name: 'Edit this gallery' });
  await expect(link).toHaveAttribute('href', new RegExp(`#/galleries/${gallery.id}$`));
  const [popup] = await Promise.all([page.waitForEvent('popup'), link.click()]);
  await expect(popup).toHaveURL(new RegExp(`page=swg-galleries#/galleries/${gallery.id}$`));
  await expect(popup.locator('#swg-admin-root .swg-ed__title')).toHaveText(gallery.title);
});
