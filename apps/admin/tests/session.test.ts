import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { Quasar } from 'quasar';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiClient, ApiError, NONCE_ERROR_CODE } from '../src/api/client';
import { attachHeartbeatNonce, createNonceRefresher } from '../src/api/session';
import { errorMessage } from '../src/api/errorMessage';
import { createAppI18n } from '../src/i18n';
import SessionBanner from '../src/layouts/SessionBanner.vue';
import { useAppStore } from '../src/stores/app';
import { resetConfigForTests } from '../src/config';
import { resetApiForTests } from '../src/api';

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}
const nonceError = (): Response =>
  json({ code: NONCE_ERROR_CODE, message: 'Cookie check failed', data: { status: 403 } }, 403);
const notLoggedIn = (): Response =>
  json({ code: 'rest_not_logged_in', message: 'x', data: { status: 401 } }, 401);

function client(fetchMock: ReturnType<typeof vi.fn>, extra = {}): ApiClient {
  return new ApiClient({
    restRoot: 'https://x.test/wp-json/swg/v1/',
    nonce: 'old',
    fetchImpl: fetchMock as unknown as typeof fetch,
    sleep: async () => {},
    ...extra,
  });
}
const nonceHeaders = (mock: ReturnType<typeof vi.fn>): string[] =>
  mock.mock.calls
    .filter((c) => String(c[0]).includes('/swg/v1/'))
    .map((c) => ((c[1] as RequestInit).headers as Record<string, string>)['X-WP-Nonce'] ?? '');

