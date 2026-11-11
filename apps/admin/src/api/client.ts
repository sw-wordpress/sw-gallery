export type QueryValue = string | number | boolean | null | undefined;
export type Query = Record<string, QueryValue>;

/** Normalised error for every failed request (HTTP, WP_Error or network). */
export class ApiError extends Error {
  readonly code: string;
  readonly status: number;
  readonly params: Record<string, string>;
  /** Raw `data` member of a WP_Error body (e.g. server state for reconciliation); untrusted. */
  readonly details: unknown;

  constructor(
    code: string,
    message: string,
    status: number,
    params: Record<string, string> = {},
    details: unknown = null,
  ) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
    this.params = params;
    this.details = details;
  }

  get isAborted(): boolean {
    return this.code === 'swg_aborted';
  }

  get isNetwork(): boolean {
    return this.code === 'swg_network';
  }
}

/** HTTP 409: the expected revision no longer matches. UI must offer a reconciliation path. */
export class ConflictError extends ApiError {
  constructor(
    code: string,
    message: string,
    params: Record<string, string> = {},
    details: unknown = null,
  ) {
    super(code, message, 409, params, details);
    this.name = 'ConflictError';
  }
}

export function isApiError(value: unknown): value is ApiError {
  return value instanceof ApiError;
}

export function isConflictError(value: unknown): value is ConflictError {
  return value instanceof ConflictError;
}

/** A successful response with its body and headers (needed for `X-WP-Total` style paging headers). */
export interface ApiResponse<T> {
  data: T;
  headers: Headers;
}

export interface RequestOptions {
  query?: Query;
  body?: unknown;
  signal?: AbortSignal;
}

export interface RetryPolicy {
  /** Extra attempts after the first one. Bounded: never above MAX_RETRIES. */
  retries: number;
  /** Delay before retry n is `baseDelayMs * 2 ** (n - 1)`. */
  baseDelayMs: number;
}

export interface ClientOptions {
  restRoot: string;
  nonce: string;
  fetchImpl?: typeof fetch;
  retry?: Partial<RetryPolicy>;
  /** Injectable for tests; must reject with an AbortError-like DOMException when aborted. */
  sleep?: (ms: number, signal?: AbortSignal) => Promise<void>;
}

export const MAX_RETRIES = 3;
const RETRYABLE_STATUS = new Set([502, 503, 504]);

interface WpErrorBody {
  code?: unknown;
  message?: unknown;
  data?: { status?: unknown; params?: unknown } | null;
}

function toParams(value: unknown): Record<string, string> {
  const out: Record<string, string> = {};
  if (value && typeof value === 'object') {
    for (const [k, v] of Object.entries(value as Record<string, unknown>)) {
      out[k] = typeof v === 'string' ? v : JSON.stringify(v);
    }
  }
  return out;
}

/** Turn any non-OK response body into an ApiError (ConflictError for 409). Exported for tests. */
export function normaliseError(status: number, body: unknown): ApiError {
  let code = `swg_http_${status}`;
  let message = `HTTP ${status}`;
  let params: Record<string, string> = {};
  let details: unknown = null;
  let effective = status;
  if (body && typeof body === 'object') {
    const b = body as WpErrorBody;
    if (typeof b.code === 'string' && typeof b.message === 'string') {
      code = b.code;
      message = b.message;
      effective = typeof b.data?.status === 'number' ? b.data.status : status;
      params = toParams(b.data?.params);
      details = b.data ?? null;
    }
  }
  if (effective === 409) return new ConflictError(code, message, params, details);
  return new ApiError(code, message, effective, params, details);
}

function abortError(): DOMException {
  return new DOMException('Aborted', 'AbortError');
}

function defaultSleep(ms: number, signal?: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    if (signal?.aborted) {
      reject(abortError());
      return;
    }
    const onAbort = (): void => {
      clearTimeout(timer);
      reject(abortError());
    };
    const timer = setTimeout(() => {
      signal?.removeEventListener('abort', onAbort);
      resolve();
    }, ms);
    signal?.addEventListener('abort', onAbort, { once: true });
  });
}

function isAbort(error: unknown): boolean {
  return error instanceof DOMException && error.name === 'AbortError';
}

/**
 * The single HTTP gateway of the app. Adds the REST nonce, sends cookies, speaks JSON,
 * normalises errors, supports cancellation and retries idempotent reads a bounded number of
 * times. Components must never call `fetch` directly.
 */
export class ApiClient {
  private readonly root: string;
  private nonce: string;
  private readonly fetchImpl: typeof fetch;
  private readonly policy: RetryPolicy;
  private readonly sleep: (ms: number, signal?: AbortSignal) => Promise<void>;

