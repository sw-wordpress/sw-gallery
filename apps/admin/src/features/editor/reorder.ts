export type MoveMode = 'earlier' | 'later' | 'start' | 'end';

/**
 * Moves the selected ids within `order` (pure). The relative order of the selection is kept;
 * `earlier`/`later` swap each selected block with its unselected neighbour, `start`/`end` gather
 * the selection at the edge. Returns the same array instance when nothing would change.
 */
export function moveIds(
  order: readonly number[],
  selected: ReadonlySet<number>,
  mode: MoveMode,
): readonly number[] {
  if (selected.size === 0) return order;
  const picked = order.filter((id) => selected.has(id));
  if (picked.length === 0) return order;
  let next: number[];
  if (mode === 'start' || mode === 'end') {
    const rest = order.filter((id) => !selected.has(id));
    next = mode === 'start' ? [...picked, ...rest] : [...rest, ...picked];
  } else {
    next = [...order];
    if (mode === 'earlier') {
      for (let i = 1; i < next.length; i += 1) {
        const id = next[i];
        const previous = next[i - 1];
        if (
          id !== undefined &&
          previous !== undefined &&
          selected.has(id) &&
          !selected.has(previous)
        ) {
          next[i - 1] = id;
          next[i] = previous;
        }
      }
    } else {
      for (let i = next.length - 2; i >= 0; i -= 1) {
        const id = next[i];
        const following = next[i + 1];
        if (
          id !== undefined &&
          following !== undefined &&
          selected.has(id) &&
          !selected.has(following)
        ) {
          next[i + 1] = id;
          next[i] = following;
        }
      }
    }
  }
  return next.every((id, index) => id === order[index]) ? order : next;
}

/**
 * Drag and drop: puts the dragged ids (in their current relative order) before the item at
 * `targetIndex` of the CURRENT order (`order.length` = at the end).
 */
export function moveIdsTo(
  order: readonly number[],
  dragged: ReadonlySet<number>,
  targetIndex: number,
): readonly number[] {
  const picked = order.filter((id) => dragged.has(id));
  if (picked.length === 0) return order;
  const clamped = Math.min(Math.max(targetIndex, 0), order.length);
  const before = order.slice(0, clamped).filter((id) => !dragged.has(id));
  const after = order.slice(clamped).filter((id) => !dragged.has(id));
  const next = [...before, ...picked, ...after];
  return next.every((id, index) => id === order[index]) ? order : next;
}
