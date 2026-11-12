import type { APIRequestContext, FrameLocator, Locator, Page } from '@playwright/test';
import { expect } from '@playwright/test';
import { PLUGIN_PAGE } from './env';

/** A 1x1 PNG: tiny, but a real image that WordPress accepts, decodes and creates sub-sizes for. */
const TINY_PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64',
);

interface Bootstrap {
  restRoot: string;
  nonce: string;
}

export interface BlockGallery {
  id: number;
  title: string;
}

/**
 * Creates galleries with a few tiny uploaded images through the plugin's REST API as the logged-in user of the page
 * (nonce from the admin app bootstrap). Everything created carries the given title prefix for the clean-up.
 */
export class BlockFixtures {
  private constructor(
    private readonly request: APIRequestContext,
    private readonly bootstrap: Bootstrap,
  ) {}

  static async forPage(page: Page): Promise<BlockFixtures> {
    await page.goto(PLUGIN_PAGE);
    await expect(page.locator('#swg-admin-root .swg-shell')).toBeVisible({ timeout: 30_000 });
    const bootstrap = await page.evaluate(() => {
      const value = (window as unknown as { swgAdmin?: { restRoot: string; nonce: string } })
        .swgAdmin;
      return value === undefined ? null : { restRoot: value.restRoot, nonce: value.nonce };
    });
    if (bootstrap === null) throw new Error('window.swgAdmin is missing');
    return new BlockFixtures(page.request, bootstrap);
  }

  private url(path: string): string {
    return `${this.bootstrap.restRoot.replace(/\/$/, '')}/${path}`;
  }

  private get headers(): Record<string, string> {
    return { 'X-WP-Nonce': this.bootstrap.nonce };
  }

  async uploadImage(title: string): Promise<number> {
    const response = await this.request.post(this.url('media'), {
      headers: this.headers,
      multipart: {
        file: { name: 'tiny.png', mimeType: 'image/png', buffer: TINY_PNG },
        title,
      },
    });
    if (response.status() !== 201) {
      throw new Error(`upload answered ${response.status()}: ${await response.text()}`);
    }
    return ((await response.json()) as { id: number }).id;
  }

  /** Creates a gallery and assigns `images` freshly uploaded images to it. */
  async createGallery(
    title: string,
    status: 'draft' | 'publish' | 'private',
    images: number,
  ): Promise<BlockGallery> {
    const created = await this.request.post(this.url('galleries'), {
      headers: this.headers,
      data: { title, status },
    });
    if (created.status() !== 201) {
      throw new Error(`create gallery answered ${created.status()}: ${await created.text()}`);
    }
    const gallery = (await created.json()) as { id: number; revision: number };
    if (images > 0) {
      const ids: number[] = [];
      for (let n = 1; n <= images; n += 1) ids.push(await this.uploadImage(`${title} image ${n}`));
      const assigned = await this.request.post(this.url(`galleries/${gallery.id}/items`), {
        headers: this.headers,
        data: { attachmentIds: ids, expectedRevision: gallery.revision },
      });
      if (assigned.status() !== 200) {
        throw new Error(`assign answered ${assigned.status()}: ${await assigned.text()}`);
      }
    }
    return { id: gallery.id, title };
  }

  /** Permanently deletes galleries whose title starts with the prefix; the uploaded images stay (throw-away stack). */
  async purge(prefix: string): Promise<void> {
    const listed = await this.request.get(
      `${this.url('galleries')}?search=${encodeURIComponent(prefix)}&per_page=100&status=draft,pending,publish,private,trash`,
      { headers: this.headers },
    );
    if (listed.status() !== 200) return;
    for (const row of (await listed.json()) as { id: number; title: string; revision: number }[]) {
      if (!row.title.startsWith(prefix)) continue;
      await this.request.delete(
        `${this.url(`galleries/${row.id}`)}?force=true&expectedRevision=${row.revision}`,
        { headers: this.headers },
      );
    }
  }
}

