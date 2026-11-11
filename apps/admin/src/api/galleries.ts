import type { ApiClient } from './client';

/** Shapes of `docs/rest-api.md` (routes `swg/v1/galleries`). camelCase, as the server sends them. */

export type GalleryStatus = 'draft' | 'pending' | 'publish' | 'private' | 'trash';
/** Statuses a client may write (trash is reached through DELETE only). */
export type WritableGalleryStatus = 'draft' | 'pending' | 'publish' | 'private';

export interface Gallery {
  id: number;
  title: string;
  description: string;
  status: GalleryStatus;
  ownerId: number;
  coverAttachmentId: number | null;
  /** WordPress medium size; `null` unless the actor may use the attachment. */
  coverUrl: string | null;
  layout: string;
  sortMode: string;
  settings: Record<string, unknown>;
  passwordProtected: boolean;
  /** ISO 8601 UTC, e.g. `2026-11-11T09:30:00Z`. */
  modified: string;
  /** Optimistic-concurrency token: send it back as `expectedRevision`. */
  revision: number;
  itemCount: number;
  /** UX hints; the server enforces everything again. */
  canEdit: boolean;
  canDelete: boolean;
  canPublish: boolean;
}

export interface GalleryListQuery {
  search?: string;
  /** Omitted = the server default (everything except the trash). */
  status?: GalleryStatus[];
  owner?: number;
  page?: number;
  perPage?: number;
}

export interface GalleryPage {
  items: Gallery[];
  /** `X-WP-Total` */
  total: number;
  /** `X-WP-TotalPages` */
  totalPages: number;
}

export interface CreateGalleryInput {
  title: string;
  description?: string;
  status?: WritableGalleryStatus;
}

export interface UpdateGalleryInput {
  title?: string;
  description?: string;
  status?: WritableGalleryStatus;
  coverAttachmentId?: number | null;
}

export interface TrashResult {
  id: number;
  trashed: boolean;
  status: 'trash';
  revision: number;
}

export interface DeleteResult {
  id: number;
  deleted: true;
}

function headerNumber(headers: Headers, name: string, fallback: number): number {
  const value = Number.parseInt(headers.get(name) ?? '', 10);
  return Number.isFinite(value) && value >= 0 ? value : fallback;
}

export function createGalleriesApi(client: ApiClient) {
  return {
    async list(query: GalleryListQuery = {}, signal?: AbortSignal): Promise<GalleryPage> {
      const { data, headers } = await client.requestFull<Gallery[]>('GET', 'galleries', {
        query: {
          search: query.search,
          status: query.status?.join(','),
          owner: query.owner,
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
    create(input: CreateGalleryInput): Promise<Gallery> {
      return client.post<Gallery>('galleries', input);
    },
    get(id: number, signal?: AbortSignal): Promise<Gallery> {
      return client.get<Gallery>(`galleries/${id}`, undefined, signal);
    },
    update(id: number, expectedRevision: number, input: UpdateGalleryInput): Promise<Gallery> {
      return client.patch<Gallery>(`galleries/${id}`, { ...input, expectedRevision });
    },
    /** Moves to the trash; attachments are never touched. */
    trash(id: number, expectedRevision: number): Promise<TrashResult> {
      return client.delete<TrashResult>(`galleries/${id}`, { expectedRevision });
    },
    deletePermanently(id: number, expectedRevision: number): Promise<DeleteResult> {
      return client.delete<DeleteResult>(`galleries/${id}`, { expectedRevision, force: true });
    },
    duplicate(id: number, title?: string): Promise<Gallery> {
      return client.post<Gallery>(
        `galleries/${id}/duplicate`,
        title === undefined ? {} : { title },
      );
    },
    /** Always yields a draft. */
    restore(id: number, expectedRevision: number): Promise<Gallery> {
      return client.post<Gallery>(`galleries/${id}/restore`, { expectedRevision });
    },
  };
}

export type GalleriesApi = ReturnType<typeof createGalleriesApi>;
