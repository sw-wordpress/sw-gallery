<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { MediaLibraryItem } from '@/api/media';
import SwgCard from '@/components/ui/SwgCard.vue';
import MediaUsage from './MediaUsage.vue';

defineProps<{
  items: MediaLibraryItem[];
  selected: ReadonlySet<number>;
  /** `aria-checked` of the header checkbox. */
  allState: 'true' | 'false' | 'mixed';
  formatDate: (iso: string) => string;
}>();
const emit = defineEmits<{
  (e: 'toggle', index: number, event: MouseEvent): void;
  (e: 'toggle-all'): void;
  (e: 'open', index: number): void;
}>();
const { t } = useI18n();

function name(item: MediaLibraryItem): string {
  return item.title === '' ? t('media.untitled') : item.title;
}
</script>

<template>
  <SwgCard tag="div" :padding="false" class="swg-mtable">
    <div class="swg-mtable__scroll">
      <table class="swg-mtable__table">
        <caption class="swg-sr-only">
          {{
            t('media.table.caption')
          }}
        </caption>
        <thead>
          <tr>
            <th scope="col" class="swg-mtable__check">
              <button
                type="button"
                role="checkbox"
                class="swg-mtable__cb"
                :aria-checked="allState"
                :aria-label="t('media.selectAllPage')"
                @click="emit('toggle-all')"
              >
                <q-icon
                  :name="
                    allState === 'true'
                      ? 'r_check_box'
                      : allState === 'mixed'
                        ? 'r_indeterminate_check_box'
                        : 'r_check_box_outline_blank'
                  "
                  size="24px"
                />
              </button>
            </th>
            <th scope="col">
              <span class="swg-sr-only">{{ t('media.table.preview') }}</span>
            </th>
            <th scope="col">{{ t('media.table.title') }}</th>
            <th scope="col">{{ t('media.table.type') }}</th>
            <th scope="col">{{ t('media.table.size') }}</th>
            <th scope="col">{{ t('media.table.uploaded') }}</th>
            <th scope="col">{{ t('media.table.usage') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="(item, index) in items"
            :key="item.id"
            :class="{ 'is-selected': selected.has(item.id) }"
            v-bind="{ 'data-media-id': item.id }"
          >
            <td class="swg-mtable__check">
              <button
                type="button"
                role="checkbox"
                class="swg-mtable__cb"
                :aria-checked="selected.has(item.id) ? 'true' : 'false'"
                :aria-label="t('media.select', { title: name(item) })"
                @click="emit('toggle', index, $event)"
              >
                <q-icon
                  :name="selected.has(item.id) ? 'r_check_box' : 'r_check_box_outline_blank'"
                  size="24px"
                />
              </button>
            </td>
            <td class="swg-mtable__thumb">
              <img
                v-if="item.thumbnailUrl"
                :src="item.thumbnailUrl"
                alt=""
                loading="lazy"
                decoding="async"
              />
            </td>
            <th scope="row" class="swg-mtable__name">
              <button
                type="button"
                class="swg-mtable__open"
                :aria-label="t('media.openDetails', { title: name(item) })"
                @click="emit('open', index)"
              >
                {{ name(item) }}
              </button>
            </th>
            <td>{{ item.mimeType }}</td>
            <td class="swg-num">{{ item.width }} × {{ item.height }}</td>
            <td>
              <time class="swg-num" :datetime="item.uploadedAt">{{
                formatDate(item.uploadedAt)
              }}</time>
            </td>
            <td><MediaUsage :count="item.usedInGalleries" /></td>
          </tr>
        </tbody>
      </table>
    </div>
  </SwgCard>
</template>

<style scoped>
.swg-mtable__scroll {
  position: relative;
  overflow-x: auto;
}
.swg-mtable__table {
  inline-size: 100%;
  border-collapse: collapse;
  font-size: 14px;
}
.swg-mtable__table th,
.swg-mtable__table td {
  padding: 8px 14px;
  text-align: start;
  vertical-align: middle;
  border-block-end: 1px solid var(--swg-border);
}
.swg-mtable__table thead th {
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--swg-muted);
  background: var(--swg-surface-2);
  white-space: nowrap;
}
.swg-mtable__table tbody tr:last-child > * {
  border-block-end: 0;
}
.swg-mtable__table tbody tr:hover,
.swg-mtable__table tbody tr.is-selected {
  background: var(--swg-surface-2);
}
.swg-mtable__check {
  inline-size: 1%;
}
.swg-mtable__cb,
.swg-mtable__open {
  padding: 0;
  border: 0;
  background: transparent;
  color: inherit;
  cursor: pointer;
  font: inherit;
}
.swg-mtable__cb {
  display: grid;
  place-items: center;
  inline-size: 32px;
  block-size: 32px;
  border-radius: 8px;
  color: var(--swg-primary-text);
}
.swg-mtable__thumb img {
  display: block;
  inline-size: 48px;
  block-size: 48px;
  border-radius: 8px;
  object-fit: cover;
}
.swg-mtable__name {
  min-inline-size: 160px;
  font-weight: 600;
}
.swg-mtable__open {
  min-block-size: 32px;
  text-align: start;
  overflow-wrap: anywhere;
  color: var(--swg-primary);
}
.swg-mtable__open:hover {
  text-decoration: underline;
}
</style>
