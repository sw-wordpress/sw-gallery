/**
 * Bootstrap object printed by PHP as `window.swgAdmin` before the module script.
 * Single source for REST root, nonce, locale and capabilities: never read from
 * localStorage, URLs or cookies.
 */
export interface SwgAdminBootstrap {
  /** REST root of the plugin namespace, e.g. `https://x/wp-json/swg/v1/` (trailing `/` optional). */
  restRoot: string;
  /** `wp_rest` nonce, sent as `X-WP-Nonce`. Kept in memory only. */
  nonce: string;
  /** WordPress user locale, e.g. `de_DE`, `en_US`, `ar`. */
  locale: string;
  /** Plugin version (display only). */
  version: string;
  /** `admin_url()` with trailing slash. */
  adminUrl: string;
  /** Plugin capabilities of the current user (UX hints only; the server enforces). */
  capabilities: string[];
  /** `is_rtl()` for the user locale. Optional, defaults to false. */
  isRtl?: boolean;
  /** WordPress timezone (`wp_timezone_string()`, IANA name or `+02:00`). Optional. */
  timezone?: string;
  /** `admin-ajax.php` URL, used for the `rest-nonce` refresh. Optional: without it only Heartbeat refreshes the nonce. */
  ajaxUrl?: string;
  /** `wp_login_url()` with a redirect back to this page, for the "session expired" state. Optional. */
  loginUrl?: string;
  /** Public URL of the build directory (trailing slash). Optional. */
  assetsUrl?: string;
}

/** Normalised, validated form used by the app. */
export interface SwgConfig {
  restRoot: string;
  nonce: string;
  locale: string;
  version: string;
  adminUrl: string;
  capabilities: string[];
  isRtl: boolean;
  timezone: string;
  ajaxUrl: string;
  loginUrl: string;
  assetsUrl: string;
}
