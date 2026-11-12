<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAppStore } from '@/stores/app';
import { uid } from '@/utils/ids';
import type { MoveMode } from '../reorder';
import type { ItemSelection } from '../useItemSelection';
import type { EditorSession, ItemView } from '../useGalleryEditor';
import { computeWindow, scrollTopForRow } from '../virtual';
import ItemTile from './ItemTile.vue';

/**
 * Windowed, keyboard-operable items grid (M2-25) with reordering (M2-26).
 *
 * Pattern: one focusable `listbox` (aria-multiselectable) with `aria-activedescendant`; only the
 * tiles in the visible window (+ overscan rows) are in the DOM, `aria-posinset`/`aria-setsize`
 * tell assistive technology the real position. Click toggles, Shift+click selects a range,
 * arrows move the active tile, Space toggles, Ctrl/Cmd+A selects all, Enter opens the details,
 * Delete asks for removal, Ctrl/Cmd+arrows (+Home/End) reorder. Mouse users can also drag tiles
 * (native HTML5 drag and drop, no dependency); touch and assistive technology use the keyboard
 * keys or the move buttons of the toolbar.
 */
const props = defineProps<{
  session: EditorSession;
  selection: ItemSelection;
  coverAttachmentId: number | null;
  readonly: boolean;
}>();
const emit = defineEmits<{
  (e: 'open-details', itemId: number): void;
  (e: 'remove-request'): void;
}>();

const { t } = useI18n();
const app = useAppStore();
const base = uid('swg-grid');
const helpId = `${base}-help`;

const MIN_TILE = 150;
const GAP = 12;
const LABEL_HEIGHT = 26;
const PAD = 8;

const scroller = ref<HTMLElement | null>(null);
const width = ref(0);
const height = ref(0);
const scrollTop = ref(0);
const focused = ref(false);

const order = computed(() => props.session.order.value);
const win = computed(() =>
  computeWindow({
    count: order.value.length,
    width: (width.value || 900) - PAD * 2,
    height: height.value || 600,
    scrollTop: scrollTop.value,
    minTile: MIN_TILE,
    gap: GAP,
    labelHeight: LABEL_HEIGHT,
  }),
);
const visible = computed(() => {
  const out: Array<{ index: number; id: number; view: ItemView }> = [];
  for (let index = win.value.start; index < win.value.end; index += 1) {
    const view = viewAt(index);
    if (view !== null) out.push({ index, id: view.item.id, view });
  }
  return out;
});
const canReorder = computed(() => props.session.reorderEnabled.value && !props.readonly);

function measure(): void {
  const el = scroller.value;
  if (el === null) return;
  width.value = el.clientWidth;
  height.value = el.clientHeight;
}
let observer: ResizeObserver | null = null;
onMounted(() => {
  measure();
  if (typeof ResizeObserver !== 'undefined' && scroller.value !== null) {
    observer = new ResizeObserver(measure);
    observer.observe(scroller.value);
  }
});
onBeforeUnmount(() => observer?.disconnect());

function onScroll(): void {
  scrollTop.value = scroller.value?.scrollTop ?? 0;
}

function ensureVisible(index: number): void {
  const el = scroller.value;
  if (el === null) return;
  const row = Math.floor(index / win.value.columns);
  const top = scrollTopForRow(row, win.value.rowHeight, height.value || 600, scrollTop.value);
  if (top !== scrollTop.value) {
    el.scrollTop = top;
    scrollTop.value = top;
  }
}

// ---- view model of a tile
function viewAt(index: number): ItemView | null {
  const id = order.value[index];
  return id === undefined ? null : props.session.itemView(id);
}
function captionOf(view: ItemView): string {
  if (view.item.missing) return t('editor.grid.unavailable');
  return view.effective.title || t('editor.grid.untitled', { id: view.item.attachmentId });
}
function labelOf(view: ItemView): string {
  const parts = [captionOf(view)];
  if (view.item.attachmentId === props.coverAttachmentId) parts.push(t('editor.grid.isCover'));
  if (view.edited) parts.push(t('editor.grid.edited'));
  if (view.item.missing) parts.push(t('editor.grid.unavailableHint'));
  return parts.join(', ');
}
const tileId = (id: number): string => `${base}-tile-${id}`;
const activeDescendant = computed(() => {
  const id = props.selection.activeId.value;
  const index = props.selection.activeIndex.value;
  return id !== null && index >= win.value.start && index < win.value.end ? tileId(id) : undefined;
});

// ---- selection and navigation
function indexOfTarget(target: EventTarget | null): number {
  const el = (target as HTMLElement | null)?.closest<HTMLElement>('[data-index]');
  const value = el === null || el === undefined ? NaN : Number.parseInt(el.dataset.index ?? '', 10);
  return Number.isFinite(value) ? value : -1;
}

