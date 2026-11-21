import { ApiError } from './client';
import type { ApiClient } from './client';
import type { HoverConfig } from './galleries';

/** Shapes of `docs/rest-api.md` ("Preview", route `POST swg/v1/galleries/{id}/preview`). */

/** Every member is optional and never persisted by the server. */
export interface PreviewRequest {
  layout?: string;
  sortMode?: string;
  /** Unsaved gallery page size (null = site default); `perPage` below is the preview-only override. */
  galleryPerPage?: number | null;
  /** Unsaved gallery hover texts (null = site default). */
  galleryHover?: HoverConfig | null;
  /** Replaces the stored map: send the complete merged map. */
  settings?: Record<string, unknown>;
  page?: number;
  /** 1..200. */
  perPage?: number;
}

export interface PreviewDiagnostic {
  code: string;
  message: string;
}

export interface PreviewAssets {
  css: string[];
  js: string[];
}

export interface PreviewResponse {
  /** Renderer markup. UNTRUSTED for the admin DOM: only ever placed inside a sandboxed iframe. */
  html: string;
  layoutId: string;
  page: number;
  totalPages: number;
  totalItems: number;
  diagnostics: PreviewDiagnostic[];
  assets: PreviewAssets;
}

function invalid(): ApiError {
  return new ApiError('swg_invalid_response', 'The preview response has an unexpected shape', 200);
}

function strings(value: unknown): string[] {
  if (!Array.isArray(value)) throw invalid();
  return value.map((entry: unknown) => {
    if (typeof entry !== 'string') throw invalid();
    return entry;
  });
}

function count(value: unknown): number {
  if (typeof value !== 'number' || !Number.isFinite(value) || value < 0) throw invalid();
  return Math.floor(value);
}

/** Strict shape check: the REST body is data, never trusted structurally. Exported for tests. */
export function parsePreviewResponse(body: unknown): PreviewResponse {
  if (body === null || typeof body !== 'object') throw invalid();
  const raw = body as Record<string, unknown>;
  if (typeof raw.html !== 'string' || typeof raw.layoutId !== 'string') throw invalid();
  const assets = raw.assets as Record<string, unknown> | null | undefined;
  if (assets === null || typeof assets !== 'object' || !Array.isArray(raw.diagnostics)) {
    throw invalid();
  }
  return {
    html: raw.html,
    layoutId: raw.layoutId,
    page: Math.max(1, count(raw.page)),
    totalPages: Math.max(1, count(raw.totalPages)),
    totalItems: count(raw.totalItems),
    diagnostics: raw.diagnostics.map((entry: unknown) => {
      const d = (entry ?? {}) as Record<string, unknown>;
      if (typeof d.code !== 'string' || typeof d.message !== 'string') throw invalid();
      return { code: d.code, message: d.message };
    }),
    assets: { css: strings(assets.css), js: strings(assets.js) },
  };
}

/** Stable diagnostic codes of the renderer (core `RenderDiagnostic`) with their catalog message. */
const DIAGNOSTIC_KEYS: Record<string, string> = {
  gallery_not_found: 'editor.preview.diagnostics.codes.gallery_not_found',
  gallery_not_public: 'editor.preview.diagnostics.codes.gallery_not_public',
  unknown_layout: 'editor.preview.diagnostics.codes.unknown_layout',
  layout_failed: 'editor.preview.diagnostics.codes.layout_failed',
  invalid_override: 'editor.preview.diagnostics.codes.invalid_override',
  empty_gallery: 'editor.preview.diagnostics.codes.empty_gallery',
  images_unavailable: 'editor.preview.diagnostics.codes.images_unavailable',
};

/**
 * Text of one preview note: the catalog translation of a known code, else the server's own text (a code this app
 * does not know yet, e.g. from an extension). The server text is only ever rendered as text, never as HTML.
 */
export function diagnosticMessage(
  diagnostic: PreviewDiagnostic,
  t: (key: string) => string,
): string {
  const key = Object.hasOwn(DIAGNOSTIC_KEYS, diagnostic.code)
    ? DIAGNOSTIC_KEYS[diagnostic.code]
    : undefined;
  return key === undefined ? diagnostic.message : t(key);
}

export function createPreviewApi(client: ApiClient) {
  return {
    /** Server-side render of the gallery with the given UNSAVED state; cancellable. */
    async render(
      galleryId: number,
      request: PreviewRequest,
      signal?: AbortSignal,
    ): Promise<PreviewResponse> {
      const body = await client.post<unknown>(`galleries/${galleryId}/preview`, request, {
        signal,
      });
      return parsePreviewResponse(body);
    },
  };
}

export type PreviewApi = ReturnType<typeof createPreviewApi>;
