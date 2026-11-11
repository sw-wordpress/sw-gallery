import { inject } from 'vue';
import type { InjectionKey } from 'vue';
import { getApi } from '@/api';
import { createGalleriesApi } from '@/api/galleries';
import type { GalleriesApi } from '@/api/galleries';

/** Tests (and later the editor) can provide another implementation; production builds it from the shared client. */
export const GALLERIES_API: InjectionKey<GalleriesApi> = Symbol('swg-galleries-api');

export function useGalleriesApi(): GalleriesApi {
  return inject(GALLERIES_API, null) ?? createGalleriesApi(getApi());
}
