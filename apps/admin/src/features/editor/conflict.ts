import type { Gallery } from '@/api/galleries';
import type { GalleryItem } from '@/api/items';
import { changedDetailKeys, changedOverrideIds, deepEqual, detailsOf, orderChanged } from './model';
import type { Base, DetailKey, Details, Working } from './model';

/** The server state fetched after a conflict or failure. */
export interface FreshState {
  gallery: Gallery;
  items: GalleryItem[];
  /** Revision of the items snapshot (equals `gallery.revision` for a consistent load). */
  revision: number;
}

export function baseFromFresh(fresh: FreshState): Base {
  return {
    gallery: fresh.gallery,
    items: new Map(fresh.items.map((item) => [item.id, item])),
    order: fresh.items.map((item) => item.id),
    revision: fresh.revision,
  };
}

/** What changed on the server compared with our base (by someone else). */
export interface ServerDiff {
  detailKeys: DetailKey[];
  itemsAdded: number;
  itemsRemoved: number;
  /** Items still present whose position within the common items differs. */
  itemsMoved: number;
  overridesChanged: number;
}

export function diffServer(base: Base, fresh: Base): ServerDiff {
  const baseIds = new Set(base.order);
  const freshIds = new Set(fresh.order);
  const commonBase = base.order.filter((id) => freshIds.has(id));
  const commonFresh = fresh.order.filter((id) => baseIds.has(id));
  let moved = 0;
  commonBase.forEach((id, index) => {
    if (commonFresh[index] !== id) moved += 1;
  });
  let overridesChanged = 0;
  for (const id of commonBase) {
    const before = base.items.get(id);
    const after = fresh.items.get(id);
    if (
      before !== undefined &&
      after !== undefined &&
      !deepEqual(before.overrides, after.overrides)
    ) {
      overridesChanged += 1;
    }
  }
  return {
    detailKeys: changedDetailKeys(detailsOf(base.gallery), detailsOf(fresh.gallery)),
    itemsAdded: fresh.order.filter((id) => !baseIds.has(id)).length,
    itemsRemoved: base.order.filter((id) => !freshIds.has(id)).length,
    itemsMoved: moved,
    overridesChanged,
  };
}

export interface RebaseNotices {
  /** Staged order change could not be applied to the new server order. */
  droppedOrder: boolean;
  /** Staged text edits of items that no longer exist. */
  droppedOverrides: number;
  /** Detail fields where the user's staged value replaces a different server change. */
  overwrittenDetails: DetailKey[];
  /** Items whose staged texts replace texts changed elsewhere. */
  overwrittenOverrides: number;
}

export interface Rebased {
  base: Base;
  working: Working;
  notices: RebaseNotices;
}

/**
 * Re-applies the staged changes onto the fresh server state where that is unambiguous:
 *  - details/text edits are never dropped silently: the staged value wins and a differing
 *    server change is reported in `notices`;
 *  - removals of items that still exist stay staged; of vanished ones they are moot;
 *  - text edits of vanished items are dropped and counted;
 *  - the staged order survives only if nobody else moved items (additions/removals by others
 *    are merged in: new items go to the end), otherwise it is dropped and reported.
 */
export function rebase(base: Base, work: Working, freshState: FreshState): Rebased {
  const fresh = baseFromFresh(freshState);
  const notices: RebaseNotices = {
    droppedOrder: false,
    droppedOverrides: 0,
    overwrittenDetails: [],
    overwrittenOverrides: 0,
  };

  // details
  const baseDetails = detailsOf(base.gallery);
  const freshDetails = detailsOf(fresh.gallery);
  const details: Details = { ...freshDetails };
  for (const key of changedDetailKeys(baseDetails, work.details)) {
    (details as unknown as Record<DetailKey, unknown>)[key] = work.details[key];
    if (
      !deepEqual(freshDetails[key], baseDetails[key]) &&
      !deepEqual(freshDetails[key], work.details[key])
    ) {
      notices.overwrittenDetails.push(key);
    }
  }

  // items removed by the user and still on the server stay removed
  const baseIds = new Set(base.order);
  const freshIds = new Set(fresh.order);
  const keep = new Set(work.order);
  const stagedRemovals = new Set(base.order.filter((id) => !keep.has(id) && freshIds.has(id)));

  // order
  let order: number[];
  if (!orderChanged(base, work)) {
    order = fresh.order.filter((id) => !stagedRemovals.has(id));
  } else {
    const commonBase = base.order.filter((id) => freshIds.has(id));
    const commonFresh = fresh.order.filter((id) => baseIds.has(id));
    const othersMoved = commonBase.some((id, index) => commonFresh[index] !== id);
    if (othersMoved) {
      notices.droppedOrder = true;
      order = fresh.order.filter((id) => !stagedRemovals.has(id));
    } else {
      order = [
        ...work.order.filter((id) => freshIds.has(id)),
        ...fresh.order.filter((id) => !baseIds.has(id) && !stagedRemovals.has(id)),
      ];
    }
  }

  // text overrides
  const overrides: Record<number, Working['overrides'][number]> = {};
  for (const id of changedOverrideIds(base, work)) {
    const staged = work.overrides[id];
    if (staged === undefined) continue;
    const stored = fresh.items.get(id);
    if (stored === undefined) {
      notices.droppedOverrides += 1;
      continue;
    }
    const before = base.items.get(id);
    if (
      before !== undefined &&
      !deepEqual(stored.overrides, before.overrides) &&
      !deepEqual(stored.overrides, staged)
    ) {
      notices.overwrittenOverrides += 1;
    }
    overrides[id] = staged;
  }

  return { base: fresh, working: { details, order, overrides }, notices };
}
