import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

/** The few fields of a gallery the selector needs (docs/rest-api.md). */
export interface GallerySummary {
  id: number;
  title: string;
  status: string;
}

export interface LayoutSummary {
  id: string;
  label: string;
}

export type Fetcher = (options: { path: string; signal?: AbortSignal }) => Promise<unknown>;

export const PAGE_SIZE = 20;
/** Trashed galleries are never offered. Draft galleries are listed (and marked) so work in progress is selectable. */
export const SELECTABLE_STATUSES = 'draft,pending,publish,private';

const defaultFetcher: Fetcher = (options) => apiFetch(options);

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function parseGallery(value: unknown): GallerySummary | null {
  if (!isRecord(value)) return null;
  const { id, title, status } = value;
  if (typeof id !== 'number' || !Number.isInteger(id) || id < 1) return null;
  return {
    id,
    title: typeof title === 'string' ? title : '',
    status: typeof status === 'string' ? status : 'draft',
  };
}

/** Unusable rows are skipped instead of failing the whole list. */
export function parseGalleries(value: unknown): GallerySummary[] {
  if (!Array.isArray(value)) throw new Error('Unexpected response');
  return value.flatMap((row) => {
    const gallery = parseGallery(row);
    return gallery === null ? [] : [gallery];
  });
}

export function parseLayouts(value: unknown): LayoutSummary[] {
  if (!isRecord(value) || !Array.isArray(value.layouts)) throw new Error('Unexpected response');
  return value.layouts.flatMap((row: unknown) => {
    if (!isRecord(row) || typeof row.id !== 'string') return [];
    return [{ id: row.id, label: typeof row.label === 'string' ? row.label : row.id }];
  });
}

export function galleriesPath(search: string): string {
  const query = new URLSearchParams({
    search: search.trim().slice(0, 200),
    per_page: String(PAGE_SIZE),
    status: SELECTABLE_STATUSES,
  });
  return `/swg/v1/galleries?${query.toString()}`;
}

export async function listGalleries(
  search: string,
  fetcher: Fetcher = defaultFetcher,
  signal?: AbortSignal,
): Promise<GallerySummary[]> {
  return parseGalleries(await fetcher({ path: galleriesPath(search), signal }));
}

/** One gallery by id; null when it does not exist or may not be viewed (REST answers 404). */
export async function getGallery(
  id: number,
  fetcher: Fetcher = defaultFetcher,
  signal?: AbortSignal,
): Promise<GallerySummary | null> {
  try {
    return parseGallery(await fetcher({ path: `/swg/v1/galleries/${id}`, signal }));
  } catch (error) {
    if (errorCode(error) === 'swg_gallery_not_found' || errorStatus(error) === 404) return null;
    throw error;
  }
}

export async function listLayouts(
  fetcher: Fetcher = defaultFetcher,
  signal?: AbortSignal,
): Promise<LayoutSummary[]> {
  return parseLayouts(await fetcher({ path: '/swg/v1/layouts', signal }));
}

function errorCode(error: unknown): string | undefined {
  return isRecord(error) && typeof error.code === 'string' ? error.code : undefined;
}

function errorStatus(error: unknown): number | undefined {
  if (!isRecord(error) || !isRecord(error.data)) return undefined;
  return typeof error.data.status === 'number' ? error.data.status : undefined;
}

/** True when the request was cancelled on purpose (a newer search replaced it). */
export function isAbort(error: unknown): boolean {
  return isRecord(error) && (error.name === 'AbortError' || error.code === 'abort_error');
}

/** An honest, translated message for a failed request; server details (which may name internals) are not shown. */
export function describeError(error: unknown, what: 'galleries' | 'layouts'): string {
  const code = errorCode(error);
  const status = errorStatus(error);
  if (code === 'rest_cookie_invalid_nonce') {
    return __('Your session has expired. Reload the editor and try again.', 'sw-gallery');
  }
  if (status === 401 || status === 403 || code === 'rest_forbidden') {
    return what === 'galleries'
      ? __('You are not allowed to list galleries.', 'sw-gallery')
      : __('You are not allowed to list layouts.', 'sw-gallery');
  }
  if (code === 'fetch_error' || code === 'offline') {
    return __(
      'The server could not be reached. Check your connection and try again.',
      'sw-gallery',
    );
  }
  return what === 'galleries'
    ? __('The galleries could not be loaded.', 'sw-gallery')
    : __('The layouts could not be loaded.', 'sw-gallery');
}
