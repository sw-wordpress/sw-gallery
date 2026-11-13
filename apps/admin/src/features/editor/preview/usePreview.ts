import { computed, onScopeDispose, ref, shallowRef, watch } from 'vue';
import { isApiError } from '@/api/client';
import type { PreviewApi, PreviewResponse } from '@/api/preview';

/** Quiet period after the last staged change before a request is sent. */
export const PREVIEW_DEBOUNCE_MS = 400;
/** Rate guard: two requests never start closer together than this (a dragged slider cannot flood the server). */
export const PREVIEW_MIN_INTERVAL_MS = 800;

export type PreviewErrorKind =
  | 'validation'
  | 'unknownLayout'
  | 'forbidden'
  | 'notFound'
  | 'network'
  | 'invalidResponse'
  | 'server';

export interface PreviewError {
  kind: PreviewErrorKind;
  /** The thrown value (for `invalidFields` mapping by the caller). */
  cause: unknown;
}

/** The part of the staged state the server renders. Gallery title/description are NOT part of it. */
export interface PreviewState {
  layout: string;
  sortMode: string;
  settings: Record<string, unknown>;
}

export interface PreviewOptions {
  api: PreviewApi;
  galleryId: number;
  /** False while the pane is hidden, the editor is loading or the gallery is not editable: no requests. */
  active: () => boolean;
  /** Staged layout/sort/settings; `null` while loading. */
  state: () => PreviewState | null;
  /** Changes whenever the SAVED gallery changed (successful save, images added): forces a re-render. */
  reloadKey: () => unknown;
  /** 422/400: the caller maps the field errors (existing `invalidFields` mechanism). */
  onValidation?: (error: unknown) => void;
  debounceMs?: number;
  minIntervalMs?: number;
}

export function classifyPreviewError(error: unknown): PreviewError {
  if (!isApiError(error)) return { kind: 'server', cause: error };
  if (error.isNetwork) return { kind: 'network', cause: error };
  if (error.code === 'swg_invalid_response') return { kind: 'invalidResponse', cause: error };
  if (error.code === 'swg_unknown_layout') return { kind: 'unknownLayout', cause: error };
  if (error.status === 400 || error.status === 422) return { kind: 'validation', cause: error };
  if (error.status === 401 || error.status === 403) return { kind: 'forbidden', cause: error };
  if (error.status === 404) return { kind: 'notFound', cause: error };
  return { kind: 'server', cause: error };
}

/**
 * Debounced, cancellable, rate-limited, stale-safe preview requests.
 *
 *  - at most ONE request in flight: a newer one aborts the older one;
 *  - a response whose sequence number is no longer current is dropped even if the abort lost the race;
 *  - while inactive nothing is sent; becoming active renders once if the state changed meanwhile;
 *  - on error the last good result stays available (`result`) so the UI can keep it visible.
 */
export function usePreview(options: PreviewOptions) {
  const debounceMs = options.debounceMs ?? PREVIEW_DEBOUNCE_MS;
  const minInterval = options.minIntervalMs ?? PREVIEW_MIN_INTERVAL_MS;

  const result = shallowRef<PreviewResponse | null>(null);
  const error = shallowRef<PreviewError | null>(null);
  const loading = ref(false);
  const page = ref(1);
  /** Bumps with every successful render; the view recreates the iframe per value. */
  const renderId = ref(0);

  let timer: ReturnType<typeof setTimeout> | undefined;
  let controller: AbortController | null = null;
  let sequence = 0;
  let lastStart = Number.NEGATIVE_INFINITY;
  let renderedKey: string | null = null;

  const requestKey = computed(() => {
    const state = options.state();
    return state === null ? null : JSON.stringify([state, options.reloadKey()]);
  });

  function cancel(): void {
    clearTimeout(timer);
    timer = undefined;
    controller?.abort();
    controller = null;
    sequence += 1;
    loading.value = false;
  }

  async function run(): Promise<void> {
    timer = undefined;
    const state = options.state();
    if (state === null || !options.active()) return;
    controller?.abort();
    const current = new AbortController();
    controller = current;
    sequence += 1;
    const mine = sequence;
    lastStart = Date.now();
    renderedKey = requestKey.value;
    loading.value = true;
    try {
      const response = await options.api.render(
        options.galleryId,
        {
          layout: state.layout,
          sortMode: state.sortMode,
          settings: state.settings,
          page: page.value,
        },
        current.signal,
      );
      if (mine !== sequence || current.signal.aborted) return;
      result.value = response;
      error.value = null;
      page.value = response.page;
      renderId.value += 1;
    } catch (thrown) {
      if (mine !== sequence || current.signal.aborted) return;
      if (isApiError(thrown) && thrown.isAborted) return;
      const classified = classifyPreviewError(thrown);
      error.value = classified;
      // Let the next activation/change retry instead of believing this state was rendered.
      renderedKey = null;
      if (classified.kind === 'validation') options.onValidation?.(thrown);
    } finally {
      if (mine === sequence) {
        loading.value = false;
        controller = null;
      }
    }
  }

  function schedule(immediate: boolean): void {
    clearTimeout(timer);
    if (!options.active() || options.state() === null) return;
    const now = Date.now();
    const delay = Math.max(immediate ? 0 : debounceMs, lastStart + minInterval - now, 0);
    timer = setTimeout(() => void run(), delay);
  }

  watch(
    () => options.active(),
    (active) => {
      if (!active) {
        cancel();
        return;
      }
      if (requestKey.value !== renderedKey) schedule(true);
    },
    { immediate: true },
  );

  watch(requestKey, (key, before) => {
    if (key === before || !options.active()) return;
    if (controller !== null) {
      // The request in flight renders an outdated state: drop it now, the debounced one replaces it.
      controller.abort();
      controller = null;
      sequence += 1;
    }
    loading.value = true;
    schedule(false);
  });

  onScopeDispose(cancel);

  /** Explicit refresh / retry: renders now (still honouring the rate guard). */
  function refresh(): void {
    schedule(true);
  }

  function goto(next: number): void {
    const target = Math.max(1, Math.min(next, result.value?.totalPages ?? 1));
    if (target === page.value) return;
    page.value = target;
    schedule(true);
  }

  const totalPages = computed(() => result.value?.totalPages ?? 1);

  return {
    result,
    error,
    loading,
    page,
    renderId,
    totalPages,
    refresh,
    goto,
    cancel,
  };
}
