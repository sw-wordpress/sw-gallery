import type { SwgAdminBootstrap, SwgConfig } from '@/types/bootstrap';

let cached: SwgConfig | null = null;

function str(value: unknown, fallback: string): string {
  return typeof value === 'string' ? value : fallback;
}

/** Validate/normalise the PHP-provided bootstrap. Throws if the REST root is missing. */
export function normaliseConfig(raw: Partial<SwgAdminBootstrap> | undefined): SwgConfig {
  if (!raw || typeof raw.restRoot !== 'string' || raw.restRoot === '') {
    throw new Error('window.swgAdmin.restRoot is missing');
  }
  return {
    restRoot: raw.restRoot.endsWith('/') ? raw.restRoot : `${raw.restRoot}/`,
    nonce: str(raw.nonce, ''),
    locale: str(raw.locale, 'en_US'),
    version: str(raw.version, ''),
    adminUrl: str(raw.adminUrl, ''),
    capabilities: Array.isArray(raw.capabilities)
      ? raw.capabilities.filter((c): c is string => typeof c === 'string')
      : [],
    isRtl: raw.isRtl === true,
    timezone: str(raw.timezone, ''),
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
