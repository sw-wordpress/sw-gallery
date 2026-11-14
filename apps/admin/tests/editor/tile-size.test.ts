import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { Quasar } from 'quasar';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import TileSizeControl from '../../src/features/editor/components/TileSizeControl.vue';
import {
  DEFAULT_TILE_SIZE,
  TILE_MIN_WIDTH,
  TILE_SIZES,
  computeWindow,
  isTileSize,
} from '../../src/features/editor/virtual';
import { createAppI18n } from '../../src/i18n';
import { TILE_SIZE_KEY, useAppStore } from '../../src/stores/app';

const base = { gap: 12, labelHeight: 26, height: 800, scrollTop: 0 };

describe('virtualiser maths per tile size (FU-31)', () => {
  const widths = [320, 760, 1200, 1904, 2544];

  it('chooses columns from the minimum tile width and lets the tiles fill the row exactly', () => {
    for (const size of TILE_SIZES) {
      const min = TILE_MIN_WIDTH[size];
      for (const width of widths) {
        const w = computeWindow({ ...base, count: 500, width, minTile: min });
        expect(w.tileWidth, `${size}@${width}`).toBeGreaterThanOrEqual(Math.min(min, width));
        expect(w.columns * w.tileWidth + (w.columns - 1) * base.gap).toBeCloseTo(width, 6);
        // one more column would push the tile under the minimum
        const more = (width - base.gap * w.columns) / (w.columns + 1);
        expect(more < min || width < min).toBe(true);
      }
    }
  });

  it('shows more, smaller tiles for "small" and fewer, larger ones for "large" at every width', () => {
    for (const width of widths) {
      const cols = TILE_SIZES.map(
        (s) => computeWindow({ ...base, count: 500, width, minTile: TILE_MIN_WIDTH[s] }).columns,
      );
      expect(cols[0]).toBeGreaterThanOrEqual(cols[1] as number);
      expect(cols[1]).toBeGreaterThanOrEqual(cols[2] as number);
    }
    const wide = (s: keyof typeof TILE_MIN_WIDTH) =>
      computeWindow({ ...base, count: 500, width: 2544, minTile: TILE_MIN_WIDTH[s] });
    expect(wide('small').columns).toBe(20);
    expect(wide('medium').columns).toBe(15);
    expect(wide('large').columns).toBe(10);
    expect(wide('large').tileWidth).toBeGreaterThan(wide('small').tileWidth);
  });

  it('keeps the scroll maths exact for every size: padding plus rendered rows is the full height', () => {
    for (const size of TILE_SIZES) {
      for (const scrollTop of [0, 1234, 98765, 1e9]) {
        const w = computeWindow({
          ...base,
          count: 9999,
          width: 1904,
          scrollTop,
          minTile: TILE_MIN_WIDTH[size],
        });
        const rendered = Math.ceil((w.end - w.start) / w.columns) * w.rowHeight;
        const total = w.padTop + rendered + w.padBottom;
        expect(total, `${size}@${scrollTop}`).toBe(w.rows * w.rowHeight);
        expect(w.start % w.columns).toBe(0);
      }
    }
  });

  it('knows the valid sizes', () => {
    expect(DEFAULT_TILE_SIZE).toBe('medium');
    expect(isTileSize('large')).toBe(true);
    expect(isTileSize('huge')).toBe(false);
    expect(isTileSize(3)).toBe(false);
  });
});

describe('tile size preference and control', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    window.localStorage.clear();
  });
  afterEach(() => {
    vi.restoreAllMocks();
    window.localStorage.clear();
  });

  it('defaults to medium, persists a choice per viewer and drops the key for the default', () => {
    const app = useAppStore();
    expect(app.tileSize).toBe('medium');
    app.setTileSize('large');
    expect(window.localStorage.getItem(TILE_SIZE_KEY)).toBe('large');
    setActivePinia(createPinia());
    expect(useAppStore().tileSize).toBe('large');
    useAppStore().setTileSize('medium');
    expect(window.localStorage.getItem(TILE_SIZE_KEY)).toBeNull();
  });

  it('ignores a corrupt stored value and survives unusable storage', () => {
    window.localStorage.setItem(TILE_SIZE_KEY, 'gigantic');
    expect(useAppStore().tileSize).toBe('medium');
    setActivePinia(createPinia());
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    const app = useAppStore();
    expect(app.tileSize).toBe('medium');
    expect(() => app.setTileSize('small')).not.toThrow();
    expect(app.tileSize).toBe('small'); // works for the session, just not persisted
  });

  it('is a labelled group of real buttons that reflect and change the size, with a live announcement', async () => {
    const wrapper = mount(TileSizeControl, {
      global: { plugins: [createAppI18n('en_US'), [Quasar, {}]] },
      attachTo: document.body,
    });
    const group = wrapper.get('[role="group"]');
    expect(group.attributes('aria-label')).toBe('Thumbnail size');
    const buttons = wrapper.findAll('button');
    expect(buttons.map((b) => b.attributes('aria-label'))).toEqual([
      'Small thumbnails',
      'Medium thumbnails',
      'Large thumbnails',
    ]);
    expect(buttons.map((b) => b.attributes('aria-pressed'))).toEqual(['false', 'true', 'false']);
    buttons.forEach((b) => expect(b.attributes('type')).toBe('button')); // natively focusable, Enter/Space click
    const app = useAppStore();
    await buttons[2]?.trigger('click');
    expect(app.tileSize).toBe('large');
    expect(wrapper.findAll('button').map((b) => b.attributes('aria-pressed'))).toEqual([
      'false',
      'false',
      'true',
    ]);
    await new Promise((r) => setTimeout(r, 0));
    expect(app.announcement).toBe('Large thumbnails');
    await buttons[2]?.trigger('click'); // no change, no new write
    wrapper.unmount();
  });
});
