import type { APIRequestContext, APIResponse, Page } from '@playwright/test';
import { expect } from '@playwright/test';
import { PLUGIN_PAGE } from './env';

/** The subset of a gallery the fixtures need (docs/rest-api.md). */
export interface GalleryRecord {
  id: number;
  title: string;
  status: string;
  revision: number;
  ownerId: number;
}

export interface GalleryInput {
  title: string;
  description?: string;
  status?: 'draft' | 'pending' | 'publish' | 'private';
}

interface Bootstrap {
  restRoot: string;
  nonce: string;
}

function joinUrl(root: string, path: string, query: Record<string, string | number> = {}): string {
  const base = `${root.endsWith('/') ? root : `${root}/`}${path}`;
  const qs = new URLSearchParams(Object.entries(query).map(([k, v]) => [k, String(v)])).toString();
  return qs === '' ? base : `${base}${base.includes('?') ? '&' : '?'}${qs}`;
}

/**
 * REST fixtures for the logged-in user of a page: the nonce and REST root come from the page's own
 * `window.swgAdmin` bootstrap and the requests share the page's cookies. Never touches the database directly.
 */
export class GalleryFixtures {
  constructor(
    private readonly request: APIRequestContext,
    private readonly bootstrap: Bootstrap,
  ) {}

  /** Opens the plugin page (once) and reads the bootstrap of the current user. */
  static async forPage(page: Page): Promise<GalleryFixtures> {
    await page.goto(PLUGIN_PAGE);
    await expect(page.locator('#swg-admin-root .swg-shell')).toBeVisible();
    const bootstrap = await page.evaluate(() => {
      const value = (window as unknown as { swgAdmin?: { restRoot: string; nonce: string } })
        .swgAdmin;
      return value === undefined ? null : { restRoot: value.restRoot, nonce: value.nonce };
    });
    if (bootstrap === null) throw new Error('window.swgAdmin is missing');
    return new GalleryFixtures(page.request, bootstrap);
  }

  private headers(): Record<string, string> {
    return { 'X-WP-Nonce': this.bootstrap.nonce };
  }

  private async json<T>(response: APIResponse, okStatuses: number[]): Promise<T> {
    if (!okStatuses.includes(response.status())) {
      throw new Error(
        `REST ${response.url()} answered ${response.status()}: ${await response.text()}`,
      );
    }
    return (await response.json()) as T;
  }

  async create(input: GalleryInput): Promise<GalleryRecord> {
    const response = await this.request.post(joinUrl(this.bootstrap.restRoot, 'galleries'), {
      headers: this.headers(),
      data: input,
    });
    return this.json<GalleryRecord>(response, [201]);
  }

  async createMany(prefix: string, count: number): Promise<GalleryRecord[]> {
    const created: GalleryRecord[] = [];
    for (let n = 1; n <= count; n += 1) {
      created.push(await this.create({ title: `${prefix} ${String(n).padStart(2, '0')}` }));
    }
    return created;
  }

  /** PATCH with the given revision; returns the HTTP status so tests can also provoke refusals. */
  async update(
    id: number,
    expectedRevision: number,
    data: Record<string, unknown>,
  ): Promise<number> {
    const response = await this.request.patch(joinUrl(this.bootstrap.restRoot, `galleries/${id}`), {
      headers: this.headers(),
      data: { ...data, expectedRevision },
    });
    return response.status();
  }

  async list(query: Record<string, string | number> = {}): Promise<GalleryRecord[]> {
    const response = await this.request.get(joinUrl(this.bootstrap.restRoot, 'galleries', query), {
      headers: this.headers(),
    });
    return this.json<GalleryRecord[]>(response, [200]);
  }

  async get(id: number): Promise<APIResponse> {
    return this.request.get(joinUrl(this.bootstrap.restRoot, `galleries/${id}`), {
      headers: this.headers(),
    });
  }

  async trash(gallery: GalleryRecord): Promise<void> {
    const response = await this.request.delete(
      joinUrl(this.bootstrap.restRoot, `galleries/${gallery.id}`, {
        expectedRevision: gallery.revision,
      }),
      { headers: this.headers() },
    );
    await this.json(response, [200]);
  }

  /** Permanently deletes everything whose title starts with the prefix (live and trashed). */
  async purge(prefix: string): Promise<void> {
    const all = await this.list({
      search: prefix,
      status: 'draft,pending,publish,private,trash',
      per_page: 100,
    });
    for (const gallery of all) {
      if (!gallery.title.startsWith(prefix)) continue;
      const response = await this.request.delete(
        joinUrl(this.bootstrap.restRoot, `galleries/${gallery.id}`, {
          expectedRevision: gallery.revision,
          force: 'true',
        }),
        { headers: this.headers() },
      );
      if (![200, 404].includes(response.status())) {
        throw new Error(`purge ${gallery.id} answered ${response.status()}`);
      }
    }
  }
}

/** Unique title prefix per test run: parallel files and repeated runs never see each other's data. */
export function uniquePrefix(label: string): string {
  return `E2E ${label} ${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`;
}
