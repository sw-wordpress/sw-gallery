import { afterEach, describe, expect, it, vi } from 'vitest';
import { errorMessage } from '../src/api/errorMessage';
import { ApiError, ConflictError } from '../src/api/client';
import { getApi, resetApiForTests } from '../src/api';
import {
  getConfig,
  hasCapability,
  normaliseConfig,
  resetConfigForTests,
  resolveUiLocale,
} from '../src/config';
import de from '../src/i18n/de';
import en from '../src/i18n/en';
import quasarLang from '../src/i18n/quasarLang';
import { createAppI18n } from '../src/i18n';

type Tree = { [key: string]: string | Tree };

function flatten(tree: Tree, prefix = ''): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [key, value] of Object.entries(tree)) {
    if (typeof value === 'string') out[prefix + key] = value;
    else Object.assign(out, flatten(value, `${prefix}${key}.`));
  }
  return out;
}

const placeholders = (text: string): string[] => (text.match(/\{[a-zA-Z0-9_]+\}/g) ?? []).sort();

describe('translation catalogs', () => {
  const reference = flatten(en as unknown as Tree);

  it('de has exactly the keys of en, with the same placeholders and plural forms', () => {
    const flat = flatten(de as unknown as Tree);
    expect(Object.keys(flat).sort()).toEqual(Object.keys(reference).sort());
    for (const [key, text] of Object.entries(flat)) {
      const base = reference[key] ?? '';
      expect(text.trim(), `de:${key} is empty`).not.toBe('');
      expect(placeholders(text), `de:${key} placeholders`).toEqual(placeholders(base));
      expect(text.split('|').length, `de:${key} plural forms`).toBe(base.split('|').length);
    }
  });

  it('the parity helper detects drift', () => {
    expect(placeholders('Hello {name}, {count} items')).toEqual(['{count}', '{name}']);
    expect(placeholders('a {x}')).not.toEqual(placeholders('a {y}'));
    expect('a | b | c'.split('|').length).not.toBe('a | b'.split('|').length);
  });

  it('resolves plural forms and placeholders at runtime', () => {
    const i18n = createAppI18n('de_DE');
    expect(i18n.global.t('common.items', 0)).toBe('keine Einträge');
    expect(i18n.global.t('common.items', 1)).toBe('ein Eintrag');
    expect(i18n.global.t('common.items', 4)).toBe('4 Einträge');
    expect(i18n.global.t('app.versionShort', { version: '1.2' })).toBe('v1.2');
  });

  it('maps WordPress locales to the supported UI languages and Quasar packs', () => {
    expect(resolveUiLocale('de_DE_formal')).toBe('de');
    expect(resolveUiLocale('de_CH')).toBe('de');
    expect(resolveUiLocale('en_GB')).toBe('en');
    expect(resolveUiLocale('ja')).toBe('en');
    expect(quasarLang('de').isoName).toBe('de');
    expect(quasarLang('en').isoName).toBe('en-US');
  });
});

describe('bootstrap config', () => {
  afterEach(() => {
    resetConfigForTests();
    resetApiForTests();
    delete window.swgAdmin;
    vi.restoreAllMocks();
  });

  it('normalises the server bootstrap', () => {
    const config = normaliseConfig({
      restRoot: 'https://x.test/wp-json/swg/v1',
      nonce: 'n',
      locale: 'de_DE',
      version: '1.0.0',
      adminUrl: 'https://x.test/wp-admin/',
      capabilities: ['swg_view', 5 as unknown as string],
      isRtl: true,
      timezone: 'Europe/Berlin',
    });
    expect(config.restRoot).toBe('https://x.test/wp-json/swg/v1/');
    expect(config.capabilities).toEqual(['swg_view']);
    expect(config.isRtl).toBe(true);
    expect(hasCapability(config, 'swg_view')).toBe(true);
    expect(hasCapability(config, 'swg_manage')).toBe(false);
  });

  it('applies safe defaults for optional members', () => {
    const config = normaliseConfig({ restRoot: 'https://x.test/r/', nonce: 'n' });
    expect(config).toEqual({
      restRoot: 'https://x.test/r/',
      nonce: 'n',
      locale: 'en_US',
      version: '',
      adminUrl: '',
      capabilities: [],
      isRtl: false,
      timezone: '',
      ajaxUrl: '',
      loginUrl: '',
      assetsUrl: '',
    });
  });

  it('keeps web URLs and drops anything else (javascript:, junk) for the optional URLs', () => {
    const config = normaliseConfig({
      restRoot: 'https://x.test/r/',
      nonce: 'n',
      ajaxUrl: 'https://x.test/wp-admin/admin-ajax.php',
      loginUrl: 'javascript:alert(1)',
      assetsUrl: 'http://[bad',
    });
    expect(config.ajaxUrl).toBe('https://x.test/wp-admin/admin-ajax.php');
    expect(config.loginUrl).toBe('');
    expect(config.assetsUrl).toBe('');
  });

  it('throws without a usable REST root or nonce', () => {
    expect(() => normaliseConfig(undefined)).toThrow('window.swgAdmin');
    expect(() => normaliseConfig({ restRoot: '', nonce: 'n' })).toThrow('restRoot');
    expect(() => normaliseConfig({ restRoot: 'javascript:1', nonce: 'n' })).toThrow('restRoot');
    expect(() => normaliseConfig({ restRoot: 'https://x.test/r/' })).toThrow('nonce');
    expect(() => normaliseConfig({ restRoot: 'https://x.test/r/', nonce: '' })).toThrow('nonce');
  });

  it('reads window.swgAdmin once and builds the shared API client from it', async () => {
    window.swgAdmin = {
      restRoot: 'https://x.test/r/',
      nonce: 'N1',
      locale: 'en_US',
      version: '',
      adminUrl: '',
      capabilities: [],
    };
    expect(getConfig()).toBe(getConfig());
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('{}'));
    expect(getApi()).toBe(getApi());
    await getApi().get('ping');
    const init = fetchMock.mock.calls[0]?.[1] as RequestInit;
    expect((init.headers as Record<string, string>)['X-WP-Nonce']).toBe('N1');
  });
});

describe('errorMessage', () => {
  const t = (key: string): string => key;

  it('translates by code and status, never echoing server text', () => {
    expect(errorMessage(new Error('x'), t)).toBe('errors.generic');
    expect(errorMessage(new ApiError('swg_aborted', 'm', 0), t)).toBe('errors.aborted');
    expect(errorMessage(new ApiError('swg_network', 'm', 0), t)).toBe('errors.network');
    expect(errorMessage(new ApiError('swg_invalid_response', 'm', 200), t)).toBe(
      'errors.invalidResponse',
    );
    expect(errorMessage(new ApiError('c', 'm', 400), t)).toBe('errors.validation');
    expect(errorMessage(new ApiError('c', 'm', 403), t)).toBe('errors.forbidden');
    expect(errorMessage(new ApiError('c', 'm', 401), t)).toBe('errors.sessionExpired');
    expect(errorMessage(new ApiError('c', 'm', 404), t)).toBe('errors.notFound');
    expect(errorMessage(new ConflictError('c', 'm'), t)).toBe('errors.conflict');
    expect(errorMessage(new ApiError('c', 'm', 500), t)).toBe('errors.server');
    expect(errorMessage(new ApiError('c', 'm', 418), t)).toBe('errors.generic');
  });
});
