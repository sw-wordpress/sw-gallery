import { describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';
import { ApiClient, ApiError, ConflictError } from '../../src/api/client';
import { errorMessage, invalidFields } from '../../src/api/errorMessage';
import { createGalleriesApi } from '../../src/api/galleries';
import type { GalleriesApi, GalleryListQuery, GalleryPage } from '../../src/api/galleries';
import {
  availableActions,
  formatModified,
  localeTag,
  shortcodeFor,
} from '../../src/features/galleries/format';
import { useGalleryList } from '../../src/features/galleries/useGalleryList';
import { makeGallery } from './support';

function json(body: unknown, init: ResponseInit = {}): Response {
  return new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
    ...init,
  });
}

function setup(respond: () => Response) {
  const fetchMock = vi.fn(async (..._args: unknown[]) => respond());
  const client = new ApiClient({
    restRoot: 'https://x.test/wp-json/swg/v1/',
    nonce: 'n',
    fetchImpl: fetchMock as unknown as typeof fetch,
    sleep: async () => {},
  });
  const call = (index = 0) => {
    const [url, init] = fetchMock.mock.calls[index] as unknown as [string, RequestInit];
    return { url, init, body: init.body === undefined ? undefined : JSON.parse(String(init.body)) };
  };
  return { api: createGalleriesApi(client), call, fetchMock };
}

describe('galleries API module', () => {
  it('lists with the documented query parameters and reads the paging headers', async () => {
    const rows = [makeGallery({ id: 1 }), makeGallery({ id: 2 })];
    const { api, call } = setup(() =>
      json(rows, { headers: { 'X-WP-Total': '42', 'X-WP-TotalPages': '3' } }),
    );
    const signal = new AbortController().signal;
    const page = await api.list(
      { search: 'sum', status: ['draft', 'pending'], owner: 5, page: 2, perPage: 20 },
      signal,
    );
    expect(page).toEqual({ items: rows, total: 42, totalPages: 3 });
    const { url, init } = call();
    expect(url).toBe(
      'https://x.test/wp-json/swg/v1/galleries?search=sum&status=draft%2Cpending&owner=5&page=2&per_page=20',
    );
    expect(init.method).toBe('GET');
    expect(init.signal).toBe(signal);
  });

  it('omits empty parameters and falls back when headers or body are unusable', async () => {
    const { api, call } = setup(() => json(null));
    expect(await api.list()).toEqual({ items: [], total: 0, totalPages: 0 });
    expect(call().url).toBe('https://x.test/wp-json/swg/v1/galleries');
    const withRows = setup(() => json([makeGallery()], { headers: { 'X-WP-Total': 'abc' } }));
    expect(await withRows.api.list()).toEqual({
      items: [expect.objectContaining({ id: 7 })],
      total: 1,
      totalPages: 1,
    });
  });

  it('creates, reads and patches with expectedRevision in the body', async () => {
    const created = makeGallery({ id: 9, revision: 1 });
    const { api, call } = setup(() => json(created, { status: 201 }));
    expect(await api.create({ title: 'A', description: 'd', status: 'draft' })).toEqual(created);
    expect(call(0).init.method).toBe('POST');
    expect(call(0).body).toEqual({ title: 'A', description: 'd', status: 'draft' });
    await api.get(9);
    expect(call(1).url).toBe('https://x.test/wp-json/swg/v1/galleries/9');
    await api.update(9, 4, { title: 'B', coverAttachmentId: null });
    expect(call(2).init.method).toBe('PATCH');
    expect(call(2).body).toEqual({ title: 'B', coverAttachmentId: null, expectedRevision: 4 });
  });

  it('trashes and deletes with the revision as query parameter, force only for permanent delete', async () => {
    const { api, call } = setup(() => json({ id: 3 }));
    await api.trash(3, 6);
    expect(call(0).init.method).toBe('DELETE');
    expect(call(0).url).toBe('https://x.test/wp-json/swg/v1/galleries/3?expectedRevision=6');
    await api.deletePermanently(3, 6);
    expect(call(1).url).toBe(
      'https://x.test/wp-json/swg/v1/galleries/3?expectedRevision=6&force=true',
    );
  });

  it('duplicates (optional title) and restores', async () => {
    const { api, call } = setup(() => json(makeGallery()));
    await api.duplicate(3);
    expect(call(0).url).toBe('https://x.test/wp-json/swg/v1/galleries/3/duplicate');
    expect(call(0).body).toEqual({});
    await api.duplicate(3, 'Copy');
    expect(call(1).body).toEqual({ title: 'Copy' });
    await api.restore(3, 8);
    expect(call(2).url).toBe('https://x.test/wp-json/swg/v1/galleries/3/restore');
    expect(call(2).body).toEqual({ expectedRevision: 8 });
  });

  it('surfaces a stale revision as ConflictError with the server data', async () => {
    const { api } = setup(() =>
      json(
        {
          code: 'swg_revision_conflict',
          message: 'x',
          data: { status: 409, expectedRevision: 1, currentRevision: 2 },
        },
        { status: 409 },
      ),
    );
    const error = await api.update(1, 1, { title: 'x' }).catch((e: unknown) => e);
    expect(error).toBeInstanceOf(ConflictError);
    expect((error as ConflictError).details).toMatchObject({ currentRevision: 2 });
  });
});