/** Opens the block editor on a new post and gets the welcome guide and fullscreen mode out of the way. */
export async function openNewPost(page: Page): Promise<void> {
  await page.goto('/wp-admin/post-new.php');
  await closeEditorChrome(page);
}

export async function openPost(page: Page, id: number): Promise<void> {
  await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
  await closeEditorChrome(page);
}

async function closeEditorChrome(page: Page): Promise<void> {
  await page.waitForFunction(
    () =>
      (
        window as unknown as { wp?: { data?: { select?: (s: string) => unknown } } }
      ).wp?.data?.select?.('core/editor') !== undefined,
  );
  await page.evaluate(() => {
    const wp = (
      window as unknown as {
        wp: {
          data: {
            dispatch: (store: string) => {
              set: (scope: string, key: string, value: boolean) => void;
            };
          };
        };
      }
    ).wp;
    wp.data.dispatch('core/preferences').set('core/edit-post', 'welcomeGuide', false);
    wp.data.dispatch('core/preferences').set('core/edit-post', 'fullscreenMode', false);
  });
  await expect(page.locator('iframe[name="editor-canvas"]')).toBeVisible();
  await expect(page.getByRole('dialog', { name: /welcome/i })).toHaveCount(0);
}

/** The editor canvas (an iframe for blocks of apiVersion 3). */
export function canvas(page: Page): FrameLocator {
  return page.frameLocator('iframe[name="editor-canvas"]');
}

/** Inserts the SW Gallery block through the block inserter, like a user. */
export async function insertGalleryBlock(page: Page): Promise<Locator> {
  await page.getByRole('button', { name: /^(Block Inserter|Toggle block inserter)$/ }).click();
  await page.getByRole('searchbox', { name: /^Search/ }).fill('SW Gallery');
  await page.getByRole('option', { name: 'SW Gallery' }).first().click();
  const block = canvas(page).locator('.wp-block-sw-gallery-gallery').first();
  await expect(block).toBeVisible();
  // Close the inserter again so the canvas keeps its width.
  await page.getByRole('button', { name: /^(Block Inserter|Toggle block inserter)$/ }).click();
  return block;
}

/** Inserts the block through the data API: for locales whose inserter labels differ from the English ones. */
export async function insertGalleryBlockByApi(page: Page): Promise<Locator> {
  await page.evaluate(() => {
    const wp = (
      window as unknown as {
        wp: {
          blocks: { createBlock: (name: string) => unknown };
          data: { dispatch: (store: string) => { insertBlock: (block: unknown) => void } };
        };
      }
    ).wp;
    wp.data.dispatch('core/block-editor').insertBlock(wp.blocks.createBlock('sw-gallery/gallery'));
  });
  const block = canvas(page).locator('.wp-block-sw-gallery-gallery').first();
  await expect(block).toBeVisible();
  return block;
}

/** URL of a REST route on a site with plain permalinks (the e2e stack has none): `/index.php?rest_route=...`. */
export function restUrl(route: string, query = ''): string {
  return `/index.php?rest_route=${route}${query === '' ? '' : `&${query}`}`;
}

/** Reads the raw content of a post through the core REST API (editor nonce from the page). */
export async function rawContent(page: Page, postId: number): Promise<string> {
  const nonce = await page.evaluate(
    () => (window as unknown as { wpApiSettings?: { nonce: string } }).wpApiSettings?.nonce ?? '',
  );
  const response = await page.request.get(restUrl(`/wp/v2/posts/${postId}`, 'context=edit'), {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect(response.status()).toBe(200);
  return ((await response.json()) as { content: { raw: string } }).content.raw;
}

/** A fresh REST nonce for the logged-in user (works for every role). */
export async function restNonce(page: Page): Promise<string> {
  const response = await page.request.get('/wp-admin/admin-ajax.php?action=rest-nonce');
  expect(response.status()).toBe(200);
  return (await response.text()).trim();
}
