import { effectScope, nextTick, ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../../src/api/client';
import type { PreviewRequest, PreviewResponse } from '../../src/api/preview';
import {
  PREVIEW_DEBOUNCE_MS,
  PREVIEW_MIN_INTERVAL_MS,
  classifyPreviewError,
  usePreview,
} from '../../src/features/editor/preview/usePreview';
import type { PreviewState } from '../../src/features/editor/preview/usePreview';
import { makePreview } from '../editor/support';

interface Call {
  request: PreviewRequest;
  signal: AbortSignal | undefined;
  resolve: (value: PreviewResponse) => void;
  reject: (error: unknown) => void;
}

function setup(options: { honourAbort?: boolean } = {}) {
  const honourAbort = options.honourAbort ?? true;
  const active = ref(true);
  const state = ref<PreviewState | null>({
    layout: 'grid',
    sortMode: 'manual',
    settings: { columns: 3 },
  });
  const reload = ref(1);
  const calls: Call[] = [];
  const onValidation = vi.fn();
  const api = {
    render: (_id: number, request: PreviewRequest, signal?: AbortSignal) =>
      new Promise<PreviewResponse>((resolve, reject) => {
        calls.push({ request, signal, resolve, reject });
        if (honourAbort) {
          signal?.addEventListener('abort', () =>
            reject(new DOMException('Aborted', 'AbortError')),
          );
        }
      }),
  };
  const scope = effectScope();
  const preview = scope.run(() =>
    usePreview({
      api,
      galleryId: 7,
      active: () => active.value,
      state: () => state.value,
      reloadKey: () => reload.value,
      onValidation,
    }),
  );
  if (preview === undefined) throw new Error('no scope');
  const setColumns = (columns: number): void => {
    state.value = { layout: 'grid', sortMode: 'manual', settings: { columns } };
  };
  return { active, state, reload, calls, preview, scope, setColumns, onValidation };
}

async function tick(ms = 0): Promise<void> {
  await nextTick();
  await vi.advanceTimersByTimeAsync(ms);
  await nextTick();
}

beforeEach(() => {
  vi.useFakeTimers();
});
afterEach(() => {
  vi.useRealTimers();
});

describe('usePreview scheduling', () => {
  it('renders once immediately when it becomes active and sends the staged state', async () => {
    const h = setup();
    await tick(0);
    expect(h.calls).toHaveLength(1);
    expect(h.calls[0]?.request).toEqual({
      layout: 'grid',
      sortMode: 'manual',
      settings: { columns: 3 },
      page: 1,
    });
    expect(h.preview.loading.value).toBe(true);
    h.calls[0]?.resolve(makePreview({ totalPages: 3 }));
    await tick(0);
    expect(h.preview.loading.value).toBe(false);
    expect(h.preview.result.value?.totalPages).toBe(3);
    expect(h.preview.renderId.value).toBe(1);
  });

  it('debounces a burst of changes into one request with the last value', async () => {
    const h = setup();
    await tick(0);
    h.calls[0]?.resolve(makePreview());
    await tick(PREVIEW_MIN_INTERVAL_MS);
    for (const columns of [4, 5, 6]) {
      h.setColumns(columns);
      await tick(100);
    }
    expect(h.calls).toHaveLength(1);
    await tick(PREVIEW_DEBOUNCE_MS);
    expect(h.calls).toHaveLength(2);
    expect(h.calls[1]?.request.settings).toEqual({ columns: 6 });
  });

  it('aborts the request in flight when the staged state changes', async () => {
    const h = setup();
    await tick(0);
    const first = h.calls[0];
    h.setColumns(9);
    await tick(0);
    expect(first?.signal?.aborted).toBe(true);
    expect(h.preview.loading.value).toBe(true);
    await tick(PREVIEW_MIN_INTERVAL_MS);
    expect(h.calls).toHaveLength(2);
    expect(h.calls[1]?.signal?.aborted).toBe(false);
  });

  it('ignores a late answer of an outdated request (stale guard) even if the abort lost the race', async () => {
    const h = setup({ honourAbort: false });
    await tick(0);
    h.setColumns(9);
    await tick(PREVIEW_MIN_INTERVAL_MS);
    expect(h.calls).toHaveLength(2);
    h.calls[1]?.resolve(makePreview({ layoutId: 'new' }));
    await tick(0);
    h.calls[0]?.resolve(makePreview({ layoutId: 'old' }));
    await tick(0);
    expect(h.preview.result.value?.layoutId).toBe('new');
  });

  it('keeps two request starts at least the rate-guard interval apart', async () => {
    const h = setup();
    await tick(0);
    h.calls[0]?.resolve(makePreview());
    await tick(0);
    h.setColumns(2);
    await tick(PREVIEW_DEBOUNCE_MS + 10);
    expect(h.calls).toHaveLength(1);
    await tick(PREVIEW_MIN_INTERVAL_MS - PREVIEW_DEBOUNCE_MS);
    expect(h.calls).toHaveLength(2);
  });

  it('never sends while inactive, cancels on hiding and renders once on re-activation if the state changed', async () => {
    const h = setup();
    h.active.value = false;
    await tick(0);
    expect(h.calls).toHaveLength(0);
    h.setColumns(5);
    await tick(2000);
    expect(h.calls).toHaveLength(0);
    h.active.value = true;
    await tick(0);
    expect(h.calls).toHaveLength(1);
    h.calls[0]?.resolve(makePreview());
    await tick(0);
    // Hide and show again without a change: the rendered result is reused.
    h.active.value = false;
    await tick(0);
    h.active.value = true;
    await tick(2000);
    expect(h.calls).toHaveLength(1);
  });

  it('aborts when hidden while a request is in flight', async () => {
    const h = setup();
    await tick(0);
    const first = h.calls[0];
    h.active.value = false;
    await tick(0);
    expect(first?.signal?.aborted).toBe(true);
    expect(h.preview.loading.value).toBe(false);
  });

  it('re-renders when the saved gallery changed (reload key), not for unrelated state', async () => {
    const h = setup();
    await tick(0);
    h.calls[0]?.resolve(makePreview());
    await tick(PREVIEW_MIN_INTERVAL_MS);
    h.reload.value = 2;
    await tick(PREVIEW_DEBOUNCE_MS);
    expect(h.calls).toHaveLength(2);
  });

  it('does nothing while the editor state is not available', async () => {
    const h = setup();
    h.state.value = null;
    h.calls.length = 0;
    await tick(0);
    h.preview.refresh();
    await tick(2000);
    expect(h.calls.every((c) => c.signal?.aborted)).toBe(true);
  });

  it('pages: clamps to the known range, requests immediately and follows the server page', async () => {
    const h = setup();
    await tick(0);
    h.calls[0]?.resolve(makePreview({ totalPages: 3 }));
    await tick(PREVIEW_MIN_INTERVAL_MS);
    h.preview.goto(9);
    await tick(0);
    expect(h.calls[1]?.request.page).toBe(3);
    h.calls[1]?.resolve(makePreview({ page: 2, totalPages: 2 }));
    await tick(0);
    expect(h.preview.page.value).toBe(2);
    h.preview.goto(2);
    await tick(2000);
    expect(h.calls).toHaveLength(2);
  });

  it('bounds and applies the preview page size and returns to page 1', async () => {
    const h = setup();
    await tick(0);
    h.calls[0]?.resolve(makePreview({ totalPages: 4 }));
    await tick(PREVIEW_MIN_INTERVAL_MS);
    h.preview.goto(3);
    await tick(0);
    h.calls[1]?.resolve(makePreview({ page: 3, totalPages: 4 }));
    await tick(PREVIEW_MIN_INTERVAL_MS);
    h.preview.setPerPage(1000);
    expect(h.preview.page.value).toBe(1);
    await tick(PREVIEW_DEBOUNCE_MS);
    expect(h.calls[2]?.request).toMatchObject({ perPage: 200, page: 1 });
    h.preview.setPerPage(200);
    h.preview.setPerPage(null);
    expect(h.preview.perPage.value).toBeNull();
  });

  it('refresh renders again immediately (after the rate guard)', async () => {
    const h = setup();
    await tick(0);
    h.calls[0]?.resolve(makePreview());
    await tick(PREVIEW_MIN_INTERVAL_MS);
    h.preview.refresh();
    await tick(0);
    expect(h.calls).toHaveLength(2);
  });

  it('cancels on scope disposal', async () => {
    const h = setup();
    await tick(0);
    const first = h.calls[0];
    h.scope.stop();
    expect(first?.signal?.aborted).toBe(true);
  });
});

describe('usePreview errors', () => {
  it('keeps the last good result on an error and reports validation problems', async () => {
    const h = setup();
    await tick(0);
    h.calls[0]?.resolve(makePreview({ layoutId: 'good' }));
    await tick(PREVIEW_MIN_INTERVAL_MS);
    h.setColumns(20);
    await tick(PREVIEW_DEBOUNCE_MS);
    const failure = new ApiError('swg_validation_failed', 'invalid', 422, {}, { errors: [] });
    h.calls[1]?.reject(failure);
    await tick(0);
    expect(h.preview.error.value?.kind).toBe('validation');
    expect(h.preview.result.value?.layoutId).toBe('good');
    expect(h.onValidation).toHaveBeenCalledWith(failure);
    expect(h.preview.loading.value).toBe(false);
    // The next successful render clears the error.
    h.setColumns(4);
    await tick(PREVIEW_MIN_INTERVAL_MS);
    h.calls[2]?.resolve(makePreview({ layoutId: 'better' }));
    await tick(0);
    expect(h.preview.error.value).toBeNull();
  });

  it('retries after a network error through refresh', async () => {
    const h = setup();
    await tick(0);
    h.calls[0]?.reject(new ApiError('swg_network', 'down', 0));
    await tick(0);
    expect(h.preview.error.value?.kind).toBe('network');
    h.preview.refresh();
    await tick(PREVIEW_MIN_INTERVAL_MS);
    expect(h.calls).toHaveLength(2);
    h.calls[1]?.resolve(makePreview());
    await tick(0);
    expect(h.preview.error.value).toBeNull();
  });

  it('ignores an aborted-error answer', async () => {
    const h = setup();
    await tick(0);
    h.calls[0]?.reject(new ApiError('swg_aborted', 'aborted', 0));
    await tick(0);
    expect(h.preview.error.value).toBeNull();
  });

  it('classifies failures', () => {
    const kind = (error: unknown): string => classifyPreviewError(error).kind;
    expect(kind(new ApiError('swg_unknown_layout', 'x', 422))).toBe('unknownLayout');
    expect(kind(new ApiError('rest_invalid_param', 'x', 400))).toBe('validation');
    expect(kind(new ApiError('x', 'x', 403))).toBe('forbidden');
    expect(kind(new ApiError('x', 'x', 401))).toBe('forbidden');
    expect(kind(new ApiError('x', 'x', 404))).toBe('notFound');
    expect(kind(new ApiError('swg_network', 'x', 0))).toBe('network');
    expect(kind(new ApiError('swg_invalid_response', 'x', 200))).toBe('invalidResponse');
    expect(kind(new ApiError('x', 'x', 500))).toBe('server');
    expect(kind(new Error('boom'))).toBe('server');
  });
});
