/**
 * Block attributes: the gallery id plus four optional presentation overrides. Nothing else is ever stored
 * (no media ids, CODE-STYLE §8). The limits mirror block.json and `Block/BlockAttributes.php`, which validates
 * again on the server: this module only keeps the editor from storing values the server would drop.
 */
export const BLOCK_NAME = 'sw-gallery/gallery';

export const LIMITS = {
  columns: { min: 1, max: 12 },
  gap: { min: 0, max: 64 },
} as const;

/** Same pattern as `GallerySettings::LAYOUT_ID_PATTERN` in the core. */
const LAYOUT_ID_PATTERN = /^[a-z][a-z0-9_-]{0,63}$/;

export type BlockAttributes = {
  galleryId: number;
  layout?: string;
  columns?: number;
  gap?: number;
  eager?: boolean;
};

/** A whole number within the inclusive range, otherwise undefined (never coerced or clamped silently). */
export function integerInRange(value: unknown, min: number, max: number): number | undefined {
  if (typeof value !== 'number' || !Number.isInteger(value) || value < min || value > max) {
    return undefined;
  }
  return value;
}

export function isLayoutId(value: unknown): value is string {
  return typeof value === 'string' && LAYOUT_ID_PATTERN.test(value);
}

/**
 * The attributes the server will accept, with invalid or default values removed: a positive gallery id, a
 * well-formed layout id, columns and gap in range and `eager` only when it deviates from the default (true).
 */
export function sanitizeAttributes(raw: Partial<BlockAttributes>): BlockAttributes {
  const clean: BlockAttributes = {
    galleryId: integerInRange(raw.galleryId, 1, 2147483647) ?? 0,
  };
  if (isLayoutId(raw.layout)) clean.layout = raw.layout;
  const columns = integerInRange(raw.columns, LIMITS.columns.min, LIMITS.columns.max);
  if (columns !== undefined) clean.columns = columns;
  const gap = integerInRange(raw.gap, LIMITS.gap.min, LIMITS.gap.max);
  if (gap !== undefined) clean.gap = gap;
  if (raw.eager === false) clean.eager = false;
  return clean;
}

/** Render options of the server renderer for the attributes (same mapping as `BlockAttributes::options()`). */
export interface RenderOptions {
  layout?: string;
  settings?: { columns?: number; gap?: number };
  eager?: boolean;
}

export function toRenderOptions(raw: Partial<BlockAttributes>): RenderOptions {
  const clean = sanitizeAttributes(raw);
  const options: RenderOptions = {};
  if (clean.layout !== undefined) options.layout = clean.layout;
  const settings: { columns?: number; gap?: number } = {};
  if (clean.columns !== undefined) settings.columns = clean.columns;
  if (clean.gap !== undefined) settings.gap = clean.gap;
  if (Object.keys(settings).length > 0) options.settings = settings;
  if (clean.eager === false) options.eager = false;
  return options;
}
