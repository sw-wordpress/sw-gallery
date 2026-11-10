import { afterEach, describe, expect, it, vi } from 'vitest';
import { DEFAULT_LABELS, formatCounter, parseLabels } from '../src/labels.ts';
import { isRtl, readItems } from '../src/items.ts';
import { galleryHtml } from './helpers.ts';

afterEach(() => {
  document.body.innerHTML = '';
  vi.resetModules();
  vi.restoreAllMocks();
});

describe('entry point', () => {
  it('registers window.swgLightbox and initializes immediately when the DOM is ready', async () => {
    document.body.innerHTML = galleryHtml();
    await import('../src/main.ts');
    expect(typeof window.swgLightbox?.init).toBe('function');
    expect(
      document.querySelector('[data-swg-gallery]')!.hasAttribute('data-swg-lightbox-ready'),
    ).toBe(true);
  });

  it('waits for DOMContentLoaded while the document is loading', async () => {
    document.body.innerHTML = galleryHtml();
    vi.spyOn(document, 'readyState', 'get').mockReturnValue('loading');
    await import('../src/main.ts');
    const el = document.querySelector('[data-swg-gallery]')!;
    expect(el.hasAttribute('data-swg-lightbox-ready')).toBe(false);
    document.dispatchEvent(new Event('DOMContentLoaded'));
    expect(el.hasAttribute('data-swg-lightbox-ready')).toBe(true);
  });
});

describe('labels', () => {
  it('returns defaults for empty input and ignores unknown keys', () => {
    expect(parseLabels(undefined)).toEqual(DEFAULT_LABELS);
    expect(parseLabels('')).toEqual(DEFAULT_LABELS);
    expect(parseLabels('{"unknown":"x","close":"Zu"}').close).toBe('Zu');
    expect(parseLabels('{"unknown":"x"}')).toEqual(DEFAULT_LABELS);
  });

  it('formats counter placeholders without interpreting $ patterns', () => {
    expect(formatCounter('{current} of {total} ({current})', 2, 9)).toBe('2 of 9 (2)');
    expect(formatCounter('$& {current}', 1, 2)).toBe('$& 1');
  });
});

describe('items', () => {
  it('reads dimensions, alt and captions', () => {
    document.body.innerHTML = galleryHtml({ captions: { 1: ' Hello ' } });
    const items = readItems(document.body);
    expect(items).toHaveLength(3);
    expect(items[0]).toMatchObject({ width: 2000, height: 1333, alt: 'Alt 1', caption: 'Hello' });
  });

  it('detects RTL from the nearest dir attribute', () => {
    document.body.innerHTML =
      '<div dir="rtl"><p id="a"></p></div><div dir="ltr"><p id="b"></p></div>';
    expect(isRtl(document.getElementById('a')!)).toBe(true);
    expect(isRtl(document.getElementById('b')!)).toBe(false);
  });
});
