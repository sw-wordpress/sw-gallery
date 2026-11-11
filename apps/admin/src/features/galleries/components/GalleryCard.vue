<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { Gallery } from '@/api/galleries';
import SwgCard from '@/components/ui/SwgCard.vue';
import type { GalleryActionKind } from '../format';
import GalleryActionsMenu from './GalleryActionsMenu.vue';
import GalleryCover from './GalleryCover.vue';
import GalleryStatusChip from './GalleryStatusChip.vue';

const props = defineProps<{ gallery: Gallery; modified: string }>();
const emit = defineEmits<{ (e: 'action', kind: GalleryActionKind, gallery: Gallery): void }>();
const { t } = useI18n();
const title = computed(() => props.gallery.title || t('galleries.card.untitled'));
</script>

<template>
  <SwgCard tag="li" :padding="false" class="swg-gcard" v-bind="{ 'data-gallery-id': gallery.id }">
    <GalleryCover :url="gallery.coverUrl" />
    <div class="swg-gcard__body">
      <div class="swg-gcard__head">
        <h3 class="swg-gcard__title" :title="title">{{ title }}</h3>
        <GalleryActionsMenu :gallery="gallery" @action="(k, g) => emit('action', k, g)" />
      </div>
      <GalleryStatusChip :gallery="gallery" />
      <p class="swg-gcard__meta swg-num">
        {{ t('galleries.card.images', { count: gallery.itemCount }, gallery.itemCount) }}
        <span aria-hidden="true">·</span>
        <time :datetime="gallery.modified">{{
          t('galleries.card.modified', { date: modified })
        }}</time>
      </p>
    </div>
  </SwgCard>
</template>

<style scoped>
.swg-gcard {
  overflow: hidden;
  list-style: none;
  display: flex;
  flex-direction: column;
}
.swg-gcard :deep(.swg-card__body) {
  padding: 0;
  display: flex;
  flex-direction: column;
  flex: 1;
}
.swg-gcard__body {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 8px;
  padding: 12px 8px 14px 16px;
  flex: 1;
}
.swg-gcard__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
  inline-size: 100%;
}
.swg-gcard__title {
  font-size: 16px;
  font-weight: 650;
  line-height: 1.3;
  min-inline-size: 0;
  padding-block-start: 4px;
  overflow-wrap: anywhere;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.swg-gcard__meta {
  font-size: 13px;
  color: var(--swg-muted);
  display: flex;
  flex-wrap: wrap;
  gap: 4px 8px;
  margin-block-start: auto;
}
</style>
