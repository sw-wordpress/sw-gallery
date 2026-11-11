<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { Gallery } from '@/api/galleries';
import SwgCard from '@/components/ui/SwgCard.vue';
import type { GalleryActionKind } from '../format';
import GalleryActionsMenu from './GalleryActionsMenu.vue';
import GalleryCover from './GalleryCover.vue';
import GalleryStatusChip from './GalleryStatusChip.vue';

defineProps<{ galleries: Gallery[]; formatModified: (iso: string) => string }>();
const emit = defineEmits<{ (e: 'action', kind: GalleryActionKind, gallery: Gallery): void }>();
const { t } = useI18n();
</script>

<template>
  <SwgCard tag="div" :padding="false" class="swg-gtable">
    <div class="swg-gtable__scroll">
      <table class="swg-gtable__table">
        <caption class="swg-sr-only">
          {{
            t('galleries.table.caption')
          }}
        </caption>
        <thead>
          <tr>
            <th scope="col" class="swg-gtable__cover">
              <span class="swg-sr-only">{{ t('galleries.table.cover') }}</span>
            </th>
            <th scope="col">{{ t('galleries.table.title') }}</th>
            <th scope="col">{{ t('galleries.table.status') }}</th>
            <th scope="col" class="swg-gtable__num">{{ t('galleries.table.images') }}</th>
            <th scope="col">{{ t('galleries.table.modified') }}</th>
            <th scope="col" class="swg-gtable__actions">
              <span class="swg-sr-only">{{ t('galleries.table.actions') }}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="gallery in galleries"
            :key="gallery.id"
            v-bind="{ 'data-gallery-id': gallery.id }"
          >
            <td class="swg-gtable__cover"><GalleryCover :url="gallery.coverUrl" small /></td>
            <th scope="row" class="swg-gtable__title">
              <span class="swg-gtable__name">{{
                gallery.title || t('galleries.card.untitled')
              }}</span>
            </th>
            <td><GalleryStatusChip :gallery="gallery" /></td>
            <td class="swg-gtable__num swg-num">{{ gallery.itemCount }}</td>
            <td>
              <time class="swg-num" :datetime="gallery.modified">{{
                formatModified(gallery.modified)
              }}</time>
            </td>
            <td class="swg-gtable__actions">
              <GalleryActionsMenu :gallery="gallery" @action="(k, g) => emit('action', k, g)" />
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </SwgCard>
</template>

<style scoped>
.swg-gtable__scroll {
  /* Positioned: the absolutely placed screen-reader texts of the cells must be clipped by this scroller too. */
  position: relative;
  overflow-x: auto;
}
.swg-gtable__table {
  inline-size: 100%;
  border-collapse: collapse;
  font-size: 14px;
}
.swg-gtable__table th,
.swg-gtable__table td {
  padding: 10px 14px;
  text-align: start;
  vertical-align: middle;
  border-block-end: 1px solid var(--swg-border);
}
.swg-gtable__table thead th {
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--swg-muted);
  background: var(--swg-surface-2);
  white-space: nowrap;
}
.swg-gtable__table tbody tr:last-child > * {
  border-block-end: 0;
}
.swg-gtable__table tbody tr:hover {
  background: var(--swg-surface-2);
}
.swg-gtable__cover {
  inline-size: 1%;
}
.swg-gtable__title {
  font-weight: 600;
  min-inline-size: 180px;
}
.swg-gtable__name {
  overflow-wrap: anywhere;
}
.swg-gtable__num {
  text-align: end !important;
}
.swg-gtable__actions {
  inline-size: 1%;
  text-align: end !important;
}
</style>