  constructor(options: ClientOptions) {
    this.root = options.restRoot.endsWith('/') ? options.restRoot : `${options.restRoot}/`;
    this.nonce = options.nonce;
    this.fetchImpl = options.fetchImpl ?? ((...args) => fetch(...args));
    this.sleep = options.sleep ?? defaultSleep;
    this.policy = {
      retries: Math.min(Math.max(options.retry?.retries ?? 2, 0), MAX_RETRIES),
      baseDelayMs: Math.max(options.retry?.baseDelayMs ?? 300, 0),
    };
  }

  /** Replace the nonce (e.g. after a Heartbeat refresh). Never persisted. */
  setNonce(nonce: string): void {
    this.nonce = nonce;
  }

  /** Absolute URL for a resource path (no leading slash) incl. query string. */
  url(path: string, query?: Query): string {
    const qs = new URLSearchParams();
    for (const [key, value] of Object.entries(query ?? {})) {
      if (value === undefined || value === null || value === '') continue;
      qs.set(key, String(value));
    }
    const base = `${this.root}${path.replace(/^\/+/, '')}`;
    const str = qs.toString();
    if (str === '') return base;
    // WP without pretty permalinks uses `?rest_route=`, so the root may already contain `?`.
    return `${base}${base.includes('?') ? '&' : '?'}${str}`;
  }

  async request<T>(method: string, path: string, options: RequestOptions = {}): Promise<T> {
    return (await this.requestFull<T>(method, path, options)).data;
  }

  /** Like `request`, but also returns the response headers (e.g. `X-WP-Total`, `X-WP-TotalPages`). */
  async requestFull<T>(
    method: string,
    path: string,
    options: RequestOptions = {},
  ): Promise<ApiResponse<T>> {
    const idempotent = method === 'GET' || method === 'HEAD';
    const maxAttempts = idempotent ? this.policy.retries + 1 : 1;
    let attempt = 0;
    for (;;) {
      attempt += 1;
      try {
        return await this.attempt<T>(method, path, options);
      } catch (error) {
        const retryable =
          error instanceof ApiError && (error.isNetwork || RETRYABLE_STATUS.has(error.status));
        if (!retryable || attempt >= maxAttempts) throw error;
        try {
          await this.sleep(this.policy.baseDelayMs * 2 ** (attempt - 1), options.signal);
        } catch {
          throw new ApiError('swg_aborted', 'Request aborted', 0);
        }
      }
    }
  }

  private async attempt<T>(
    method: string,
    path: string,
    options: RequestOptions,
  ): Promise<ApiResponse<T>> {
    const headers: Record<string, string> = {
      Accept: 'application/json',
      'X-WP-Nonce': this.nonce,
    };
    const init: RequestInit = {
      method,
      headers,
      credentials: 'same-origin',
      signal: options.signal,
    };
    if (options.body !== undefined) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(options.body);
    }

    let response: Response;
    try {
      response = await this.fetchImpl(this.url(path, options.query), init);
    } catch (error) {
      if (isAbort(error) || options.signal?.aborted) {
        throw new ApiError('swg_aborted', 'Request aborted', 0);
      }
      throw new ApiError('swg_network', 'Network error', 0);
    }

    const refreshed = response.headers.get('X-WP-Nonce');
    if (refreshed) this.nonce = refreshed;

    let parsed: unknown = null;
    const text = await response.text();
    if (text !== '') {
      try {
        parsed = JSON.parse(text);
      } catch {
        if (response.ok) {
          throw new ApiError('swg_invalid_response', 'Invalid JSON response', response.status);
        }
      }
    }

    if (!response.ok) throw normaliseError(response.status, parsed);
    return { data: parsed as T, headers: response.headers };
  }

  get<T>(path: string, query?: Query, signal?: AbortSignal): Promise<T> {
    return this.request<T>('GET', path, { query, signal });
  }
  post<T>(path: string, body?: unknown, options: Omit<RequestOptions, 'body'> = {}): Promise<T> {
    return this.request<T>('POST', path, { ...options, body: body ?? {} });
  }
  put<T>(path: string, body: unknown, options: Omit<RequestOptions, 'body'> = {}): Promise<T> {
    return this.request<T>('PUT', path, { ...options, body });
  }
  patch<T>(path: string, body: unknown, options: Omit<RequestOptions, 'body'> = {}): Promise<T> {
    return this.request<T>('PATCH', path, { ...options, body });
  }
  delete<T>(path: string, query?: Query): Promise<T> {
    return this.request<T>('DELETE', path, { query });
  }
}
