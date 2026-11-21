import type { Gallery, GalleryStatus, HoverConfig } from '@/api/galleries';
import type { GalleryItem, GlobalTexts, ItemTexts } from '@/api/items';
import { MAX_BULK } from '@/api/items';

/*
 * Staged editing model of the gallery editor (pure functions, no Vue, no I/O).
 *
 *   base     what the server last confirmed (gallery, items, order, revision)
 *   working  the user's local copy: details, the order of the REMAINING items and text overrides
 *
 * A removal is "staged" by dropping the id from `working.order`; reordering permutes it; text
 * overrides live in `working.overrides` (only for edited items). Everything is applied by Save in
 * the order given by `buildPlan` (remove -> overrides -> order -> details, publishing last), see
 * `useGalleryEditor.ts` and apps/admin/README.md. Adding images is NOT staged (the server assigns
 * item ids), it goes through the API immediately.
 */

export const DETAIL_KEYS = [
  'title',
  'description',
  'status',
  'coverAttachmentId',
  'layout',
  'sortMode',
  'perPage',
  'hover',
  'settings',
] as const;
export type DetailKey = (typeof DETAIL_KEYS)[number];

export interface Details {
  title: string;
  description: string;
  status: GalleryStatus;
  coverAttachmentId: number | null;
  layout: string;
  sortMode: string;
  /** Items per page of this gallery; null uses the site-wide default. */
  perPage: number | null;
  /** Hover texts of this gallery; null uses the site-wide setting. */
  hover: HoverConfig | null;
  settings: Record<string, unknown>;
}

export interface Base {
  gallery: Gallery;
  items: ReadonlyMap<number, GalleryItem>;
  /** Server order (stored manual order) of all item ids. */
  order: readonly number[];
  /** Latest revision we know, advanced by every confirmed write (chained). */
  revision: number;
}

export interface Working {
  details: Details;
  /** Remaining item ids in the order the user wants. */
  order: readonly number[];
  /** Staged text overrides, only for items the user touched. */
  overrides: Readonly<Record<number, ItemTexts>>;
  /** Staged complete item settings maps (layout `x-item-settings`), only for items the user touched. */
  itemSettings?: Readonly<Record<number, Readonly<Record<string, unknown>>>>;
}

export function detailsOf(gallery: Gallery): Details {
  return {
    title: gallery.title,
    description: gallery.description,
    status: gallery.status,
    coverAttachmentId: gallery.coverAttachmentId,
    layout: gallery.layout,
    sortMode: gallery.sortMode,
    perPage: gallery.perPage,
    hover: gallery.hover,
    settings: gallery.settings,
  };
}

export function initialWorking(base: Base): Working {
  return { details: detailsOf(base.gallery), order: [...base.order], overrides: {} };
}

/** Structural equality for JSON-like values (key order of objects is irrelevant). */
export function deepEqual(a: unknown, b: unknown): boolean {
  if (a === b) return true;
  if (typeof a !== 'object' || typeof b !== 'object' || a === null || b === null) return false;
  if (Array.isArray(a) !== Array.isArray(b)) return false;
  if (Array.isArray(a) && Array.isArray(b)) {
    return a.length === b.length && a.every((value, index) => deepEqual(value, b[index]));
  }
  const left = a as Record<string, unknown>;
  const right = b as Record<string, unknown>;
  const keys = Object.keys(left);
  return (
    keys.length === Object.keys(right).length &&
    keys.every((key) => key in right && deepEqual(left[key], right[key]))
  );
}

export function changedDetailKeys(base: Details, work: Details): DetailKey[] {
  return DETAIL_KEYS.filter((key) => !deepEqual(base[key], work[key]));
}

export function removedIds(base: Base, work: Working): number[] {
  const keep = new Set(work.order);
  return base.order.filter((id) => !keep.has(id));
}