describe('ApiClient nonce refresh', () => {
  it('refreshes once on a nonce failure and replays the request with the fresh nonce, for writes too', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(nonceError())
      .mockResolvedValueOnce(json({ id: 3 }));
    const refresh = vi.fn(async () => 'freshnonce1');
    const result = await client(fetchMock, { refreshNonce: refresh }).post('galleries', { a: 1 });
    expect(result).toEqual({ id: 3 });
    expect(refresh).toHaveBeenCalledTimes(1);
    expect(nonceHeaders(fetchMock)).toEqual(['old', 'freshnonce1']);
  });

  it('never loops: a second nonce failure is thrown after exactly one refresh', async () => {
    const fetchMock = vi.fn(async () => nonceError());
    const refresh = vi.fn(async () => 'freshnonce1');
    const promise = client(fetchMock, { refreshNonce: refresh }).get('galleries');
    await expect(promise).rejects.toMatchObject({ code: NONCE_ERROR_CODE });
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(refresh).toHaveBeenCalledTimes(1);
  });

  it('reports a gone session when the refresh answers null and throws the original error', async () => {
    const fetchMock = vi.fn(async () => nonceError());
    const expired = vi.fn();
    const api = client(fetchMock, { refreshNonce: async () => null, onSessionExpired: expired });
    await expect(api.post('galleries', {})).rejects.toMatchObject({ code: NONCE_ERROR_CODE });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(expired).toHaveBeenCalledTimes(1);
  });

  it('does not claim an expired session when the refresh itself fails (server unreachable)', async () => {
    const expired = vi.fn();
    const api = client(
      vi.fn(async () => nonceError()),
      {
        refreshNonce: async () => {
          throw new Error('offline');
        },
        onSessionExpired: expired,
      },
    );
    await expect(api.get('galleries')).rejects.toMatchObject({ code: NONCE_ERROR_CODE });
    expect(expired).not.toHaveBeenCalled();
  });

  it('treats 401 as a gone session without refreshing or retrying', async () => {
    const fetchMock = vi.fn(async () => notLoggedIn());
    const refresh = vi.fn(async () => 'x');
    const expired = vi.fn();
    const api = client(fetchMock, { refreshNonce: refresh, onSessionExpired: expired });
    await expect(api.post('galleries', {})).rejects.toMatchObject({ status: 401 });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(refresh).not.toHaveBeenCalled();
    expect(expired).toHaveBeenCalledTimes(1);
  });

  it('reports a gone session when the replay answers 401', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(nonceError())
      .mockResolvedValueOnce(notLoggedIn());
    const expired = vi.fn();
    const api = client(fetchMock, {
      refreshNonce: async () => 'freshnonce1',
      onSessionExpired: expired,
    });
    await expect(api.get('galleries')).rejects.toMatchObject({ status: 401 });
    expect(expired).toHaveBeenCalledTimes(1);
  });

  it('does not retry other 403 errors and works without a refresher', async () => {
    const forbidden = (): Response =>
      json({ code: 'rest_forbidden', message: 'x', data: { status: 403 } }, 403);
    const refresh = vi.fn(async () => 'x');
    const a = client(
      vi.fn(async () => forbidden()),
      { refreshNonce: refresh },
    );
    await expect(a.get('galleries')).rejects.toMatchObject({ code: 'rest_forbidden' });
    expect(refresh).not.toHaveBeenCalled();
    const b = client(vi.fn(async () => nonceError()));
    await expect(b.get('galleries')).rejects.toMatchObject({ code: NONCE_ERROR_CODE });
  });

  it('shares one refresh between concurrent requests', async () => {
    const fetchMock = vi.fn(async (_url: string, init?: RequestInit) => {
      const nonce = (init?.headers as Record<string, string>)['X-WP-Nonce'];
      return nonce === 'old' ? nonceError() : json({ ok: true });
    });
    const refresh = vi.fn(async () => {
      await Promise.resolve();
      return 'freshnonce1';
    });
    const api = client(fetchMock, { refreshNonce: refresh });
    await Promise.all([api.get('a'), api.get('b'), api.get('c')]);
    expect(refresh).toHaveBeenCalledTimes(1);
  });

  it('aborts cleanly while refreshing', async () => {
    const controller = new AbortController();
    const api = client(
      vi.fn(async () => nonceError()),
      {
        refreshNonce: async (signal?: AbortSignal) => {
          controller.abort();
          if (signal?.aborted) throw new DOMException('Aborted', 'AbortError');
          return 'x';
        },
      },
    );
    await expect(api.get('a', undefined, controller.signal)).rejects.toBeInstanceOf(ApiError);
  });

  it('renewSession returns true with a fresh nonce used afterwards, false without a refresher', async () => {
    const fetchMock = vi.fn(async () => json({}));
    const api = client(fetchMock, { refreshNonce: async () => 'freshnonce2' });
    expect(await api.renewSession()).toBe(true);
    await api.get('x');
    expect(nonceHeaders(fetchMock)).toEqual(['freshnonce2']);
    expect(await client(fetchMock).renewSession()).toBe(false);
  });

  it('retries an upload once after a nonce failure', async () => {
    const sent: string[] = [];
    let attempt = 0;
    const factory = (): XMLHttpRequest => {
      const xhr = {
        upload: {} as XMLHttpRequestUpload,
        status: 0,
        responseText: '',
        withCredentials: false,
        open: vi.fn(),
        setRequestHeader: (name: string, value: string) => {
          if (name === 'X-WP-Nonce') sent.push(value);
        },
        getResponseHeader: () => null,
        abort: vi.fn(),
        send: () => {
          attempt += 1;
          xhr.status = attempt === 1 ? 403 : 201;
          xhr.responseText =
            attempt === 1
              ? JSON.stringify({ code: NONCE_ERROR_CODE, message: 'x', data: { status: 403 } })
              : '{"id":9}';
          queueMicrotask(() => (xhr as unknown as { onload: () => void }).onload());
        },
      };
      return xhr as unknown as XMLHttpRequest;
    };
    const api = client(vi.fn(), { xhrFactory: factory, refreshNonce: async () => 'freshnonce3' });
    await expect(api.upload('media', new Blob(['x']))).resolves.toEqual({ id: 9 });
    expect(sent).toEqual(['old', 'freshnonce3']);
  });
});

