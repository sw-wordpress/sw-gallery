import { inject } from 'vue';
import type { InjectionKey } from 'vue';
import { getApi } from '@/api';
import { createGalleriesApi } from '@/api/galleries';
import type { GalleriesApi } from '@/api/galleries';
import { createItemsApi } from '@/api/items';
import type { ItemsApi } from '@/api/items';
import { createMediaLibraryApi } from '@/api/media';
import type { MediaLibraryApi } from '@/api/media';
import { GALLERIES_API } from '@/features/galleries/injection';

/** Every endpoint the Media Overview talks to (typed layer only, never ad hoc fetch). */
export interface MediaFeatureApi {
  media: MediaLibraryApi;
  galleries: GalleriesApi;
  /** Only the assignment: the Media Overview never edits or removes items. */
  items: Pick<ItemsApi, 'assign'>;
}

/** Tests provide a fake; production builds everything from the shared client. */
export const MEDIA_API: InjectionKey<MediaFeatureApi> = Symbol('swg-media-api');

export function useMediaFeatureApi(): MediaFeatureApi {
  const injected = inject(MEDIA_API, null);
  if (injected !== null) return injected;
  const client = getApi();
  return {
    media: createMediaLibraryApi(client),
    galleries: inject(GALLERIES_API, null) ?? createGalleriesApi(client),
    items: createItemsApi(client),
  };
}
