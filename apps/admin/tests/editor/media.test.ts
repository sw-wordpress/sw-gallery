import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiClient, ApiError } from '../../src/api/client';
import type { MediaApi } from '../../src/api/media';
import type { AddOutcome } from '../../src/features/editor/useGalleryEditor';
import { useMediaPicker } from '../../src/features/editor/useMediaPicker';
import { problemOf, useUploads } from '../../src/features/editor/useUploads';
import { resetEnvironment, setBootstrap } from '../support/mountApp';
import { makeMedia } from './support';

afterEach(() => {
  delete window.wp;
  resetEnvironment();
});

describe('media picker (wp.media)', () => {
  beforeEach(() => setBootstrap(['edit_posts', 'upload_files']));

  function fakeFrame(ids: number[]) {
    const handlers = new Map<string, () => void>();
    const frame = {
      on: vi.fn((event: string, cb: () => void) => {
        handlers.set(event, cb);
        return frame;
      }),
      open: vi.fn(() => {
        handlers.get('select')?.();
        handlers.get('close')?.();
        return frame;
      }),
      close: vi.fn(),
      state: () => ({
        get: () => ({ map: <T>(cb: (a: { id: number }) => T) => ids.map((id) => cb({ id })) }),
      }),
    };
    return frame;
  }

  it('opens the image-only multi-select modal and returns the selected attachment ids', async () => {
    const frame = fakeFrame([4, 9, 0, -2]);
    const factory = vi.fn(() => frame);
    window.wp = { media: factory as never };
    const picker = useMediaPicker();
    expect(picker.permitted.value).toBe(true);
    expect(picker.isAvailable()).toBe(true);
    const result = await picker.open({ title: 'T', button: 'B' });
    expect(factory).toHaveBeenCalledWith({
      title: 'T',
      button: { text: 'B' },
      library: { type: 'image' },
      multiple: 'add',
    });
    expect(result).toEqual({ status: 'selected', attachmentIds: [4, 9] });
  });

  it('treats closing without a selection as cancelled', async () => {
    window.wp = { media: (() => fakeFrame([])) as never };
    expect(await useMediaPicker().open({ title: 'T', button: 'B' })).toEqual({
      status: 'cancelled',
    });
  });

  it('fails gracefully when wp.media is missing', async () => {
    const picker = useMediaPicker();
    expect(picker.isAvailable()).toBe(false);
    expect(await picker.open({ title: 'T', button: 'B' })).toEqual({ status: 'unavailable' });
  });

  it('is not permitted for users without upload_files (contributors)', async () => {
    setBootstrap(['edit_posts']);
    const factory = vi.fn(() => fakeFrame([1]));
    window.wp = { media: factory as never };
    const picker = useMediaPicker();
    expect(picker.permitted.value).toBe(false);
    expect(await picker.open({ title: 'T', button: 'B' })).toEqual({ status: 'unavailable' });
    expect(factory).not.toHaveBeenCalled();
  });
});

function pngFile(name = 'a.png', size = 10, type = 'image/png'): File {
  return new File([new Uint8Array(size)], name, { type });
}

interface Deferred<T> {
  promise: Promise<T>;
  resolve: (value: T) => void;
  reject: (reason: unknown) => void;
}
function deferred<T>(): Deferred<T> {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, resolve, reject };
}