/** Staged override entries that differ from the stored ones, for items that remain. */
export function changedOverrideIds(base: Base, work: Working): number[] {
  const out: number[] = [];
  for (const id of work.order) {
    const staged = work.overrides[id];
    const stored = base.items.get(id);
    if (staged !== undefined && stored !== undefined && !deepEqual(staged, stored.overrides)) {
      out.push(id);
    }
  }
  return out;
}

/** Items whose staged settings map differs from the stored one (items that remain). */
export function changedItemSettingsIds(base: Base, work: Working): number[] {
  const out: number[] = [];
  for (const id of work.order) {
    const staged = work.itemSettings?.[id];
    const stored = base.items.get(id);
    if (staged !== undefined && stored !== undefined && !deepEqual(staged, stored.settings)) {
      out.push(id);
    }
  }
  return out;
}

/** The order differs from the server's, ignoring items that are staged for removal. */
export function orderChanged(base: Base, work: Working): boolean {
  const keep = new Set(work.order);
  const stored = base.order.filter((id) => keep.has(id));
  return (
    stored.length !== work.order.length || stored.some((id, index) => work.order[index] !== id)
  );
}

/** Number shown as "n unsaved changes": each detail field, removed item, edited item and the order count once. */
export function countChanges(base: Base, work: Working): number {
  return (
    changedDetailKeys(detailsOf(base.gallery), work.details).length +
    removedIds(base, work).length +
    changedOverrideIds(base, work).length +
    changedItemSettingsIds(base, work).length +
    (orderChanged(base, work) ? 1 : 0)
  );
}

export type SaveStep =
  | { kind: 'remove'; itemIds: number[] }
  | { kind: 'override'; itemId: number; overrides: Partial<ItemTexts> }
  | { kind: 'itemSettings'; itemId: number; settings: Record<string, unknown> }
  | { kind: 'order'; itemIds: number[] }
  | { kind: 'details'; changes: Partial<Details> };

/**
 * Defined save order: removals first (so the order list is an exact permutation), then text
 * overrides and item settings, then the order with the complete remaining list, finally the gallery details, so a
 * publish never happens before the rest was saved.
 */
export function buildPlan(base: Base, work: Working): SaveStep[] {
  const plan: SaveStep[] = [];
  const removed = removedIds(base, work);
  for (let offset = 0; offset < removed.length; offset += MAX_BULK) {
    plan.push({ kind: 'remove', itemIds: removed.slice(offset, offset + MAX_BULK) });
  }
  for (const itemId of changedOverrideIds(base, work)) {
    const staged = work.overrides[itemId];
    const stored = base.items.get(itemId);
    if (staged === undefined || stored === undefined) continue;
    const overrides: Partial<ItemTexts> = {};
    for (const field of ['title', 'caption', 'alt'] as const) {
      if (staged[field] !== stored.overrides[field]) overrides[field] = staged[field];
    }
    plan.push({ kind: 'override', itemId, overrides });
  }
  for (const itemId of changedItemSettingsIds(base, work)) {
    const staged = work.itemSettings?.[itemId];
    if (staged !== undefined) plan.push({ kind: 'itemSettings', itemId, settings: { ...staged } });
  }
  if (orderChanged(base, work)) plan.push({ kind: 'order', itemIds: [...work.order] });
  const keys = changedDetailKeys(detailsOf(base.gallery), work.details);
  if (keys.length > 0) {
    const changes: Record<string, unknown> = {};
    for (const key of keys) changes[key] = work.details[key];
    plan.push({ kind: 'details', changes: changes as Partial<Details> });
  }
  return plan;
}

/** Effective texts: staged or stored overrides on top of the global attachment texts. */
export function effectiveTexts(item: GalleryItem, overrides: ItemTexts): GlobalTexts {
  const global = item.global ?? { title: '', caption: '', alt: '' };
  return {
    title: overrides.title ?? global.title,
    caption: overrides.caption ?? global.caption,
    alt: overrides.alt ?? global.alt,
  };
}
