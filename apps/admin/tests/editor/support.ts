import { vi } from 'vitest';
import type { Mock } from 'vitest';
import { ApiError, ConflictError } from '../../src/api/client';
import type { Gallery, UpdateGalleryInput } from '../../src/api/galleries';
import type { GalleryItem, ItemPatchBody, ItemPage } from '../../src/api/items';
import type { LayoutsResponse } from '../../src/api/layouts';
import type { MediaItem } from '../../src/api/media';
import type { EditorApi } from '../../src/features/editor/injection';
import { makeGallery } from '../galleries/support';

export { makeGallery };

export function makeItem(id: number, over: Partial<GalleryItem> = {}): GalleryItem {
  return {
    id,
    attachmentId: id + 1000,
    position: id,
    overrides: { title: null, caption: null, alt: null },
    effective: { title: `Global ${id}`, caption: '', alt: `alt ${id}` },
    global: { title: `Global ${id}`, caption: '', alt: `alt ${id}` },
    settings: {},
    media: {
      mimeType: 'image/jpeg',
      width: 800,
      height: 600,
      thumbnailUrl: `https://x.test/t${id}.jpg`,
      fullUrl: `https://x.test/f${id}.jpg`,
    },
    missing: false,
    ...over,
  };
}

export const LAYOUTS: LayoutsResponse = {
  fallbackId: 'grid',
  layouts: [
    {
      id: 'grid',
      label: 'Grid',
      settingsSchema: {
        type: 'object',
        properties: {
          columns: { type: 'integer', minimum: 1, maximum: 12, default: 3, 'x-responsive': true },
          gap: { type: 'integer', minimum: 0, maximum: 64, default: 8 },
        },
      },
    },
    {
      id: 'masonry',
      label: 'Masonry',
      settingsSchema: { type: 'object', properties: {} },
    },
  ],
};

/**
 * Stateful in-memory server behind the typed API: enforces `expectedRevision` like the real one
 * (409 on a stale value), bumps the revision per applied write and records every call, so tests
 * can assert the order of operations and the revision chain.
 */
export class FakeServer {
  gallery: Gallery;
  items: GalleryItem[];
  revision: number;
  nextItemId: number;
  calls: string[] = [];
  /** Errors to throw for a call label (`remove`, `override:5`, `order`, `details`, `assign`, `get`, `list`). */
  failures = new Map<string, unknown>();
  /** Callback run before a write (lets a test change the server "in the background"). */
  beforeWrite: ((label: string) => void) | null = null;
  /** Revisions seen as `expectedRevision`, in call order. */
  expected: number[] = [];

  constructor(itemCount = 4, galleryOver: Partial<Gallery> = {}) {
    this.items = Array.from({ length: itemCount }, (_, i) => makeItem(i + 1));
    this.revision = 5;
    this.nextItemId = itemCount + 1;
    this.gallery = makeGallery({ id: 7, revision: 5, itemCount, ...galleryOver });
  }

  private snapshot(): Gallery {
    return { ...this.gallery, revision: this.revision, itemCount: this.items.length };
  }

  private fail(label: string): void {
    const error = this.failures.get(label);
    if (error !== undefined) {
      this.failures.delete(label);
      throw error;
    }
  }

  private write(label: string, expected: number): void {
    this.calls.push(label);
    this.expected.push(expected);
    this.beforeWrite?.(label);
    this.fail(label);
    if (expected !== this.revision) {
      throw new ConflictError(
        'swg_revision_conflict',
        'stale',
        {},
        {
          status: 409,
          expectedRevision: expected,
          currentRevision: this.revision,
        },
      );
    }
  }

  /** A change made by someone else. */
  externalChange(change: () => void): void {
    change();
    this.revision += 1;
  }

