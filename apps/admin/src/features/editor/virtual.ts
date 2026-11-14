/** Windowing math of the items grid (pure; the component only measures and renders). */

/** Thumbnail size choices of the items grid (FU-31) and their minimum tile width in px. */
export const TILE_SIZES = ['small', 'medium', 'large'] as const;
export type TileSize = (typeof TILE_SIZES)[number];
export const DEFAULT_TILE_SIZE: TileSize = 'medium';
export const TILE_MIN_WIDTH: Record<TileSize, number> = { small: 110, medium: 150, large: 240 };

export function isTileSize(value: unknown): value is TileSize {
  return typeof value === 'string' && (TILE_SIZES as readonly string[]).includes(value);
}

export interface WindowInput {
  count: number;
  /** Inner width of the scroller in px. */
  width: number;
  /** Height of the visible area in px. */
  height: number;
  scrollTop: number;
  minTile: number;
  gap: number;
  /** Height of the caption below the square thumbnail. */
  labelHeight: number;
  /** Extra rows rendered above and below the visible ones. */
  overscanRows?: number;
}

export interface WindowResult {
  columns: number;
  tileWidth: number;
  rowHeight: number;
  rows: number;
  /** Index range [start, end) of the items to render. */
  start: number;
  end: number;
  padTop: number;
  padBottom: number;
}

export function computeWindow(input: WindowInput): WindowResult {
  const { count, width, height, scrollTop, minTile, gap, labelHeight } = input;
  const overscan = input.overscanRows ?? 2;
  const columns = Math.max(1, Math.floor((Math.max(width, 1) + gap) / (minTile + gap)));
  const tileWidth = Math.max(1, (Math.max(width, 1) - gap * (columns - 1)) / columns);
  const rowHeight = Math.round(tileWidth + labelHeight + gap);
  const rows = Math.ceil(count / columns);
  const firstVisible = Math.floor(Math.max(scrollTop, 0) / rowHeight);
  const lastVisible = Math.ceil((Math.max(scrollTop, 0) + Math.max(height, 1)) / rowHeight);
  const startRow = Math.min(Math.max(firstVisible - overscan, 0), Math.max(rows - 1, 0));
  const endRow = Math.min(lastVisible + overscan, rows);
  return {
    columns,
    tileWidth,
    rowHeight,
    rows,
    start: Math.min(startRow * columns, count),
    end: Math.min(endRow * columns, count),
    padTop: startRow * rowHeight,
    padBottom: Math.max(rows - endRow, 0) * rowHeight,
  };
}

/** Scroll position that brings row `row` fully into view (or the current one if it already is). */
export function scrollTopForRow(
  row: number,
  rowHeight: number,
  height: number,
  scrollTop: number,
): number {
  const top = row * rowHeight;
  const bottom = top + rowHeight;
  if (top < scrollTop) return top;
  if (bottom > scrollTop + height) return bottom - height;
  return scrollTop;
}
