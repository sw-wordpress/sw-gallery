/*
 * Security boundary of the editor live preview (CODE-STYLE §5/§7).
 *
 * The REST response carries renderer markup plus asset URLs. Both are UNTRUSTED for the admin
 * DOM. The only way they are shown is an `<iframe sandbox="allow-scripts" srcdoc="...">` (no
 * `allow-same-origin`: the frame has an opaque origin and cannot reach the admin page, cookies or
 * the REST nonce). The document built here adds a CSP meta tag that allows exactly the validated
 * assets, same-origin images and one nonce-protected inline script (height report and click
 * guard). Nothing in this file touches the DOM.
 */

/** Largest height the frame reports/accepts (pixels); content beyond it scrolls inside the frame. */
export const MAX_FRAME_HEIGHT = 100000;

const SAFE_LANG = /^[A-Za-z0-9]{1,8}(?:-[A-Za-z0-9]{1,8}){0,4}$/;
/** Path characters that cannot break out of a CSP source expression. */
const SAFE_PATH = /^[A-Za-z0-9._~%/+@:=-]+$/;

export interface ValidatedAssets {
  css: string[];
  js: string[];
  /** Number of URLs that were rejected (reported to the user: "not everything was loaded"). */
  dropped: number;
}

/** Normalised absolute URL, or `null` unless it is http(s), credential-free, same origin and CSP-safe. */
export function validateAssetUrl(raw: string, origin: string): string | null {
  let url: URL;
  try {
    url = new URL(raw, origin);
  } catch {
    return null;
  }
  if (url.protocol !== 'http:' && url.protocol !== 'https:') return null;
  if (url.origin !== origin || url.username !== '' || url.password !== '') return null;
  if (!SAFE_PATH.test(url.pathname)) return null;
  return url.href;
}

/** Keeps only the assets that pass `validateAssetUrl` (duplicates removed, order kept). */
export function validateAssets(
  assets: { css: readonly string[]; js: readonly string[] },
  origin: string,
): ValidatedAssets {
  let dropped = 0;
  const pick = (list: readonly string[]): string[] => {
    const out: string[] = [];
    for (const raw of list) {
      const ok = validateAssetUrl(raw, origin);
      if (ok === null) dropped += 1;
      else if (!out.includes(ok)) out.push(ok);
    }
    return out;
  };
  const css = pick(assets.css);
  const js = pick(assets.js);
  return { css, js, dropped };
}

export function escapeHtmlAttr(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

/** Random hex token (message token and CSP nonce). */
export function randomToken(bytes = 16): string {
  const data = new Uint8Array(bytes);
  if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
    crypto.getRandomValues(data);
  } else {
    for (let i = 0; i < bytes; i += 1) data[i] = Math.floor(Math.random() * 256);
  }
  return Array.from(data, (b) => b.toString(16).padStart(2, '0')).join('');
}

export interface SrcdocInput {
  html: string;
  assets: ValidatedAssets;
  /** `window.location.origin` of the admin page. */
  origin: string;
  dark: boolean;
  dir: 'ltr' | 'rtl';
  lang: string;
  /** Binds height messages to this render. */
  token: string;
  /** CSP nonce of the inline reporter script. */
  nonce: string;
}

/** The CSP of the preview document. Exported for tests. */
export function buildCsp(input: Pick<SrcdocInput, 'assets' | 'origin' | 'nonce'>): string {
  const exact = (list: string[]): string[] =>
    list.map((href) => {
      const u = new URL(href);
      return `${u.origin}${u.pathname}`;
    });
  const styles = ['style-src', ...exact(input.assets.css), "'unsafe-inline'"];
  const scripts = ['script-src', ...exact(input.assets.js), `'nonce-${input.nonce}'`];
  return [
    "default-src 'none'",
    styles.join(' '),
    scripts.join(' '),
    `img-src ${input.origin} data:`,
    `font-src ${input.origin} data:`,
    "connect-src 'none'",
    "frame-src 'none'",
    "object-src 'none'",
    "media-src 'none'",
    "base-uri 'none'",
    "form-action 'none'",
  ].join('; ');
}

