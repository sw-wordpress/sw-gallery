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
