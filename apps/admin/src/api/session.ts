import type { ClientOptions } from './client';

/** A WordPress nonce is a 10 character hex string; anything else (`0`, `-1`, HTML) means "not logged in". */
const NONCE_PATTERN = /^[A-Za-z0-9]{6,32}$/;

/**
 * Fetches a fresh `wp_rest` nonce from `admin-ajax.php?action=rest-nonce` (same origin, with cookies). Only the
 * bootstrap-provided `ajaxUrl` is used, never anything derived from user input. `null` = the session is gone.
 */
export function createNonceRefresher(
  ajaxUrl: string,
  fetchImpl: typeof fetch = (...args) => fetch(...args),
): NonNullable<ClientOptions['refreshNonce']> {
  return async (signal) => {
    const separator = ajaxUrl.includes('?') ? '&' : '?';
    const response = await fetchImpl(`${ajaxUrl}${separator}action=rest-nonce`, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      signal,
    });
    const text = (await response.text()).trim();
    if (response.status === 400 || response.status === 401 || response.status === 403) return null;
    if (!response.ok) throw new Error(`rest-nonce failed: HTTP ${response.status}`);
    return NONCE_PATTERN.test(text) ? text : null;
  };
}

type HeartbeatHandler = (event: unknown, data?: Record<string, unknown>) => void;
interface JQueryLike {
  (target: Document): {
    on(event: string, handler: HeartbeatHandler): unknown;
    off(event: string, handler: HeartbeatHandler): unknown;
  };
}

/**
 * Takes the fresh REST nonce that WordPress puts into every Heartbeat response (`rest_nonce`, core's
 * `wp_refresh_heartbeat_nonces`). Heartbeat is jQuery based; without it (or jQuery) this is a no-op and the reactive
 * refresh in the ApiClient still keeps the session usable. Returns a function that detaches the listener.
 */
export function attachHeartbeatNonce(
  onNonce: (nonce: string) => void,
  jquery: JQueryLike | undefined = (window as unknown as { jQuery?: JQueryLike }).jQuery,
  doc: Document = document,
): () => void {
  if (typeof jquery !== 'function') return () => {};
  const handler: HeartbeatHandler = (_event, data) => {
    const nonce = data?.rest_nonce;
    if (typeof nonce === 'string' && NONCE_PATTERN.test(nonce)) onNonce(nonce);
  };
  const target = jquery(doc);
  target.on('heartbeat-tick', handler);
  return () => {
    target.off('heartbeat-tick', handler);
  };
}