  api(): EditorApi {
    const listPage = (page: number, perPage: number): ItemPage => {
      const slice = this.items.slice((page - 1) * perPage, page * perPage);
      return {
        items: slice,
        total: this.items.length,
        totalPages: Math.ceil(this.items.length / perPage),
        revision: this.revision,
      };
    };
    return {
      galleries: {
        list: async () => ({ items: [], total: 0, totalPages: 0 }),
        create: () => Promise.reject(new Error('unused')),
        get: async () => {
          this.calls.push('get');
          this.fail('get');
          return this.snapshot();
        },
        update: async (_id: number, expectedRevision: number, input: UpdateGalleryInput) => {
          this.write('details', expectedRevision);
          this.gallery = { ...this.gallery, ...input } as Gallery;
          this.revision += 1;
          return this.snapshot();
        },
        trash: () => Promise.reject(new Error('unused')),
        deletePermanently: () => Promise.reject(new Error('unused')),
        duplicate: () => Promise.reject(new Error('unused')),
        restore: () => Promise.reject(new Error('unused')),
      },
      items: {
        list: async (_id: number, page: number, perPage = 100) => {
          this.calls.push(`list:${page}`);
          this.fail('list');
          return listPage(page, perPage);
        },
        assign: async (_id: number, attachmentIds: number[], expectedRevision: number) => {
          this.write('assign', expectedRevision);
          const known = new Set(this.items.map((i) => i.attachmentId));
          const added: number[] = [];
          const already: number[] = [];
          const missing: number[] = [];
          for (const attachmentId of attachmentIds) {
            if (attachmentId < 0) missing.push(attachmentId);
            else if (known.has(attachmentId)) already.push(attachmentId);
            else {
              added.push(attachmentId);
              this.items.push(makeItem(this.nextItemId, { attachmentId }));
              this.nextItemId += 1;
            }
          }
          if (added.length > 0) this.revision += 1;
          return { added, alreadyAssigned: already, missing, revision: this.revision };
        },
        patch: async (
          _id: number,
          itemId: number,
          body: ItemPatchBody,
          expectedRevision: number,
        ) => {
          this.write(`override:${itemId}`, expectedRevision);
          const index = this.items.findIndex((i) => i.id === itemId);
          const current = this.items[index];
          if (current === undefined) throw new ApiError('swg_item_not_found', 'x', 404);
          const overrides = { ...current.overrides, ...body.overrides };
          const updated = makeItem(itemId, {
            ...current,
            overrides,
            effective: {
              title: overrides.title ?? current.global?.title ?? '',
              caption: overrides.caption ?? current.global?.caption ?? '',
              alt: overrides.alt ?? current.global?.alt ?? '',
            },
          });
          this.items[index] = updated;
          this.revision += 1;
          return { item: updated, revision: this.revision };
        },
        bulkRemove: async (_id: number, itemIds: number[], expectedRevision: number) => {
          this.write('remove', expectedRevision);
          const ids = new Set(itemIds);
          const removed = this.items.filter((i) => ids.has(i.id)).map((i) => i.id);
          this.items = this.items.filter((i) => !ids.has(i.id));
          if (removed.length > 0) this.revision += 1;
          return { removed, notFound: [], revision: this.revision };
        },
        setOrder: async (_id: number, itemIds: number[], expectedRevision: number) => {
          this.write('order', expectedRevision);
          const byId = new Map(this.items.map((i) => [i.id, i]));
          if (itemIds.length !== this.items.length || itemIds.some((id) => !byId.has(id))) {
            throw new ApiError('swg_invalid_order', 'x', 422);
          }
          this.items = itemIds.map((id) => byId.get(id) as GalleryItem);
          this.revision += 1;
          return { itemCount: this.items.length, revision: this.revision };
        },
      },
      layouts: { list: async () => LAYOUTS },
      media: { upload: () => Promise.reject(new Error('unused')) },
    };
  }

  ids(): number[] {
    return this.items.map((i) => i.id);
  }
}

export type SpyMedia = { upload: Mock<(file: File) => Promise<MediaItem>> };

export function spyMedia(): SpyMedia {
  return { upload: vi.fn() };
}

export function makeMedia(id: number): MediaItem {
  return {
    id,
    title: `Upload ${id}`,
    alt: '',
    caption: '',
    mimeType: 'image/png',
    width: 10,
    height: 10,
    thumbnailUrl: null,
    fullUrl: null,
  };
}
