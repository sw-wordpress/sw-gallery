import { vi } from 'vitest';
import type { Mock } from 'vitest';
import type { GalleriesApi, Gallery, GalleryPage } from '../../src/api/galleries';

export function makeGallery(overrides: Partial<Gallery> = {}): Gallery {
  return {
    id: 7,
    title: 'Summer 2025',
    description: 'Holiday pictures',
    status: 'draft',
    ownerId: 1,
    coverAttachmentId: null,
    coverUrl: null,
    layout: 'grid',
    sortMode: 'manual',
    perPage: null,
    hover: null,
    settings: {},
    passwordProtected: false,
    modified: '2026-11-11T09:30:00Z',
    revision: 3,
    itemCount: 4,
    canEdit: true,
    canDelete: true,
    canPublish: true,
    ...overrides,
  };
}

export function pageOf(items: Gallery[], total = items.length, totalPages = 1): GalleryPage {
  return { items, total, totalPages: items.length === 0 && total === 0 ? 0 : totalPages };
}

export type MockApi = { [K in keyof GalleriesApi]: Mock<GalleriesApi[K]> };

/** Every method is a spy; unconfigured writes fail loudly so a test cannot pass by accident. */
export function mockApi(overrides: Partial<MockApi> = {}): MockApi {
  const fail = (name: string) => () => Promise.reject(new Error(`${name} not configured`));
  return {
    list: vi.fn<GalleriesApi['list']>(async () => pageOf([])),
    create: vi.fn<GalleriesApi['create']>(fail('create')),
    get: vi.fn<GalleriesApi['get']>(fail('get')),
    update: vi.fn<GalleriesApi['update']>(fail('update')),
    trash: vi.fn<GalleriesApi['trash']>(fail('trash')),
    deletePermanently: vi.fn<GalleriesApi['deletePermanently']>(fail('deletePermanently')),
    duplicate: vi.fn<GalleriesApi['duplicate']>(fail('duplicate')),
    restore: vi.fn<GalleriesApi['restore']>(fail('restore')),
    ...overrides,
  };
}