/** JSON usable inside an inline script (no `</script>`, no HTML comment openers). */
function jsString(value: string): string {
  return JSON.stringify(value).replace(/</g, '\\u003c').replace(/>/g, '\\u003e');
}

function reporter(token: string, origin: string, nonce: string): string {
  const code =
    '(function(){var T=' +
    jsString(token) +
    ',O=' +
    jsString(origin) +
    ';' +
    'function send(){var h=Math.ceil(document.documentElement.getBoundingClientRect().height);' +
    "parent.postMessage({swgPreview:T,type:'height',height:h},O);}" +
    'if(window.ResizeObserver){new ResizeObserver(send).observe(document.documentElement);}' +
    "window.addEventListener('load',send);send();" +
    "window.addEventListener('click',function(e){var t=e.target;" +
    "var a=t&&t.closest?t.closest('a[href]'):null;if(a&&!e.defaultPrevented){e.preventDefault();}});" +
    "window.addEventListener('submit',function(e){e.preventDefault();});" +
    '})();';
  return `<script nonce="${escapeHtmlAttr(nonce)}">${code}</script>`;
}

/** The complete srcdoc document. `html` is inserted as markup into the body of the sandboxed document only. */
export function buildSrcdoc(input: SrcdocInput): string {
  const lang = SAFE_LANG.test(input.lang) ? input.lang : 'en';
  const scheme = input.dark ? 'dark' : 'light';
  const bg = input.dark ? '#131a2e' : '#ffffff';
  const fg = input.dark ? '#e8ecf8' : '#1f2937';
  const links = input.assets.css
    .map((href) => `<link rel="stylesheet" href="${escapeHtmlAttr(href)}">`)
    .join('');
  const scripts = input.assets.js
    .map((href) => `<script defer src="${escapeHtmlAttr(href)}"></script>`)
    .join('');
  return (
    `<!doctype html><html lang="${lang}" dir="${input.dir}"><head><meta charset="utf-8">` +
    `<meta http-equiv="Content-Security-Policy" content="${escapeHtmlAttr(buildCsp(input))}">` +
    '<meta name="viewport" content="width=device-width, initial-scale=1">' +
    `<meta name="color-scheme" content="${scheme}">` +
    `<style>html{color-scheme:${scheme}}body{margin:0;padding:16px;background:${bg};color:${fg};` +
    'font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}</style>' +
    `${links}</head><body>${input.html}${scripts}` +
    `${reporter(input.token, input.origin, input.nonce)}</body></html>`
  );
}

export interface FrameMessage {
  origin: string;
  source: unknown;
  data: unknown;
}

/**
 * Validates a `message` event from the preview frame and returns the reported height, or `null`
 * when it must be ignored: wrong window, origin other than the opaque `"null"` of a sandboxed
 * frame, wrong shape, wrong render token or a nonsensical number.
 */
export function parseHeightMessage(
  event: FrameMessage,
  frameWindow: unknown,
  token: string,
): number | null {
  if (frameWindow === null || frameWindow === undefined || event.source !== frameWindow)
    return null;
  if (event.origin !== 'null') return null;
  const data = event.data;
  if (data === null || typeof data !== 'object' || Array.isArray(data)) return null;
  const record = data as Record<string, unknown>;
  const keys = Object.keys(record);
  if (keys.length !== 3 || !keys.every((k) => ['swgPreview', 'type', 'height'].includes(k))) {
    return null;
  }
  if (record.swgPreview !== token || record.type !== 'height') return null;
  const height = record.height;
  if (typeof height !== 'number' || !Number.isFinite(height)) return null;
  if (height < 0 || height > MAX_FRAME_HEIGHT) return null;
  return Math.ceil(height);
}