describe('nonce refresher and heartbeat', () => {
  it('reads the nonce from admin-ajax, same origin with cookies', async () => {
    const fetchMock = vi.fn(async () => new Response('abc123def4'));
    const nonce = await createNonceRefresher(
      'https://x.test/wp-admin/admin-ajax.php',
      fetchMock as unknown as typeof fetch,
    )();
    expect(nonce).toBe('abc123def4');
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe('https://x.test/wp-admin/admin-ajax.php?action=rest-nonce');
    expect(init.credentials).toBe('same-origin');
  });

  it('answers null for logged-out answers and throws for server errors', async () => {
    const make = (body: string, status: number) =>
      createNonceRefresher(
        'https://x.test/a.php?x=1',
        (async () => new Response(body, { status })) as unknown as typeof fetch,
      );
    expect(await make('0', 200)()).toBeNull();
    expect(await make('<html>', 200)()).toBeNull();
    expect(await make('-1', 403)()).toBeNull();
    await expect(make('', 500)()).rejects.toThrow('HTTP 500');
  });

  it('applies the rest_nonce of heartbeat ticks and detaches', () => {
    type Handler = (e: unknown, d?: Record<string, unknown>) => void;
    const handlers = new Map<string, Handler>();
    const jq = vi.fn(() => ({
      on: (event: string, h: Handler) => handlers.set(event, h),
      off: (event: string) => handlers.delete(event),
    }));
    const seen: string[] = [];
    const detach = attachHeartbeatNonce((n) => seen.push(n), jq as never);
    const tick = handlers.get('heartbeat-tick');
    tick?.({}, { rest_nonce: 'abcdef1234' });
    tick?.({}, { rest_nonce: '<bad>' });
    tick?.({}, undefined);
    expect(seen).toEqual(['abcdef1234']);
    detach();
    expect(handlers.size).toBe(0);
  });

  it('is a no-op without jQuery', () => {
    expect(() => attachHeartbeatNonce(() => {}, undefined)()).not.toThrow();
  });
});

describe('session expired banner', () => {
  beforeEach(() => {
    resetConfigForTests();
    resetApiForTests();
    window.swgAdmin = {
      restRoot: 'https://x.test/wp-json/swg/v1/',
      nonce: 'n1',
      locale: 'en_US',
      version: '',
      adminUrl: '',
      capabilities: [],
      loginUrl: 'https://x.test/wp-login.php?redirect_to=a',
      ajaxUrl: 'https://x.test/wp-admin/admin-ajax.php',
    };
  });
  afterEach(() => {
    delete window.swgAdmin;
    resetConfigForTests();
    resetApiForTests();
    vi.unstubAllGlobals();
  });

  function mountBanner() {
    const pinia = createPinia();
    setActivePinia(pinia);
    return mount(SessionBanner, {
      global: { plugins: [pinia, createAppI18n('en_US'), [Quasar, {}]] },
    });
  }

  it('links to the bootstrap login URL in a new tab and clears itself once the nonce is available again', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => new Response('freshnonce9')),
    );
    const wrapper = mountBanner();
    const app = useAppStore();
    app.setSessionExpired(true);
    const link = wrapper.find('a[href^="https://x.test/wp-login.php"]');
    expect(link.attributes('target')).toBe('_blank');
    expect(link.attributes('rel')).toContain('noopener');
    await wrapper.find('button').trigger('click');
    await flushPromises();
    expect(app.sessionExpired).toBe(false);
  });

  it('says so when the user is still logged out', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => new Response('0')),
    );
    const wrapper = mountBanner();
    useAppStore().setSessionExpired(true);
    await wrapper.find('button').trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('still logged out');
    expect(useAppStore().sessionExpired).toBe(true);
  });

  it('maps 401 to the session-expired message', () => {
    const t = (key: string): string => key;
    expect(errorMessage(new ApiError('rest_not_logged_in', 'x', 401), t)).toBe(
      'errors.sessionExpired',
    );
  });
});