function onClick(event: MouseEvent): void {
  const index = indexOfTarget(event.target);
  if (index < 0) return;
  if (event.shiftKey) props.selection.range(index);
  else props.selection.toggle(index);
}

function onDblClick(event: MouseEvent): void {
  const index = indexOfTarget(event.target);
  const id = order.value[index];
  if (id !== undefined) {
    props.selection.setActive(index);
    emit('open-details', id);
  }
}

function announce(message: string): void {
  app.announce(message);
}

const MOVE_KEYS: Record<string, MoveMode | undefined> = {
  ArrowLeft: 'earlier',
  ArrowUp: 'earlier',
  ArrowRight: 'later',
  ArrowDown: 'later',
  Home: 'start',
  End: 'end',
};

/** Moves the selected (or the active) items and keeps the active tile in view; public for the toolbar. */
async function moveTargets(mode: MoveMode): Promise<void> {
  if (!canReorder.value) return;
  const targets = props.selection.targets.value;
  const activeId = props.selection.activeId.value;
  if (!props.session.move(targets, mode)) return;
  const anchorId = activeId !== null && targets.has(activeId) ? activeId : [...targets][0];
  const index = anchorId === undefined ? -1 : order.value.indexOf(anchorId);
  if (index >= 0) props.selection.setActive(index);
  if (targets.size === 1 && index >= 0) {
    announce(t('editor.reorder.movedTo', { position: index + 1, total: order.value.length }));
  } else {
    announce(t(`editor.reorder.group.${mode}`, { count: targets.size }));
  }
  await nextTick();
  ensureVisible(props.selection.activeIndex.value);
}
defineExpose({ moveTargets, focus: () => scroller.value?.focus() });

function pageRows(): number {
  return Math.max(1, Math.floor((height.value || 600) / win.value.rowHeight));
}

function isRtl(): boolean {
  return scroller.value !== null && getComputedStyle(scroller.value).direction === 'rtl';
}

async function onKeydown(event: KeyboardEvent): Promise<void> {
  const sel = props.selection;
  const count = order.value.length;
  if (count === 0) return;
  const mod = event.ctrlKey || event.metaKey;
  const key = event.key;
  const columns = win.value.columns;

  if (mod && key.toLowerCase() === 'a') {
    event.preventDefault();
    sel.selectAll();
    announce(t('editor.selection.allSelected', { count }));
    return;
  }
  if (mod && MOVE_KEYS[key] !== undefined) {
    event.preventDefault();
    const mode = MOVE_KEYS[key];
    if (mode !== undefined) {
      // In right-to-left text the horizontal arrows swap their meaning.
      const swapped = isRtl() && (key === 'ArrowLeft' || key === 'ArrowRight');
      const effective: MoveMode = swapped ? (mode === 'earlier' ? 'later' : 'earlier') : mode;
      await moveTargets(effective);
    }
    return;
  }
  let next: number;
  const rtl = isRtl();
  switch (key) {
    case 'ArrowRight':
      next = sel.activeIndex.value + (rtl ? -1 : 1);
      break;
    case 'ArrowLeft':
      next = sel.activeIndex.value + (rtl ? 1 : -1);
      break;
    case 'ArrowDown':
      next = sel.activeIndex.value + columns;
      break;
    case 'ArrowUp':
      next = sel.activeIndex.value - columns;
      break;
    case 'PageDown':
      next = sel.activeIndex.value + columns * pageRows();
      break;
    case 'PageUp':
      next = sel.activeIndex.value - columns * pageRows();
      break;
    case 'Home':
      next = 0;
      break;
    case 'End':
      next = count - 1;
      break;
    case ' ':
      event.preventDefault();
      if (event.shiftKey) sel.range(sel.activeIndex.value);
      else sel.toggle(sel.activeIndex.value);
      return;
    case 'Enter': {
      event.preventDefault();
      const id = sel.activeId.value;
      if (id !== null) emit('open-details', id);
      return;
    }
    case 'Delete':
    case 'Backspace':
      if (!props.readonly) {
        event.preventDefault();
        emit('remove-request');
      }
      return;
    case 'Escape':
      if (sel.count.value > 0) {
        event.preventDefault();
        sel.clear();
      }
      return;
    default:
      return;
  }
  event.preventDefault();
  const clamped = Math.min(Math.max(next, 0), count - 1);
  if (event.shiftKey) {
    sel.anchor.value ??= sel.activeIndex.value;
    sel.range(clamped);
  } else {
    sel.setActive(clamped);
  }
  ensureVisible(clamped);
}

watch(order, () => props.selection.prune());

// ---- drag and drop (native HTML5, mouse; keyboard and buttons are the alternative)
const dragIds = ref<ReadonlySet<number>>(new Set());
const dropIndex = ref<number | null>(null);

