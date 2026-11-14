import type { SwgAdminBootstrap, SwgConfig } from '@/types/bootstrap';

let cached: SwgConfig | null = null;

function str(value: unknown, fallback: string): string {
  return typeof value === 'string' ? value : fallback;
}

/** An absolute http(s) URL or `''`: a bootstrap value that is not one (e.g. `javascript:`) is dropped, never used. */
function webUrl(value: unknown): string {
  if (typeof value !== 'string' || value === '') return '';
  try {
    const url = new URL(value, window.location.href);
    return url.protocol === 'http:' || url.protocol === 'https:' ? value : '';
  } catch {
    return '';
  }
}

/**
 * Validate/normalise the PHP-provided bootstrap once. Throws if the REST root or the nonce is missing or not
 * a web URL / string (the caller shows a translated error screen). Optional fields get safe defaults.
 */
export function normaliseConfig(raw: Partial<SwgAdminBootstrap> | undefined): SwgConfig {
  if (!raw || typeof raw !== 'object') {
    throw new Error('window.swgAdmin is missing');
  }
  if (webUrl(raw.restRoot) === '') {
    throw new Error('window.swgAdmin.restRoot is missing or not a web URL');
  }
  if (typeof raw.nonce !== 'string' || raw.nonce === '') {
    throw new Error('window.swgAdmin.nonce is missing');
  }
  const restRoot = raw.restRoot as string;
  return {
    restRoot: restRoot.endsWith('/') ? restRoot : `${restRoot}/`,
    nonce: raw.nonce,
    locale: str(raw.locale, 'en_US'),
    version: str(raw.version, ''),
    adminUrl: str(raw.adminUrl, ''),
    capabilities: Array.isArray(raw.capabilities)
      ? raw.capabilities.filter((c): c is string => typeof c === 'string')
      : [],
    isRtl: raw.isRtl === true,
    timezone: str(raw.timezone, ''),
    ajaxUrl: webUrl(raw.ajaxUrl),
    loginUrl: webUrl(raw.loginUrl),
    assetsUrl: webUrl(raw.assetsUrl),
  };
}

export function getConfig(): SwgConfig {
  cached ??= normaliseConfig(window.swgAdmin);
  return cached;
}

/** For tests. */
export function resetConfigForTests(): void {
  cached = null;
}

export function hasCapability(config: SwgConfig, capability: string): boolean {
  return config.capabilities.includes(capability);
}

export const UI_LOCALES = ['en', 'de'] as const;
export type UiLocale = (typeof UI_LOCALES)[number];

/** Maps a WordPress locale (de_DE, de_CH_informal, ...) to a supported UI language; English is the fallback. */
export function resolveUiLocale(locale: string): UiLocale {
  const language = locale.toLowerCase().slice(0, 2);
  return (UI_LOCALES as readonly string[]).includes(language) ? (language as UiLocale) : 'en';
}