describe('errorMessage for the gallery routes', () => {
  const t = (key: string): string => key;
  const err = (code: string, status: number) => new ApiError(code, 'server text', status);

  it('maps every documented code to its own message', () => {
    expect(errorMessage(err('swg_cannot_publish', 403), t)).toBe('errors.cannotPublish');
    expect(errorMessage(err('swg_invalid_cover', 422), t)).toBe('errors.invalidCover');
    expect(errorMessage(err('swg_invalid_request', 422), t)).toBe('errors.invalidRequest');
    expect(errorMessage(err('swg_no_changes', 400), t)).toBe('errors.noChanges');
    expect(errorMessage(err('swg_revision_conflict', 409), t)).toBe('errors.revisionConflict');
    expect(errorMessage(err('swg_invalid_state', 409), t)).toBe('errors.invalidState');
    expect(errorMessage(err('swg_operation_vetoed', 403), t)).toBe('errors.vetoed');
    expect(errorMessage(err('swg_gallery_not_found', 404), t)).toBe('errors.galleryNotFound');
  });

  it('falls back to the status for the remaining codes', () => {
    expect(errorMessage(err('swg_forbidden', 403), t)).toBe('errors.forbidden');
    expect(errorMessage(err('swg_validation_failed', 422), t)).toBe('errors.validation');
    expect(errorMessage(err('swg_unknown_layout', 422), t)).toBe('errors.validation');
    expect(errorMessage(err('swg_internal_error', 500), t)).toBe('errors.server');
  });

  it('extracts invalid field names from params and validation errors only', () => {
    const withParams = new ApiError('rest_invalid_param', 'm', 400, { title: 'bad' });
    expect(invalidFields(withParams)).toEqual(['title']);
    const withErrors = new ApiError(
      'swg_validation_failed',
      'm',
      422,
      {},
      {
        errors: [{ path: 'settings.columns' }, { path: '' }, null, { path: 7 }],
      },
    );
    expect(invalidFields(withErrors)).toEqual(['settings']);
    expect(invalidFields(err('swg_forbidden', 403))).toEqual([]);
    expect(invalidFields(new Error('x'))).toEqual([]);
  });
});

describe('format helpers', () => {
  it('derives a BCP 47 tag from the WordPress locale', () => {
    expect(localeTag('de_DE_formal')).toBe('de-DE');
    expect(localeTag('en_US')).toBe('en-US');
    expect(localeTag('ar')).toBe('ar');
    expect(localeTag('de_CH_informal')).toBe('de-CH');
    expect(localeTag('!!')).toBe('en');
    expect(localeTag('')).toBe('en');
  });

  it('formats the UTC value in the user locale and WordPress timezone', () => {
    const iso = '2026-11-11T09:30:00Z';
    const de = formatModified(iso, 'de_DE', 'Europe/Berlin');
    expect(de).toContain('11.11.2026');
    expect(de).toContain('10:30');
    const us = formatModified(iso, 'en_US', 'America/New_York');
    expect(us).toContain('Nov 11, 2026');
    expect(us).toContain('4:30');
  });

  it('accepts UTC offsets, an empty timezone and falls back for unknown ones', () => {
    const iso = '2026-11-11T09:30:00Z';
    expect(formatModified(iso, 'en_US', '')).toContain('2026');
    expect(formatModified(iso, 'en_US', 'Not/AZone')).toContain('9:30');
    expect(formatModified('garbage', 'en_US', '')).toBe('garbage');
  });

  it('builds the shortcode', () => {
    expect(shortcodeFor(12)).toBe('[sw_gallery id="12"]');
  });

  it('derives the row actions from the status and the server hints', () => {
    expect(availableActions(makeGallery())).toEqual([
      'editImages',
      'edit',
      'duplicate',
      'copy',
      'trash',
    ]);
    expect(availableActions(makeGallery({ canEdit: false, canDelete: false }))).toEqual([
      'duplicate',
      'copy',
    ]);
    expect(availableActions(makeGallery({ status: 'trash' }))).toEqual(['restore', 'delete']);
    expect(availableActions(makeGallery({ status: 'trash', canDelete: false }))).toEqual([]);
  });
});

