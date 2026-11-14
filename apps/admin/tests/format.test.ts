import { afterEach, describe, expect, it } from 'vitest';
import { resetConfigForTests } from '../src/config';
import { formatDateTime, formatNumber, localeTag, useFormatters } from '../src/utils/format';

describe('formatting helper', () => {
  afterEach(() => {
    delete window.swgAdmin;
    resetConfigForTests();
  });

  it('maps WordPress locales to BCP 47 tags', () => {
    expect(localeTag('de_DE')).toBe('de-DE');
    expect(localeTag('de_DE_formal')).toBe('de-DE');
    expect(localeTag('he_IL')).toBe('he-IL');
    expect(localeTag('ar')).toBe('ar');
    expect(localeTag('???')).toBe('en');
  });

  it('formats a date in the locale and the site timezone (fake zones, same instant)', () => {
    const iso = '2026-03-01T23:30:00Z';
    expect(formatDateTime(iso, 'de_DE', 'Europe/Berlin')).toContain('02.03.2026');
    expect(formatDateTime(iso, 'en_US', 'America/Los_Angeles')).toContain('3:30');
    expect(formatDateTime(iso, 'en_US', '+05:30')).toMatch(/Mar 2, 2026/);
    expect(formatDateTime(iso, 'en_US', 'Mars/Olympus')).toMatch(/Mar 1, 2026/); // invalid zone: UTC
  });

  it('formats numbers with the locale separators', () => {
    expect(formatNumber(1234567.5, 'de_DE')).toBe('1.234.567,5');
    expect(formatNumber(1234567.5, 'en_US')).toBe('1,234,567.5');
  });

  it('binds to the bootstrap locale and timezone', () => {
    resetConfigForTests();
    window.swgAdmin = {
      restRoot: 'https://x.test/r/',
      nonce: 'n',
      locale: 'de_DE_formal',
      version: '',
      adminUrl: '',
      capabilities: [],
      timezone: 'Asia/Tokyo',
    };
    const f = useFormatters();
    expect(f.dateTime('2026-03-01T23:30:00Z')).toContain('02.03.2026');
    expect(f.dateTime('2026-03-01T23:30:00Z')).toContain('08:30');
    expect(f.number(1000)).toBe('1.000');
  });
});
