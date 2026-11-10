import { describe, expect, it, vi } from 'vitest';
import {
  ApiClient,
  ApiError,
  ConflictError,
  MAX_RETRIES,
  isApiError,
  isConflictError,
  normaliseError,
} from '../src/api/client';

function jsonResponse(body: unknown, init: ResponseInit = {}): Response {
  return new Response(body === null ? null : JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
    ...init,
  });
}

const noSleep = vi.fn(async () => {});

function makeClient(
  fetchImpl: typeof fetch,
  extra: Partial<ConstructorParameters<typeof ApiClient>[0]> = {},
) {
  return new ApiClient({
    restRoot: 'https://x.test/wp-json/swg/v1/',
    nonce: 'abc',
    fetchImpl,
    sleep: noSleep,
    ...extra,
  });
}

function asFetch(fn: unknown): typeof fetch {
  return fn as typeof fetch;
}

describe('ApiClient requests', () => {
  it('sends nonce, same-origin credentials and a JSON body', async () => {
    const fetchMock = vi.fn(async () => jsonResponse({ id: 1 }));
    const result = await makeClient(asFetch(fetchMock)).post<{ id: number }>('galleries', {
      title: 'A',
    });
    expect(result).toEqual({ id: 1 });
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe('https://x.test/wp-json/swg/v1/galleries');
    expect(init.method).toBe('POST');
    expect(init.credentials).toBe('same-origin');
    const headers = init.headers as Record<string, string>;
    expect(headers['X-WP-Nonce']).toBe('abc');
    expect(headers['Content-Type']).toBe('application/json');
    expect(init.body).toBe('{"title":"A"}');
  });

  it('builds query strings and skips empty values', () => {
    const client = makeClient(asFetch(vi.fn()));
    expect(client.url('/items', { page: 2, search: '', type: undefined, s: null, ok: true })).toBe(
      'https://x.test/wp-json/swg/v1/items?page=2&ok=true',
    );
    expect(client.url('items')).toBe('https://x.test/wp-json/swg/v1/items');
  });

  it('adds a trailing slash to the root and handles plain permalinks', () => {
    const client = new ApiClient({ restRoot: 'https://x.test/?rest_route=/swg/v1', nonce: 'n' });
    expect(client.url('galleries', { page: 1 })).toBe(
      'https://x.test/?rest_route=/swg/v1/galleries&page=1',
    );
  });

  it('supports put, delete and get helpers', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(null, { status: 204 }));
    const client = makeClient(asFetch(fetchMock));
    await client.put('galleries/1', { title: 'B' });
    await client.delete('galleries/1', { force: true });
    await client.get('galleries', { page: 3 });
    const methods = fetchMock.mock.calls.map(
      (c) => (c as unknown as [string, RequestInit])[1].method,
    );
    expect(methods).toEqual(['PUT', 'DELETE', 'GET']);
  });

  it('returns null for empty bodies and rejects invalid JSON on success', async () => {
    const empty = makeClient(asFetch(async () => new Response(null, { status: 204 })));
    expect(await empty.get('x')).toBeNull();
    const broken = makeClient(
      asFetch(async () => new Response('<html>', { status: 200 })),
      {
        retry: { retries: 0 },
      },
    );
    const error = (await broken.get('x').catch((e: unknown) => e)) as ApiError;
    expect(error.code).toBe('swg_invalid_response');
  });

  it('takes over a refreshed nonce from the response header and accepts setNonce', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse({}, { headers: { 'X-WP-Nonce': 'fresh' } }))
      .mockImplementation(async () => jsonResponse({}));
    const client = makeClient(asFetch(fetchMock));
    await client.get('a');
    await client.get('b');
    const second = fetchMock.mock.calls[1] as unknown as [string, RequestInit];
    expect((second[1].headers as Record<string, string>)['X-WP-Nonce']).toBe('fresh');
    client.setNonce('manual');
    await client.get('c');
    const third = fetchMock.mock.calls[2] as unknown as [string, RequestInit];
    expect((third[1].headers as Record<string, string>)['X-WP-Nonce']).toBe('manual');
  });

  it('uses the global fetch when none is injected', async () => {
    const spy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ ok: 1 }));
    const result = await new ApiClient({ restRoot: 'https://x.test/r/', nonce: 'n' }).get('p');
    expect(result).toEqual({ ok: 1 });
    spy.mockRestore();
  });
});