describe('upload queue', () => {
  const added = (n: number): AddOutcome => ({ ok: true, added: n, alreadyAssigned: 0, missing: 0 });

  it('uploads two at a time with real progress and assigns everything in one batch', async () => {
    const pending = [
      deferred<ReturnType<typeof makeMedia>>(),
      deferred<ReturnType<typeof makeMedia>>(),
      deferred<ReturnType<typeof makeMedia>>(),
    ];
    const progress: Array<(p: { loaded: number; total: number | null }) => void> = [];
    let call = 0;
    const api: MediaApi = {
      upload: vi.fn((_file, options) => {
        progress.push(options?.onProgress ?? (() => {}));
        const d = pending[call];
        call += 1;
        return (d as Deferred<ReturnType<typeof makeMedia>>).promise;
      }),
    };
    const onBatch = vi.fn(async (ids: number[]) => added(ids.length));
    const summary = vi.fn();
    const uploads = useUploads(api, onBatch, summary);
    uploads.add([pngFile('a.png', 100), pngFile('b.png', 100), pngFile('c.png', 100)]);
    expect(api.upload).toHaveBeenCalledTimes(2);
    expect(uploads.entries.value.map((e) => e.status)).toEqual([
      'uploading',
      'uploading',
      'queued',
    ]);
    expect(uploads.active.value).toBe(true);

    progress[0]?.({ loaded: 40, total: 100 });
    expect(uploads.entries.value[0]).toMatchObject({ loaded: 40, total: 100 });
    progress[1]?.({ loaded: 5, total: null });
    expect(uploads.entries.value[1]).toMatchObject({ loaded: 5, total: null });

    pending[0]?.resolve(makeMedia(11));
    await flushPromises();
    expect(api.upload).toHaveBeenCalledTimes(3);
    pending[1]?.resolve(makeMedia(12));
    pending[2]?.resolve(makeMedia(13));
    await flushPromises();
    expect(onBatch).toHaveBeenCalledTimes(1);
    expect(onBatch).toHaveBeenCalledWith([11, 12, 13]);
    expect(uploads.entries.value.every((e) => e.status === 'added')).toBe(true);
    expect(summary).toHaveBeenCalledWith({ uploaded: 3, failed: 0, outcome: added(3) });
    expect(uploads.busy.value).toBe(false);
    uploads.dismiss();
    expect(uploads.entries.value).toHaveLength(0);
  });

  it('rejects wrong types, empty and huge files before any request', () => {
    const api: MediaApi = { upload: vi.fn() };
    const uploads = useUploads(api, async () => added(0));
    uploads.add([
      pngFile('doc.pdf', 5, 'application/pdf'),
      pngFile('none', 5, ''),
      pngFile('empty.png', 0),
      Object.defineProperty(pngFile('big.png'), 'size', { value: 65 * 1024 * 1024 }),
    ]);
    expect(api.upload).not.toHaveBeenCalled();
    expect(uploads.entries.value.map((e) => e.problem)).toEqual(['type', 'type', 'empty', 'size']);
    expect(uploads.failedCount.value).toBe(4);
    expect(uploads.canRetry(1)).toBe(false);
    uploads.retry(1);
    uploads.dismiss(1);
    expect(uploads.entries.value).toHaveLength(3);
  });

  it('adds what worked on a partial failure and lets the failed file be retried', async () => {
    const results: Array<() => Promise<ReturnType<typeof makeMedia>>> = [
      async () => makeMedia(21),
      async () => {
        throw new ApiError('swg_unsupported_media_type', 'x', 422);
      },
    ];
    let n = 0;
    const api: MediaApi = { upload: vi.fn(() => (results[n++] ?? (async () => makeMedia(22)))()) };
    const onBatch = vi.fn(async (ids: number[]) => added(ids.length));
    const summary = vi.fn();
    const uploads = useUploads(api, onBatch, summary);
    uploads.add([pngFile('ok.png'), pngFile('bad.png')]);
    await flushPromises();
    expect(onBatch).toHaveBeenCalledWith([21]);
    expect(summary).toHaveBeenLastCalledWith({ uploaded: 1, failed: 1, outcome: added(1) });
    expect(uploads.entries.value.map((e) => [e.status, e.problem])).toEqual([
      ['added', null],
      ['error', 'unsupported'],
    ]);
    expect(uploads.canRetry(2)).toBe(true);
    uploads.retry(2);
    await flushPromises();
    expect(onBatch).toHaveBeenLastCalledWith([22]);
    expect(uploads.entries.value[1]?.status).toBe('added');
  });

  it('cancels a running and a queued upload', async () => {
    const running = [
      deferred<ReturnType<typeof makeMedia>>(),
      deferred<ReturnType<typeof makeMedia>>(),
    ];
    const signals: AbortSignal[] = [];
    let n = 0;
    const api: MediaApi = {
      upload: vi.fn((_file, options) => {
        const signal = options?.signal as AbortSignal;
        signals.push(signal);
        const d = running[n++] as Deferred<ReturnType<typeof makeMedia>>;
        signal.addEventListener('abort', () => d.reject(new ApiError('swg_aborted', 'x', 0)));
        return d.promise;
      }),
    };
    const onBatch = vi.fn(async () => added(0));
    const uploads = useUploads(api, onBatch);
    uploads.add([pngFile('1.png'), pngFile('2.png'), pngFile('3.png')]);
    uploads.cancel(3);
    expect(uploads.entries.value[2]?.status).toBe('cancelled');
    uploads.cancel(1);
    await flushPromises();
    expect(signals[0]?.aborted).toBe(true);
    expect(uploads.entries.value[0]?.status).toBe('cancelled');
    expect(uploads.canRetry(1)).toBe(true);
    uploads.cancelAll();
    await flushPromises();
    expect(uploads.entries.value.map((e) => e.status)).toEqual([
      'cancelled',
      'cancelled',
      'cancelled',
    ]);
    expect(onBatch).not.toHaveBeenCalled();
    uploads.cancel(99);
  });

  it('keeps uploaded files unassigned when the assignment fails and offers to retry it', async () => {
    const api: MediaApi = { upload: vi.fn(async () => makeMedia(31)) };
    const onBatch = vi
      .fn<(ids: number[]) => Promise<AddOutcome>>()
      .mockResolvedValueOnce({ ok: false, failure: 'conflict' })
      .mockResolvedValueOnce(added(1));
    const uploads = useUploads(api, onBatch);
    uploads.add([pngFile()]);
    await flushPromises();
    expect(uploads.entries.value[0]?.status).toBe('done');
    expect(uploads.unassigned.value).toBe(1);
    await uploads.assignPending();
    expect(uploads.entries.value[0]?.status).toBe('added');
    expect(uploads.unassigned.value).toBe(0);
  });

  it('classifies upload problems from the server answer', () => {
    const p = (code: string, status: number): string => problemOf(new ApiError(code, 'x', status));
    expect(p('swg_file_too_large', 413)).toBe('tooLarge');
    expect(p('swg_unsupported_media_type', 422)).toBe('unsupported');
    expect(p('swg_invalid_image', 422)).toBe('invalid');
    expect(p('swg_forbidden', 403)).toBe('forbidden');
    expect(p('swg_network', 0)).toBe('network');
    expect(p('swg_upload_failed', 500)).toBe('other');
    expect(problemOf(new Error('x'))).toBe('other');
  });
});

