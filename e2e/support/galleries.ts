import { deflateSync } from 'node:zlib';
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

/** Item of a gallery as the REST API returns it (docs/rest-api.md, "Item resource"); only what the tests assert. */
export interface ItemRecord {
  id: number;
  attachmentId: number;
  position: number;
  overrides: { title: string | null; caption: string | null; alt: string | null };
  missing: boolean;
}

export interface GalleryDetail extends GalleryRecord {
  description: string;
  layout: string;
  sortMode: string;
  settings: Record<string, unknown>;
  coverAttachmentId: number | null;
  itemCount: number;
}

export interface MediaRecord {
  id: number;
  title: string;
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

  async detail(id: number): Promise<GalleryDetail> {
    return this.json<GalleryDetail>(await this.get(id), [200]);
  }

  /** The items of a gallery in stored order (all pages of 100). */
  async items(galleryId: number): Promise<ItemRecord[]> {
    const all: ItemRecord[] = [];
    for (let page = 1; ; page += 1) {
      const response = await this.request.get(
        joinUrl(this.bootstrap.restRoot, `galleries/${galleryId}/items`, { page, per_page: 100 }),
        { headers: this.headers() },
      );
      all.push(...(await this.json<ItemRecord[]>(response, [200])));
      if (page >= Number(response.headers()['x-wp-totalpages'] ?? 1)) return all;
    }
  }

  /** Adds attachments to a gallery with the current revision (fixtures only: the editor under test never uses this). */
  async assign(galleryId: number, attachmentIds: number[]): Promise<void> {
    const gallery = await this.detail(galleryId);
    const response = await this.request.post(
      joinUrl(this.bootstrap.restRoot, `galleries/${galleryId}/items`),
      { headers: this.headers(), data: { attachmentIds, expectedRevision: gallery.revision } },
    );
    await this.json(response, [200]);
  }

  /** Sets gallery-local texts of one item (the item's own text overrides; REST `PATCH items/{id}`). */
  async setItemTexts(
    galleryId: number,
    itemId: number,
    overrides: { title?: string; caption?: string; alt?: string },
  ): Promise<void> {
    const gallery = await this.detail(galleryId);
    const response = await this.request.patch(
      joinUrl(this.bootstrap.restRoot, `galleries/${galleryId}/items/${itemId}`),
      { headers: this.headers(), data: { overrides, expectedRevision: gallery.revision } },
    );
    await this.json(response, [200]);
  }

  /** Uploads a tiny real image through `POST /media` (type and decodability are checked by the server). */
  async uploadImage(title: string, buffer: Buffer, filename = 'e2e.png'): Promise<MediaRecord> {
    const response = await this.request.post(joinUrl(this.bootstrap.restRoot, 'media'), {
      headers: this.headers(),
      multipart: { file: { name: filename, mimeType: 'image/png', buffer }, title },
    });
    const media = await this.json<{ id: number; title: string }>(response, [201]);
    return { id: media.id, title: media.title };
  }

  private wpRoot(): string {
    return this.bootstrap.restRoot.replace('swg/v1', 'wp/v2');
  }

  /** True while the attachment exists (core REST). */
  async mediaExists(id: number): Promise<boolean> {
    const response = await this.request.get(joinUrl(this.wpRoot(), `media/${id}`), {
      headers: this.headers(),
    });
    return response.status() === 200;
  }

  async deleteMedia(id: number): Promise<void> {
    await this.request.delete(joinUrl(this.wpRoot(), `media/${id}`, { force: 'true' }), {
      headers: this.headers(),
    });
  }

  /** Permanently deletes the uploaded fixtures whose title starts with the prefix. */
  async purgeMedia(prefix: string): Promise<void> {
    const response = await this.request.get(
      joinUrl(this.bootstrap.restRoot, 'media', { search: prefix, per_page: 100 }),
      { headers: this.headers() },
    );
    if (response.status() !== 200) return;
    for (const media of (await response.json()) as MediaRecord[]) {
      if (!media.title.startsWith(prefix)) continue;
      await this.request.delete(joinUrl(this.wpRoot(), `media/${media.id}`, { force: 'true' }), {
        headers: this.headers(),
      });
    }
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

function crc32(buffer: Buffer): number {
  let crc = ~0;
  for (const byte of buffer) {
    crc ^= byte;
    for (let bit = 0; bit < 8; bit += 1) crc = crc & 1 ? (crc >>> 1) ^ 0xedb88320 : crc >>> 1;
  }
  return ~crc >>> 0;
}

function chunk(type: string, data: Buffer): Buffer {
  const length = Buffer.alloc(4);
  length.writeUInt32BE(data.length);
  const body = Buffer.concat([Buffer.from(type, 'ascii'), data]);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(body));
  return Buffer.concat([length, body, crc]);
}

/** A valid single-colour PNG (default 24x24, a few hundred bytes): fixtures stay tiny but decodable. */
export function tinyPng(rgb: [number, number, number], size = 24): Buffer {
  const header = Buffer.alloc(13);
  header.writeUInt32BE(size, 0);
  header.writeUInt32BE(size, 4);
  header[8] = 8; // bit depth
  header[9] = 2; // truecolour
  const row = Buffer.concat([
    Buffer.from([0]),
    Buffer.from(Array.from({ length: size }, () => rgb).flat()),
  ]);
  const raw = Buffer.concat(Array.from({ length: size }, () => row));
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', header),
    chunk('IDAT', deflateSync(raw)),
    chunk('IEND', Buffer.alloc(0)),
  ]);
}
