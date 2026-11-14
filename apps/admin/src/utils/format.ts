import { getConfig } from '@/config';

/** `de_DE_formal` -> `de-DE`, `ar` -> `ar`; anything Intl rejects falls back to English (BCP 47 tag for Intl). */
export function localeTag(wpLocale: string): string {
  const [language, region] = wpLocale.split('_');
  const tag =
    region !== undefined && /^[A-Za-z]{2}$/.test(region) ? `${language}-${region}` : language;
  try {
    return Intl.getCanonicalLocales(tag ?? 'en')[0] ?? 'en';
  } catch {
    return 'en';
  }
}

/**
 * Formats an ISO UTC timestamp in the user's locale and the WordPress site timezone. An unknown timezone falls back
 * to UTC, an unparsable date to the raw value. The one date formatter of the app: no ad-hoc `toLocale*` calls.
 */
export function formatDateTime(iso: string, wpLocale: string, timezone: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso;
  const locale = localeTag(wpLocale);
  const options: Intl.DateTimeFormatOptions = { dateStyle: 'medium', timeStyle: 'short' };
  try {
    return new Intl.DateTimeFormat(locale, {
      ...options,
      ...(timezone === '' ? {} : { timeZone: timezone }),
    }).format(date);
  } catch {
    return new Intl.DateTimeFormat(locale, { ...options, timeZone: 'UTC' }).format(date);
  }
}

/** Number with the user's locale separators (`1.234` vs `1,234`). */
export function formatNumber(value: number, wpLocale: string): string {
  return new Intl.NumberFormat(localeTag(wpLocale)).format(value);
}

/** Formatters bound to the server bootstrap (locale and site timezone). */
export function useFormatters(): {
  dateTime: (iso: string) => string;
  number: (value: number) => string;
} {
  const config = getConfig();
  return {
    dateTime: (iso) => formatDateTime(iso, config.locale, config.timezone),
    number: (value) => formatNumber(value, config.locale),
  };
}
