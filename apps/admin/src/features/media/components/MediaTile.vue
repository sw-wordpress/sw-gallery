<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { MediaLibraryItem } from '@/api/media';
import MediaUsage from './MediaUsage.vue';

const props = defineProps<{ item: MediaLibraryItem; selected: boolean }>();
const emit = defineEmits<{
  (e: 'toggle', event: MouseEvent): void;
  (e: 'open'): void;
}>();
const { t } = useI18n();
/** A thumbnail that fails to load falls back to the neutral placeholder. */
const broken = ref(false);
watch(
  () => props.item.thumbnailUrl,
  () => {
    broken.value = false;
  },
);
function name(): string {
  return props.item.title === '' ? t('media.untitled') : props.item.title;
}
</script>

<template>
  <li class="swg-mtile" :class="{ 'is-selected': selected }" v-bind="{ 'data-media-id': item.id }">
    <button
      type="button"
      role="checkbox"
      class="swg-mtile__check"
      :aria-checked="selected ? 'true' : 'false'"
      :aria-label="t('media.select', { title: name() })"
      @click="emit('toggle', $event)"
    >
      <q-icon :name="selected ? 'r_check_box' : 'r_check_box_outline_blank'" size="24px" />
    </button>
    <button
      type="button"
      class="swg-mtile__thumb"
      :aria-label="t('media.openDetails', { title: name() })"
      @click="emit('open')"
    >
      <img
        v-if="item.thumbnailUrl && !broken"
        :src="item.thumbnailUrl"
        alt=""
        loading="lazy"
        decoding="async"
        @error="broken = true"
      />
      <span v-else class="swg-mtile__none"
        ><q-icon name="r_image_not_supported" size="32px" aria-hidden="true"
      /></span>
    </button>
    <div class="swg-mtile__meta">
      <span class="swg-mtile__title swg-truncate" :title="name()">{{ name() }}</span>
      <MediaUsage :count="item.usedInGalleries" />
    </div>
  </li>
</template>

<style scoped>
.swg-mtile {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 8px;
  background: var(--swg-surface);
  border: 1px solid var(--swg-border);
  border-radius: var(--swg-radius-sm);
  box-shadow: var(--swg-shadow-1);
  min-inline-size: 0;
}
.swg-mtile.is-selected {
  border-color: var(--swg-primary);
  box-shadow: 0 0 0 2px var(--swg-primary);
}
.swg-mtile__check {
  position: absolute;
  inset-block-start: 12px;
  inset-inline-start: 12px;
  z-index: 1;
  display: grid;
  place-items: center;
  inline-size: 32px;
  block-size: 32px;
  padding: 0;
  border: 0;
  border-radius: 8px;
  cursor: pointer;
  color: var(--swg-primary-text);
  background: var(--swg-surface);
  box-shadow: var(--swg-shadow-1);
}
.swg-mtile__thumb {
  display: block;
  inline-size: 100%;
  aspect-ratio: 1;
  padding: 0;
  border: 0;
  border-radius: 8px;
  overflow: hidden;
  cursor: pointer;
  background: var(--swg-surface-3);
}
.swg-mtile__thumb img {
  display: block;
  inline-size: 100%;
  block-size: 100%;
  object-fit: cover;
}
.swg-mtile__none {
  display: grid;
  place-items: center;
  inline-size: 100%;
  block-size: 100%;
  color: var(--swg-muted);
}
.swg-mtile__meta {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
  min-inline-size: 0;
}
.swg-mtile__title {
  max-inline-size: 100%;
  font-size: 13px;
  font-weight: 600;
}
</style>
