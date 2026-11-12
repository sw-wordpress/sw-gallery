import { computed, ref } from 'vue';
import type { Ref } from 'vue';

/** Selection of items by id with anchor (shift ranges) and the keyboard "active" index. Order comes from the editor. */
export function useItemSelection(order: Ref<readonly number[]>) {
  const selected = ref<ReadonlySet<number>>(new Set());
  const anchor = ref<number | null>(null);
  const activeIndex = ref(0);

  const count = computed(() => selected.value.size);
  const activeId = computed(() => order.value[activeIndex.value] ?? null);
  /** The selection, or the active item when nothing is selected (what toolbar actions act on). */
  const targets = computed<ReadonlySet<number>>(() => {
    if (selected.value.size > 0) return selected.value;
    return activeId.value === null ? new Set<number>() : new Set([activeId.value]);
  });

  function isSelected(id: number): boolean {
    return selected.value.has(id);
  }

  function setActive(index: number): void {
    const max = Math.max(order.value.length - 1, 0);
    activeIndex.value = Math.min(Math.max(index, 0), max);
  }

  function toggle(index: number): void {
    const id = order.value[index];
    if (id === undefined) return;
    const next = new Set(selected.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    selected.value = next;
    anchor.value = index;
    setActive(index);
  }

  /** Selects the range between the anchor and `index` (adds to the current selection). */
  function range(index: number): void {
    const from = anchor.value ?? activeIndex.value;
    const [low, high] = from <= index ? [from, index] : [index, from];
    const next = new Set(selected.value);
    for (let i = low; i <= high; i += 1) {
      const id = order.value[i];
      if (id !== undefined) next.add(id);
    }
    selected.value = next;
    setActive(index);
  }

  function selectAll(): void {
    selected.value = new Set(order.value);
  }

  function clear(): void {
    selected.value = new Set();
    anchor.value = null;
  }

  /** Forget ids that are no longer part of the list (removed, reloaded). */
  function prune(): void {
    const present = new Set(order.value);
    const next = new Set([...selected.value].filter((id) => present.has(id)));
    if (next.size !== selected.value.size) selected.value = next;
    setActive(activeIndex.value);
  }

  return {
    selected,
    anchor,
    activeIndex,
    activeId,
    count,
    targets,
    isSelected,
    setActive,
    toggle,
    range,
    selectAll,
    clear,
    prune,
  };
}

export type ItemSelection = ReturnType<typeof useItemSelection>;
