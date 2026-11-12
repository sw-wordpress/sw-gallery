<script setup lang="ts">
import { ref, watch } from 'vue';
import type { ItemView } from '../useGalleryEditor';

/**
 * One tile of the items grid. Purely presentational: the grid delegates click, keyboard and drag
 * events (data-index) and owns the roving state (`aria-activedescendant`), so tiles that scroll
 * out of the virtual window can be unmounted without losing focus.
 */
const props = defineProps<{
  domId: string;
  view: ItemView;
  index: number;
  total: number;
  /** Accessible name incl. state (cover, edited, unavailable). */
  label: string;
  caption: string;
  selected: boolean;
  active: boolean;
  isCover: boolean;
  draggable: boolean;
  drop: 'before' | 'after' | null;
}>();

const failed = ref(false);
watch(
  () => props.view.item.media?.thumbnailUrl,
  () => {
    failed.value = false;
  },
);
</script>

<template>
  <div
    :id="domId"
    role="option"
    class="swg-tile"
    :class="{
      'is-selected': selected,
      'is-active': active,
      'is-missing': view.item.missing,
      'is-drop-before': drop === 'before',
      'is-drop-after': drop === 'after',
    }"
    :aria-selected="selected"
    :aria-posinset="index + 1"
    :aria-setsize="total"
    :aria-label="label"
    :draggable="draggable"
    v-bind="{ 'data-index': index, 'data-item-id': view.item.id }"
  >
    <div class="swg-tile__thumb">
      <img
        v-if="view.item.media?.thumbnailUrl && !failed"
        :src="view.item.media.thumbnailUrl"
        alt=""
        loading="lazy"
        decoding="async"
        draggable="false"
        @error="failed = true"
      />
      <span v-else class="swg-tile__ph" aria-hidden="true">
        <q-icon :name="view.item.missing ? 'r_broken_image' : 'r_image'" size="36px" />
      </span>
      <span class="swg-tile__check" aria-hidden="true"><q-icon name="r_check" size="16px" /></span>
      <span class="swg-tile__pos swg-num" aria-hidden="true">{{ index + 1 }}</span>
      <span class="swg-tile__badges" aria-hidden="true">
        <q-icon v-if="isCover" name="r_star" size="16px" />
        <q-icon v-if="view.edited" name="r_edit" size="16px" />
      </span>
    </div>
    <div class="swg-tile__label" :class="{ 'is-missing': view.item.missing }">
      <span class="swg-truncate">{{ caption }}</span>
    </div>
  </div>
</template>

<style scoped>
.swg-tile {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-inline-size: 0;
  border-radius: var(--swg-radius-sm);
  cursor: pointer;
  user-select: none;
}
.swg-tile__thumb {
  position: relative;
  aspect-ratio: 1;
  overflow: hidden;
  border: 2px solid var(--swg-border);
  border-radius: var(--swg-radius-sm);
  background: var(--swg-surface-3);
}
.swg-tile__thumb img {
  display: block;
  inline-size: 100%;
  block-size: 100%;
  object-fit: cover;
}
.swg-tile__ph {
  display: grid;
  place-items: center;
  inline-size: 100%;
  block-size: 100%;
  color: var(--swg-muted);
}
.is-missing .swg-tile__thumb {
  border-style: dashed;
}
.swg-tile.is-selected .swg-tile__thumb {
  border-color: var(--swg-primary);
  box-shadow: 0 0 0 2px var(--swg-primary);
}
.swg-tile__check {
  position: absolute;
  inset-block-start: 6px;
  inset-inline-start: 6px;
  display: grid;
  place-items: center;
  inline-size: 22px;
  block-size: 22px;
  border: 2px solid var(--swg-on-media);
  border-radius: 50%;
  background: var(--swg-media-scrim);
  color: transparent;
}
.is-selected .swg-tile__check {
  background: var(--swg-primary);
  color: var(--swg-on-media);
}
.swg-tile__pos {
  position: absolute;
  inset-block-end: 6px;
  inset-inline-start: 6px;
  padding: 0 6px;
  border-radius: 999px;
  background: var(--swg-media-scrim);
  color: var(--swg-on-media);
  font-size: 12px;
  font-weight: 600;
}
.swg-tile__badges {
  position: absolute;
  inset-block-start: 6px;
  inset-inline-end: 6px;
  display: flex;
  gap: 4px;
  padding: 2px 4px;
  border-radius: 999px;
  background: var(--swg-media-scrim);
  color: var(--swg-on-media);
}
.swg-tile__badges:empty {
  display: none;
}
.swg-tile__label > span {
  display: block;
}
.swg-tile__label {
  min-inline-size: 0;
  font-size: 13px;
  line-height: 1.3;
  min-block-size: 1.3em;
  color: var(--swg-text);
}
.swg-tile__label.is-missing {
  color: var(--swg-danger-text);
}
.is-active .swg-tile__thumb {
  outline: 3px solid transparent;
}
.swg-tile.is-drop-before::before,
.swg-tile.is-drop-after::after {
  content: '';
  position: absolute;
  inset-block: 0;
  inline-size: 4px;
  border-radius: 2px;
  background: var(--swg-primary);
}
.swg-tile.is-drop-before::before {
  inset-inline-start: -8px;
}
.swg-tile.is-drop-after::after {
  inset-inline-end: -8px;
}
</style>
