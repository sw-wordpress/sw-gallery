import { describe, expect, it, vi } from 'vitest';
import apiFetch from '@wordpress/api-fetch';
import {
  describeError,
  galleriesPath,
  getGallery,
  isAbort,
  listGalleries,
  listLayouts,
  parseGalleries,
  parseGallery,
  parseLayouts,
} from '../src/api';
import {
  integerInRange,
  isLayoutId,
  LIMITS,
  sanitizeAttributes,
  toRenderOptions,
} from '../src/attributes';
import { FALLBACK_GALLERIES_URL, readConfig } from '../src/config';
import { editGalleryUrl, galleryListUrl } from '../src/links';

describe('attributes', () => {
  it('accepts only whole numbers in range', () => {
    expect(integerInRange(3, 1, 12)).toBe(3);
    expect(integerInRange(1, 1, 12)).toBe(1);
    expect(integerInRange(12, 1, 12)).toBe(12);
    for (const bad of [0, 13, -1, 2.5, '3', null, undefined, NaN, Infinity, {}, [3]]) {
      expect(integerInRange(bad, 1, 12)).toBeUndefined();
    }
  });

  it('recognises layout ids like the core pattern', () => {
    expect(isLayoutId('grid')).toBe(true);
    expect(isLayoutId('masonry-2_x')).toBe(true);
    for (const bad of ['', 'Grid', '1grid', 'a b', 'a/b', '<script>', 'x'.repeat(65), 5, null]) {
      expect(isLayoutId(bad)).toBe(false);
    }
  });

  it('drops invalid values and keeps eager only when it deviates', () => {
    expect(
      sanitizeAttributes({ galleryId: 5, layout: 'grid', columns: 4, gap: 8, eager: false }),
    ).toEqual({ galleryId: 5, layout: 'grid', columns: 4, gap: 8, eager: false });
    expect(sanitizeAttributes({ galleryId: 5, eager: true })).toEqual({ galleryId: 5 });
    expect(
      sanitizeAttributes({
        galleryId: -3,
        layout: 'No Good',
        columns: LIMITS.columns.max + 1,
        gap: -1,
      }),
    ).toEqual({ galleryId: 0 });
    expect(sanitizeAttributes({} as { galleryId: number })).toEqual({ galleryId: 0 });
  });

  it('maps attributes to the render options of the server', () => {
    expect(toRenderOptions({ galleryId: 5 })).toEqual({});
    expect(
      toRenderOptions({ galleryId: 5, layout: 'grid', columns: 3, gap: 0, eager: false }),
    ).toEqual({ layout: 'grid', settings: { columns: 3, gap: 0 }, eager: false });
    expect(toRenderOptions({ galleryId: 5, gap: 16 })).toEqual({ settings: { gap: 16 } });
    expect(toRenderOptions({ galleryId: 5, columns: 99 })).toEqual({});
  });
});

describe('config and links', () => {
  it('reads the URL from window.swgBlock and falls back safely', () => {
    expect(
      readConfig({
        swgBlock: { galleriesUrl: 'https://x.test/wp-admin/admin.php?page=swg-galleries' },
      }),
    ).toEqual({ galleriesUrl: 'https://x.test/wp-admin/admin.php?page=swg-galleries' });
    for (const source of [undefined, null, {}, { swgBlock: null }, { swgBlock: {} }]) {
      expect(readConfig(source).galleriesUrl).toBe(FALLBACK_GALLERIES_URL);
    }
    expect(readConfig({ swgBlock: { galleriesUrl: 42 } }).galleriesUrl).toBe(
      FALLBACK_GALLERIES_URL,
    );
    expect(readConfig({ swgBlock: { galleriesUrl: '' } }).galleriesUrl).toBe(
      FALLBACK_GALLERIES_URL,
    );
    expect(readConfig({ swgBlock: { galleriesUrl: 'a#b' } }).galleriesUrl).toBe(
      FALLBACK_GALLERIES_URL,
    );
  });

  it('builds the deep links of the admin app', () => {
    const base = 'admin.php?page=swg-galleries';
    expect(galleryListUrl(base)).toBe('admin.php?page=swg-galleries#/galleries');
    expect(editGalleryUrl(base, 42)).toBe('admin.php?page=swg-galleries#/galleries/42');
    for (const bad of [0, -1, 1.5, NaN]) expect(editGalleryUrl(base, bad)).toBeNull();
  });
});

