import type { ApiClient, UploadOptions } from './client';

/** `POST /media` resource (docs/rest-api.md "Media"). */
export interface MediaItem {
  id: number;
  title: string;
  alt: string;
  caption: string;
  mimeType: string;
  width: number;
  height: number;
  thumbnailUrl: string | null;
  fullUrl: string | null;
}

export function createMediaApi(client: ApiClient) {
  return {
    /** Multipart upload with real XHR progress. */
    upload(file: File, options: UploadOptions = {}): Promise<MediaItem> {
      return client.upload<MediaItem>('media', file, options);
    },
  };
}

export type MediaApi = ReturnType<typeof createMediaApi>;

/** Row of `GET /media` (docs/rest-api.md "Media"): the Media Overview resource. No file paths. */
export interface MediaLibraryItem extends MediaItem {
  /** ISO 8601 UTC. */
  uploadedAt: string;
  /** Uploader's user id. */
  uploadedBy: number;
  /** Number of live, non-trashed galleries that contain the image (global count). */
  usedInGalleries: number;
}

export type MediaOrderBy = 'date' | 'title';
export type MediaOrder = 'asc' | 'desc';

export interface MediaListQuery {
  search?: string;
  orderby?: MediaOrderBy;
  order?: MediaOrder;
  uploadedBy?: number;
  page?: number;
  perPage?: number;
}

export interface MediaPage {
  items: MediaLibraryItem[];
  /** `X-WP-Total` */
  total: number;
  /** `X-WP-TotalPages` */
  totalPages: number;
}

function headerNumber(headers: Headers, name: string, fallback: number): number {
  const value = Number.parseInt(headers.get(name) ?? '', 10);
  return Number.isFinite(value) && value >= 0 ? value : fallback;
}

/** Media Overview endpoints: the permission-filtered list plus the same upload as the editor. */
export function createMediaLibraryApi(client: ApiClient) {
  return {
    async list(query: MediaListQuery = {}, signal?: AbortSignal): Promise<MediaPage> {
      const { data, headers } = await client.requestFull<MediaLibraryItem[]>('GET', 'media', {
        query: {
          search: query.search,
          orderby: query.orderby,
          order: query.order,
          uploadedBy: query.uploadedBy,
          page: query.page,
          per_page: query.perPage,
        },
        signal,
      });
      const items = Array.isArray(data) ? data : [];
      return {
        items,
        total: headerNumber(headers, 'X-WP-Total', items.length),
        totalPages: headerNumber(headers, 'X-WP-TotalPages', items.length > 0 ? 1 : 0),
      };
    },
    upload(file: File, options: UploadOptions = {}): Promise<MediaItem> {
      return client.upload<MediaItem>('media', file, options);
    },
  };
}

export type MediaLibraryApi = ReturnType<typeof createMediaLibraryApi>;