describe('useGalleryList', () => {
  const emptyPage: GalleryPage = { items: [], total: 0, totalPages: 0 };

  function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (reason: unknown) => void;
    const promise = new Promise<T>((res, rej) => {
      resolve = res;
      reject = rej;
    });
    return { promise, resolve, reject };
  }

  function withList(list: GalleriesApi['list'], initial: GalleryListQuery = {}) {
    const query = ref<GalleryListQuery>(initial);
    const scope = effectScope();
    const state = scope.run(() => useGalleryList({ list } as GalleriesApi, query));
    if (state === undefined) throw new Error('scope');
    return { query, scope, state };
  }

  it('loads immediately, exposes rows and totals and settles', async () => {
    const rows = [makeGallery({ id: 1 })];
    const list = vi.fn(async () => ({ items: rows, total: 1, totalPages: 1 }));
    const { state } = withList(list);
    expect(state.loading.value).toBe(true);
    expect(state.settled.value).toBe(false);
    await vi.waitFor(() => expect(state.settled.value).toBe(true));
    expect(state.items.value).toEqual(rows);
    expect(state.total.value).toBe(1);
    expect(state.loading.value).toBe(false);
    expect(state.error.value).toBeNull();
  });

  it('cancels the running request on a query change and ignores its late answer', async () => {
    const first = deferred<GalleryPage>();
    const second = deferred<GalleryPage>();
    const signals: AbortSignal[] = [];
    const list = vi
      .fn<GalleriesApi['list']>()
      .mockImplementationOnce((_q, signal) => {
        if (signal) signals.push(signal);
        return first.promise;
      })
      .mockImplementationOnce((_q, signal) => {
        if (signal) signals.push(signal);
        return second.promise;
      });
    const { query, state } = withList(list, { search: 'a' });
    query.value = { search: 'ab' };
    await nextTick();
    expect(signals[0]?.aborted).toBe(true);
    expect(signals[1]?.aborted).toBe(false);
    second.resolve({ items: [makeGallery({ id: 2 })], total: 1, totalPages: 1 });
    await vi.waitFor(() => expect(state.settled.value).toBe(true));
    // The stale response of the first request arrives afterwards and must not win.
    first.resolve({ items: [makeGallery({ id: 1 })], total: 1, totalPages: 1 });
    await Promise.resolve();
    await Promise.resolve();
    expect(state.items.value.map((g) => g.id)).toEqual([2]);
    expect(list).toHaveBeenCalledTimes(2);
  });

  it('keeps the rows while reloading and drops them on a query change', async () => {
    const rows = [makeGallery({ id: 1 })];
    const reload = deferred<GalleryPage>();
    const list = vi
      .fn<GalleriesApi['list']>()
      .mockResolvedValueOnce({ items: rows, total: 1, totalPages: 1 })
      .mockImplementationOnce(() => reload.promise)
      .mockImplementation(() => new Promise<GalleryPage>(() => {}));
    const { query, state } = withList(list);
    await vi.waitFor(() => expect(state.settled.value).toBe(true));
    const pending = state.reload();
    expect(state.loading.value).toBe(true);
    expect(state.items.value).toEqual(rows);
    reload.resolve(emptyPage);
    await pending;
    expect(state.items.value).toEqual([]);
    state.items.value = rows;
    query.value = { page: 2 };
    await nextTick();
    expect(state.items.value).toEqual([]);
    expect(state.settled.value).toBe(false);
  });

  it('stores the error, ignores aborts and recovers on retry', async () => {
    const list = vi
      .fn<GalleriesApi['list']>()
      .mockRejectedValueOnce(new ApiError('swg_internal_error', 'x', 500))
      .mockRejectedValueOnce(new Error('weird'))
      .mockRejectedValueOnce(new ApiError('swg_aborted', 'x', 0))
      .mockResolvedValueOnce({ items: [makeGallery()], total: 1, totalPages: 1 });
    const { state } = withList(list);
    await vi.waitFor(() => expect(state.settled.value).toBe(true));
    expect(state.error.value?.code).toBe('swg_internal_error');
    await state.reload();
    expect(state.error.value?.code).toBe('swg_unknown');
    await state.reload();
    expect(state.loading.value).toBe(true); // an abort neither settles nor reports an error
    expect(state.error.value).toBeNull();
    await state.reload();
    expect(state.items.value).toHaveLength(1);
    expect(state.loading.value).toBe(false);
  });

  it('aborts and ignores everything after the owning scope is disposed', async () => {
    const pending = deferred<GalleryPage>();
    let signal: AbortSignal | undefined;
    const list = vi.fn<GalleriesApi['list']>((_q, s) => {
      signal = s;
      return pending.promise;
    });
    const { scope, state } = withList(list);
    scope.stop();
    expect(signal?.aborted).toBe(true);
    pending.resolve({ items: [makeGallery()], total: 1, totalPages: 1 });
    await Promise.resolve();
    expect(state.items.value).toEqual([]);
  });
});