describe('api', () => {
  it('builds the list request with search, page size and statuses', () => {
    const url = new URL(galleriesPath('  sum mer '), 'https://x.test');
    expect(url.pathname).toBe('/swg/v1/galleries');
    expect(url.searchParams.get('search')).toBe('sum mer');
    expect(url.searchParams.get('per_page')).toBe('20');
    expect(url.searchParams.get('status')).toBe('draft,pending,publish,private');
    expect(
      new URL(galleriesPath('x'.repeat(500)), 'https://x.test').searchParams.get('search'),
    ).toHaveLength(200);
  });

  it('parses galleries defensively', () => {
    expect(parseGallery({ id: 3, title: 'A', status: 'publish', extra: 1 })).toEqual({
      id: 3,
      title: 'A',
      status: 'publish',
    });
    expect(parseGallery({ id: 3 })).toEqual({ id: 3, title: '', status: 'draft' });
    for (const bad of [null, 'x', [], { id: 0 }, { id: '3' }, { id: 1.5 }]) {
      expect(parseGallery(bad)).toBeNull();
    }
    expect(parseGalleries([{ id: 1 }, { nope: true }, null])).toHaveLength(1);
    expect(() => parseGalleries({ not: 'a list' })).toThrow();
  });

  it('parses layouts defensively', () => {
    expect(
      parseLayouts({ layouts: [{ id: 'grid', label: 'Grid' }, { id: 'x' }, { label: 'no id' }] }),
    ).toEqual([
      { id: 'grid', label: 'Grid' },
      { id: 'x', label: 'x' },
    ]);
    expect(() => parseLayouts(null)).toThrow();
    expect(() => parseLayouts({ layouts: 'no' })).toThrow();
  });

  it('lists through the injected fetcher and through api-fetch by default', async () => {
    const fetcher = vi.fn().mockResolvedValue([{ id: 2, title: 'B', status: 'publish' }]);
    await expect(listGalleries('b', fetcher)).resolves.toEqual([
      { id: 2, title: 'B', status: 'publish' },
    ]);
    expect(fetcher).toHaveBeenCalledWith(
      expect.objectContaining({ path: expect.stringContaining('search=b') }),
    );

    vi.mocked(apiFetch).mockResolvedValueOnce({ layouts: [{ id: 'grid', label: 'Grid' }] });
    await expect(listLayouts()).resolves.toEqual([{ id: 'grid', label: 'Grid' }]);
    expect(apiFetch).toHaveBeenCalledWith({ path: '/swg/v1/layouts', signal: undefined });
  });

  it('returns null for a gallery that is gone and rethrows other failures', async () => {
    await expect(
      getGallery(5, () => Promise.resolve({ id: 5, title: 'T', status: 'publish' })),
    ).resolves.toEqual({
      id: 5,
      title: 'T',
      status: 'publish',
    });
    await expect(
      getGallery(5, () => Promise.reject({ code: 'swg_gallery_not_found' })),
    ).resolves.toBeNull();
    await expect(
      getGallery(5, () => Promise.reject({ data: { status: 404 } })),
    ).resolves.toBeNull();
    await expect(getGallery(5, () => Promise.reject({ code: 'rest_forbidden' }))).rejects.toEqual({
      code: 'rest_forbidden',
    });
  });

  it('detects aborted requests', () => {
    expect(isAbort({ name: 'AbortError' })).toBe(true);
    expect(isAbort({ code: 'abort_error' })).toBe(true);
    expect(isAbort(new Error('x'))).toBe(false);
    expect(isAbort(null)).toBe(false);
  });

  it('describes failures honestly without server details', () => {
    expect(describeError({ code: 'rest_cookie_invalid_nonce' }, 'galleries')).toMatch(
      /session has expired/,
    );
    expect(describeError({ data: { status: 403 }, message: '/var/www/secret' }, 'galleries')).toBe(
      'You are not allowed to list galleries.',
    );
    expect(describeError({ code: 'rest_forbidden' }, 'layouts')).toBe(
      'You are not allowed to list layouts.',
    );
    expect(describeError({ data: { status: 401 } }, 'layouts')).toBe(
      'You are not allowed to list layouts.',
    );
    expect(describeError({ code: 'fetch_error' }, 'galleries')).toMatch(/could not be reached/);
    expect(describeError({ code: 'offline' }, 'layouts')).toMatch(/could not be reached/);
    expect(describeError(new Error('SQL syntax near /etc/passwd'), 'galleries')).toBe(
      'The galleries could not be loaded.',
    );
    expect(describeError('boom', 'layouts')).toBe('The layouts could not be loaded.');
  });
});
