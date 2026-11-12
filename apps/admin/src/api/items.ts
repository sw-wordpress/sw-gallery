import type { ApiClient } from './client';
import { ApiError } from './client';

/** Shapes of `docs/rest-api.md` ("Items and order"). camelCase as sent by the server. */

/** `null` = inherit the global attachment text, `''` = intentional blank. */
export interface ItemTexts {
  title: string | null;
  caption: string | null;
  alt: string | null;
}

export interface GlobalTexts {
  title: string;
  caption: string;
  alt: string;
}

export interface ItemMedia {
  mimeType: string;
  width: number;
  height: number;
  thumbnailUrl: string | null;
  fullUrl: string | null;
}

export interface GalleryItem {
  /** Item (membership) id, not the attachment id. */
  id: number;
  attachmentId: number;
  position: number;
  overrides: ItemTexts;
  effective: GlobalTexts;
  global: GlobalTexts | null;
  settings: Record<string, unknown>;
  media: ItemMedia | null;
  missing: boolean;
}

export interface ItemPage {
  items: GalleryItem[];
  total: number;
  totalPages: number;
  /** `X-SWG-Revision`: gallery revision of this snapshot. */
  revision: number;
}

/** The server answers lists of ids; counts are tolerated as well. */
type IdsOrCount = number[] | number;

export interface AssignResult {
  added: IdsOrCount;
  alreadyAssigned: IdsOrCount;
  missing: IdsOrCount;
  revision: number;
}

export interface BulkRemoveResult {
  removed: IdsOrCount;
  notFound: IdsOrCount;
  revision: number;
}

export interface OrderResult {
  itemCount: number;
  revision: number;
}

export interface ItemPatchBody {
  overrides?: Partial<ItemTexts>;
  settings?: Record<string, unknown>;
}

/** Server limits (docs/rest-api.md). */
export const ITEM_PAGE_SIZE = 100;
export const MAX_ORDER_ITEMS = 10000;
export const MAX_BULK = 500;

export function countOf(value: IdsOrCount): number {
  return Array.isArray(value) ? value.length : value;
}

function headerNumber(headers: Headers, name: string, fallback: number): number {
  const value = Number.parseInt(headers.get(name) ?? '', 10);
  return Number.isFinite(value) && value >= 0 ? value : fallback;
}

export function createItemsApi(client: ApiClient) {
  return {
    async list(
      galleryId: number,
      page: number,
      perPage = ITEM_PAGE_SIZE,
      signal?: AbortSignal,
    ): Promise<ItemPage> {
      const { data, headers } = await client.requestFull<GalleryItem[]>(
        'GET',
        `galleries/${galleryId}/items`,
        { query: { page, per_page: perPage }, signal },
      );
      if (!headers.has('X-SWG-Revision')) {
        throw new ApiError('swg_invalid_response', 'Missing revision header', 200);
      }
      const items = Array.isArray(data) ? data : [];
      return {
        items,
        total: headerNumber(headers, 'X-WP-Total', items.length),
        totalPages: headerNumber(headers, 'X-WP-TotalPages', items.length > 0 ? 1 : 0),
        revision: headerNumber(headers, 'X-SWG-Revision', 0),
      };
    },
    assign(galleryId: number, attachmentIds: number[], expectedRevision: number) {
      return client.post<AssignResult>(`galleries/${galleryId}/items`, {
        attachmentIds,
        expectedRevision,
      });
    },
    patch(galleryId: number, itemId: number, body: ItemPatchBody, expectedRevision: number) {
      return client.patch<{ item: GalleryItem; revision: number }>(
        `galleries/${galleryId}/items/${itemId}`,
        { ...body, expectedRevision },
      );
    },
    bulkRemove(galleryId: number, itemIds: number[], expectedRevision: number) {
      return client.post<BulkRemoveResult>(`galleries/${galleryId}/items/bulk-remove`, {
        itemIds,
        expectedRevision,
      });
    },
    /** `itemIds` must be the COMPLETE ordered list of the gallery's item ids. */
    setOrder(galleryId: number, itemIds: number[], expectedRevision: number) {
      return client.put<OrderResult>(`galleries/${galleryId}/order`, {
        itemIds,
        expectedRevision,
      });
    },
  };
}

export type ItemsApi = ReturnType<typeof createItemsApi>;
