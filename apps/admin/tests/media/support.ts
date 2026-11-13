import { vi } from 'vitest';
import type { Mock } from 'vitest';
import type {
  MediaLibraryApi,
  MediaLibraryItem,
  MediaListQuery,
  MediaPage,
} from '../../src/api/media';
import type { ItemsApi } from '../../src/api/items';
import type { MediaFeatureApi } from '../../src/features/media/injection';
import {
  makeGallery,
  mockApi as mockGalleriesApi,
  pageOf as galleryPage,
} from '../galleries/support';
import type { MockApi as MockGalleriesApi } from '../galleries/support';

export function makeMedia(id: number, overrides: Partial<MediaLibraryItem> = {}): MediaLibraryItem {
  return {
    id,
    title: `Image ${id}`,
    alt: `Alt ${id}`,
    caption: '',
    mimeType: 'image/png',
    width: 640,
    height: 480,
    thumbnailUrl: `https://x.test/uploads/thumb-${id}.png`,
    fullUrl: `https://x.test/uploads/full-${id}.png`,
    uploadedAt: '2026-11-10T08:00:00Z',
    uploadedBy: 2,
    usedInGalleries: 0,
    ...overrides,
  };
}

export function mediaPage(
  items: MediaLibraryItem[],
  total = items.length,
  totalPages = 1,
): MediaPage {
  return { items, total, totalPages: items.length === 0 && total === 0 ? 0 : totalPages };
}

export interface MockMediaFeature extends MediaFeatureApi {
  media: { [K in keyof MediaLibraryApi]: Mock<MediaLibraryApi[K]> };
  galleries: MockGalleriesApi;
  items: { assign: Mock<ItemsApi['assign']> };
}

export function mockMediaApi(items: MediaLibraryItem[] = []): MockMediaFeature {
  return {
    media: {
      list: vi.fn<MediaLibraryApi['list']>(async () => mediaPage(items)),
      upload: vi.fn<MediaLibraryApi['upload']>(async (file) => ({
        id: 900,
        title: file.name,
        alt: '',
        caption: '',
        mimeType: 'image/png',
        width: 1,
        height: 1,
        thumbnailUrl: null,
        fullUrl: null,
      })),
    },
    galleries: mockGalleriesApi({
      list: vi.fn(async () =>
        galleryPage([
          makeGallery({ id: 7, title: 'Summer', canEdit: true, revision: 3 }),
          makeGallery({ id: 8, title: 'Read only', canEdit: false }),
        ]),
      ),
      get: vi.fn(async (id: number) => makeGallery({ id, title: 'Summer', revision: 5 })),
    }),
    items: {
      assign: vi.fn<ItemsApi['assign']>(async (_id, ids) => ({
        added: ids,
        alreadyAssigned: [],
        missing: [],
        revision: 6,
      })),
    },
  };
}

export function lastMediaQuery(api: MockMediaFeature): MediaListQuery {
  return api.media.list.mock.calls[api.media.list.mock.calls.length - 1]?.[0] ?? {};
}
