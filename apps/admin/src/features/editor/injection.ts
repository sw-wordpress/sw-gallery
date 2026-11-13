import { inject } from 'vue';
import type { InjectionKey } from 'vue';
import { getApi } from '@/api';
import { createGalleriesApi } from '@/api/galleries';
import type { GalleriesApi } from '@/api/galleries';
import { createItemsApi } from '@/api/items';
import type { ItemsApi } from '@/api/items';
import { createLayoutsApi } from '@/api/layouts';
import type { LayoutsApi } from '@/api/layouts';
import { createMediaApi } from '@/api/media';
import type { MediaApi } from '@/api/media';
import { createPreviewApi } from '@/api/preview';
import type { PreviewApi } from '@/api/preview';
import { GALLERIES_API } from '@/features/galleries/injection';

/** Every endpoint the editor talks to (typed layer only, never ad hoc fetch). */
export interface EditorApi {
  galleries: GalleriesApi;
  items: ItemsApi;
  layouts: LayoutsApi;
  media: MediaApi;
  preview: PreviewApi;
}

/** Tests provide a fake; production builds everything from the shared client. */
export const EDITOR_API: InjectionKey<EditorApi> = Symbol('swg-editor-api');

export function useEditorApi(): EditorApi {
  const injected = inject(EDITOR_API, null);
  if (injected !== null) return injected;
  const client = getApi();
  return {
    galleries: inject(GALLERIES_API, null) ?? createGalleriesApi(client),
    items: createItemsApi(client),
    layouts: createLayoutsApi(client),
    media: createMediaApi(client),
    preview: createPreviewApi(client),
  };
}