/** Minimal XMLHttpRequest double that records what the client sends. */
class FakeXhr {
  static last: FakeXhr;
  headers: Record<string, string> = {};
  method = '';
  url = '';
  withCredentials = false;
  status = 0;
  responseText = '';
  body: FormData | null = null;
  upload: { onprogress: ((e: ProgressEvent) => void) | null } = { onprogress: null };
  onload: (() => void) | null = null;
  onerror: (() => void) | null = null;
  onabort: (() => void) | null = null;
  responseHeaders: Record<string, string> = {};
  constructor() {
    FakeXhr.last = this;
  }
  open(method: string, url: string): void {
    this.method = method;
    this.url = url;
  }
  setRequestHeader(name: string, value: string): void {
    this.headers[name] = value;
  }
  getResponseHeader(name: string): string | null {
    return this.responseHeaders[name] ?? null;
  }
  send(body: FormData): void {
    this.body = body;
  }
  abort(): void {
    this.onabort?.();
  }
  finish(status: number, body: string): void {
    this.status = status;
    this.responseText = body;
    this.onload?.();
  }
}

describe('ApiClient.upload (XMLHttpRequest)', () => {
  function client(): ApiClient {
    return new ApiClient({
      restRoot: 'https://x.test/wp-json/swg/v1/',
      nonce: 'nonce-1',
      xhrFactory: () => new FakeXhr() as unknown as XMLHttpRequest,
    });
  }

  it('posts multipart with nonce and credentials and reports real progress', async () => {
    const onProgress = vi.fn();
    const promise = client().upload<{ id: number }>('media', pngFile('p.png'), {
      fields: { title: 'T' },
      onProgress,
    });
    const xhr = FakeXhr.last;
    expect(xhr.method).toBe('POST');
    expect(xhr.url).toBe('https://x.test/wp-json/swg/v1/media');
    expect(xhr.headers['X-WP-Nonce']).toBe('nonce-1');
    expect(xhr.withCredentials).toBe(true);
    expect(xhr.body?.get('title')).toBe('T');
    expect((xhr.body?.get('file') as File).name).toBe('p.png');
    xhr.upload.onprogress?.({ lengthComputable: true, loaded: 5, total: 10 } as ProgressEvent);
    xhr.upload.onprogress?.({ lengthComputable: false, loaded: 7, total: 0 } as ProgressEvent);
    expect(onProgress).toHaveBeenNthCalledWith(1, { loaded: 5, total: 10 });
    expect(onProgress).toHaveBeenNthCalledWith(2, { loaded: 7, total: null });
    xhr.responseHeaders['X-WP-Nonce'] = 'nonce-2';
    xhr.finish(201, '{"id":5}');
    expect(await promise).toEqual({ id: 5 });
  });

  it('normalises server errors, invalid bodies, network failures and aborts', async () => {
    const c = client();
    const rejected = c.upload('media', pngFile());
    FakeXhr.last.finish(413, '{"code":"swg_file_too_large","message":"x","data":{"status":413}}');
    await expect(rejected).rejects.toMatchObject({ code: 'swg_file_too_large', status: 413 });

    const garbage = c.upload('media', pngFile());
    FakeXhr.last.finish(201, 'not json');
    await expect(garbage).rejects.toMatchObject({ code: 'swg_invalid_response' });

    const httpOnly = c.upload('media', pngFile());
    FakeXhr.last.finish(502, '');
    await expect(httpOnly).rejects.toMatchObject({ status: 502 });

    const offline = c.upload('media', pngFile());
    FakeXhr.last.onerror?.();
    await expect(offline).rejects.toMatchObject({ code: 'swg_network' });

    const controller = new AbortController();
    const aborted = c.upload('media', pngFile(), { signal: controller.signal });
    controller.abort();
    await expect(aborted).rejects.toMatchObject({ code: 'swg_aborted' });

    const early = c.upload('media', pngFile(), { signal: controller.signal });
    await expect(early).rejects.toMatchObject({ code: 'swg_aborted' });
  });
});