describe('error handling', () => {
  it('normalises WP_Error bodies', async () => {
    const body = {
      code: 'swg_invalid_param',
      message: 'Invalid',
      data: { status: 400, params: { title: 'Required', n: 5 } },
    };
    const client = makeClient(asFetch(async () => jsonResponse(body, { status: 400 })));
    const error = await client.get('galleries').catch((e: unknown) => e);
    expect(isApiError(error)).toBe(true);
    const api = error as ApiError;
    expect(api.code).toBe('swg_invalid_param');
    expect(api.status).toBe(400);
    expect(api.params).toEqual({ title: 'Required', n: '5' });
    expect(api.isAborted).toBe(false);
    expect(api.isNetwork).toBe(false);
  });

  it('handles non-JSON error responses with retries exhausted on 502', async () => {
    const fetchMock = vi.fn(async () => new Response('<html>boom</html>', { status: 502 }));
    const error = (await makeClient(asFetch(fetchMock))
      .get('health')
      .catch((e: unknown) => e)) as ApiError;
    expect(error.code).toBe('swg_http_502');
    expect(error.status).toBe(502);
    expect(fetchMock).toHaveBeenCalledTimes(3); // 1 + default 2 retries
  });

  it('surfaces HTTP 409 as an explicit ConflictError carrying server details', async () => {
    const body = {
      code: 'swg_revision_conflict',
      message: 'Stale',
      data: { status: 409, current_revision: 7 },
    };
    const fetchMock = vi.fn(async () => jsonResponse(body, { status: 409 }));
    const error = await makeClient(asFetch(fetchMock))
      .put('galleries/1', { revision: 3 })
      .catch((e: unknown) => e);
    expect(error).toBeInstanceOf(ConflictError);
    expect(isConflictError(error)).toBe(true);
    expect((error as ConflictError).status).toBe(409);
    expect((error as ConflictError).details).toMatchObject({ current_revision: 7 });
    expect(fetchMock).toHaveBeenCalledTimes(1); // never retried
  });

  it('treats a bare HTTP 409 (no WP_Error body) as conflict too', () => {
    expect(normaliseError(409, null)).toBeInstanceOf(ConflictError);
    expect(normaliseError(404, 'text').code).toBe('swg_http_404');
    expect(isConflictError(new ApiError('x', 'y', 500))).toBe(false);
  });

  it('maps an aborted fetch to swg_aborted without retrying', async () => {
    const fetchMock = vi.fn(async () => {
      throw new DOMException('Aborted', 'AbortError');
    });
    const error = (await makeClient(asFetch(fetchMock))
      .get('x')
      .catch((e: unknown) => e)) as ApiError;
    expect(error.isAborted).toBe(true);
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('cancels through an AbortSignal', async () => {
    const controller = new AbortController();
    const fetchImpl = (_url: string, init: RequestInit) =>
      new Promise<Response>((_resolve, reject) => {
        init.signal?.addEventListener('abort', () =>
          reject(new DOMException('Aborted', 'AbortError')),
        );
      });
    const pending = makeClient(asFetch(fetchImpl)).get('x', undefined, controller.signal);
    controller.abort();
    expect(((await pending.catch((e: unknown) => e)) as ApiError).isAborted).toBe(true);
  });
});

describe('retry policy', () => {
  it('retries network errors on GET with exponential backoff, then succeeds', async () => {
    const sleep = vi.fn(async () => {});
    const fetchMock = vi
      .fn()
      .mockRejectedValueOnce(new TypeError('offline'))
      .mockRejectedValueOnce(new TypeError('offline'))
      .mockResolvedValueOnce(jsonResponse({ ok: true }));
    const client = makeClient(asFetch(fetchMock), {
      sleep,
      retry: { retries: 2, baseDelayMs: 100 },
    });
    expect(await client.get('x')).toEqual({ ok: true });
    expect(sleep.mock.calls.map((c) => (c as unknown as [number])[0])).toEqual([100, 200]);
  });

  it('reports a network error after the bounded attempts', async () => {
    const fetchMock = vi.fn().mockRejectedValue(new TypeError('offline'));
    const error = (await makeClient(asFetch(fetchMock), { retry: { retries: 1 } })
      .get('x')
      .catch((e: unknown) => e)) as ApiError;
    expect(error.isNetwork).toBe(true);
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('caps retries at MAX_RETRIES and clamps negative values', async () => {
    const fetchMock = vi.fn().mockRejectedValue(new TypeError('offline'));
    await makeClient(asFetch(fetchMock), { retry: { retries: 99 } })
      .get('x')
      .catch(() => null);
    expect(fetchMock).toHaveBeenCalledTimes(MAX_RETRIES + 1);
    const once = vi.fn().mockRejectedValue(new TypeError('offline'));
    await makeClient(asFetch(once), { retry: { retries: -5, baseDelayMs: -1 } })
      .get('x')
      .catch(() => null);
    expect(once).toHaveBeenCalledTimes(1);
  });

  it('never retries writes', async () => {
    const fetchMock = vi.fn(async () => new Response('', { status: 503 }));
    await makeClient(asFetch(fetchMock))
      .post('galleries', {})
      .catch(() => null);
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('does not retry client errors', async () => {
    const fetchMock = vi.fn(async () => jsonResponse({ code: 'c', message: 'm' }, { status: 403 }));
    await makeClient(asFetch(fetchMock))
      .get('x')
      .catch(() => null);
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('stops waiting when aborted during the backoff', async () => {
    const sleep = vi.fn(async () => {
      throw new DOMException('Aborted', 'AbortError');
    });
    const fetchMock = vi.fn().mockRejectedValue(new TypeError('offline'));
    const error = (await makeClient(asFetch(fetchMock), { sleep })
      .get('x')
      .catch((e: unknown) => e)) as ApiError;
    expect(error.isAborted).toBe(true);
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('uses the real abortable sleep by default', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(new Response('', { status: 503 }))
      .mockResolvedValueOnce(jsonResponse({ ok: 1 }));
    const client = new ApiClient({
      restRoot: 'https://x.test/r/',
      nonce: 'n',
      fetchImpl: asFetch(fetchMock),
      retry: { retries: 1, baseDelayMs: 1 },
    });
    expect(await client.get('x')).toEqual({ ok: 1 });

    const controller = new AbortController();
    const slow = new ApiClient({
      restRoot: 'https://x.test/r/',
      nonce: 'n',
      fetchImpl: asFetch(vi.fn().mockRejectedValue(new TypeError('offline'))),
      retry: { retries: 1, baseDelayMs: 60_000 },
    });
    const pending = slow.get('x', undefined, controller.signal);
    await new Promise((r) => setTimeout(r, 5));
    controller.abort();
    expect(((await pending.catch((e: unknown) => e)) as ApiError).isAborted).toBe(true);

    const already = new AbortController();
    already.abort();
    const early = await slow.get('x', undefined, already.signal).catch((e: unknown) => e);
    expect((early as ApiError).isAborted).toBe(true);
  });
});