function onDragStart(event: DragEvent): void {
  const index = indexOfTarget(event.target);
  const id = order.value[index];
  if (id === undefined || !canReorder.value) {
    event.preventDefault();
    return;
  }
  if (!props.selection.isSelected(id)) {
    props.selection.clear();
    props.selection.toggle(index);
  }
  dragIds.value = new Set(props.selection.selected.value);
  if (event.dataTransfer !== null) {
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', 'swg-items');
  }
}

function onDragOver(event: DragEvent): void {
  if (dragIds.value.size === 0) return;
  event.preventDefault();
  if (event.dataTransfer !== null) event.dataTransfer.dropEffect = 'move';
  const el = scroller.value;
  if (el !== null) {
    const rect = el.getBoundingClientRect();
    if (event.clientY < rect.top + 40) el.scrollTop -= 24;
    else if (event.clientY > rect.bottom - 40) el.scrollTop += 24;
  }
  const index = indexOfTarget(event.target);
  if (index < 0) return;
  const tile = (event.target as HTMLElement).closest<HTMLElement>('[data-index]');
  if (tile === null) return;
  const rect = tile.getBoundingClientRect();
  const mid = rect.left + rect.width / 2;
  const before = isRtl() ? event.clientX > mid : event.clientX < mid;
  dropIndex.value = before ? index : index + 1;
}

function endDrag(): void {
  dragIds.value = new Set();
  dropIndex.value = null;
}

function onDrop(event: DragEvent): void {
  if (dragIds.value.size === 0) return;
  event.preventDefault();
  const target = dropIndex.value;
  const dragged = dragIds.value;
  endDrag();
  if (target === null) return;
  if (props.session.moveTo(dragged, target)) {
    const first = [...dragged][0];
    const index = first === undefined ? -1 : order.value.indexOf(first);
    if (index >= 0) props.selection.setActive(index);
    if (dragged.size === 1 && index >= 0) {
      announce(t('editor.reorder.movedTo', { position: index + 1, total: order.value.length }));
    } else {
      announce(t('editor.reorder.dropped', { count: dragged.size }));
    }
  }
}

function dropMark(index: number): 'before' | 'after' | null {
  const target = dropIndex.value;
  if (target === null) return null;
  if (target === index) return 'before';
  if (target === order.value.length && index === order.value.length - 1) return 'after';
  return null;
}
</script>

<template>
  <div class="swg-igrid-wrap">
    <p :id="helpId" class="swg-sr-only">{{ t('editor.grid.keyboardHelp') }}</p>
    <div
      ref="scroller"
      class="swg-igrid"
      role="listbox"
      aria-multiselectable="true"
      tabindex="0"
      :aria-label="t('editor.grid.label', { count: order.length })"
      :aria-describedby="helpId"
      :aria-activedescendant="activeDescendant"
      :aria-readonly="readonly"
      @scroll.passive="onScroll"
      @keydown="onKeydown"
      @focus="focused = true"
      @blur="focused = false"
      @click="onClick"
      @dblclick="onDblClick"
      @dragstart="onDragStart"
      @dragover="onDragOver"
      @dragleave.self="dropIndex = null"
      @drop="onDrop"
      @dragend="endDrag"
    >
      <div
        class="swg-igrid__window"
        :class="{ 'is-focused': focused }"
        :style="{
          paddingBlockStart: `${win.padTop}px`,
          paddingBlockEnd: `${win.padBottom}px`,
        }"
      >
        <div
          class="swg-igrid__rows"
          :style="{
            gridTemplateColumns: `repeat(${win.columns}, minmax(0, 1fr))`,
            gridAutoRows: `${win.rowHeight - 12}px`,
            gap: '12px',
          }"
        >
          <ItemTile
            v-for="row in visible"
            :key="row.id"
            :dom-id="tileId(row.id)"
            :view="row.view"
            :index="row.index"
            :total="order.length"
            :label="labelOf(row.view)"
            :caption="captionOf(row.view)"
            :selected="selection.isSelected(row.id)"
            :active="selection.activeIndex.value === row.index"
            :is-cover="row.view.item.attachmentId === coverAttachmentId"
            :draggable="canReorder"
            :drop="dropMark(row.index)"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.swg-igrid {
  max-block-size: min(72vh, 760px);
  min-block-size: 200px;
  overflow-y: auto;
  padding: 8px;
  border: 1px solid var(--swg-border);
  border-radius: var(--swg-radius);
  background: var(--swg-surface);
}
.swg-igrid:focus-visible {
  outline: 3px solid var(--swg-focus);
  outline-offset: 2px;
}
.swg-igrid__rows {
  display: grid;
}
.swg-igrid:focus-visible :deep(.swg-tile.is-active .swg-tile__thumb) {
  outline: 3px solid var(--swg-focus);
  outline-offset: 2px;
}
@media (prefers-reduced-motion: no-preference) {
  .swg-igrid {
    scroll-behavior: auto;
  }
}
</style>
