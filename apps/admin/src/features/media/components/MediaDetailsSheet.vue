<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { MediaLibraryItem } from '@/api/media';
import SideSheet from '@/components/ui/SideSheet.vue';
import MediaUsage from './MediaUsage.vue';

/**
 * Read-only image details (decision D6): nothing here edits metadata or deletes. Deliberately no
 * file names or paths: the API does not send any and the page never derives one from a URL.
 */
const open = defineModel<boolean>({ required: true });
const props = defineProps<{
  item: MediaLibraryItem | null;
  formatDate: (iso: string) => string;
}>();
const emit = defineEmits<{ (e: 'add', item: MediaLibraryItem): void }>();
const { t } = useI18n();

/** `data-*` hook for tests and automation (the strict template check does not accept bound data attributes). */
function attrs(field: string): Record<string, string> {
  return { 'data-field': field };
}

const title = computed(() =>
  props.item === null || props.item.title === '' ? t('media.untitled') : props.item.title,
);
const rows = computed(() => {
  const item = props.item;
  if (item === null) return [];
  return [
    { key: 'title', label: t('media.details.title'), value: item.title || '–' },
    { key: 'alt', label: t('media.details.alt'), value: item.alt || t('media.details.none') },
    {
      key: 'caption',
      label: t('media.details.caption'),
      value: item.caption || t('media.details.none'),
    },
    {
      key: 'dimensions',
      label: t('media.details.dimensions'),
      value: `${item.width} × ${item.height}`,
    },
    { key: 'type', label: t('media.details.type'), value: item.mimeType },
    { key: 'id', label: t('media.details.id'), value: String(item.id) },
    { key: 'date', label: t('media.details.uploaded'), value: props.formatDate(item.uploadedAt) },
    {
      key: 'user',
      label: t('media.details.uploader'),
      value: t('media.details.userId', { id: item.uploadedBy }),
    },
  ];
});
</script>

<template>
  <SideSheet
    v-model="open"
    :title="title"
    :subtitle="t('media.details.subtitle')"
    :close-label="t('media.details.close')"
    :width="460"
  >
    <div v-if="item" class="swg-mdetails">
      <div class="swg-mdetails__preview">
        <img v-if="item.thumbnailUrl" :src="item.thumbnailUrl" alt="" />
        <q-icon v-else name="r_image_not_supported" size="48px" aria-hidden="true" />
      </div>
      <p class="swg-mdetails__usage">
        <MediaUsage :count="item.usedInGalleries" />
      </p>
      <dl class="swg-mdetails__list">
        <div v-for="row in rows" :key="row.key" class="swg-mdetails__row">
          <dt>{{ row.label }}</dt>
          <dd v-bind="attrs(row.key)">{{ row.value }}</dd>
        </div>
      </dl>
      <p class="swg-muted swg-mdetails__note">{{ t('media.details.readOnly') }}</p>
    </div>
    <template #footer>
      <q-btn flat no-caps :label="t('media.details.close')" @click="open = false" />
      <q-btn
        v-if="item"
        unelevated
        no-caps
        color="primary"
        icon="r_playlist_add"
        :label="t('media.addToGallery')"
        @click="emit('add', item)"
      />
    </template>
  </SideSheet>
</template>

<style scoped>
.swg-mdetails {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding-block-start: 14px;
}
.swg-mdetails__preview {
  display: grid;
  place-items: center;
  min-block-size: 160px;
  border-radius: var(--swg-radius-sm);
  background: var(--swg-surface-3);
  color: var(--swg-muted);
  overflow: hidden;
}
.swg-mdetails__preview img {
  display: block;
  max-inline-size: 100%;
  max-block-size: 320px;
  object-fit: contain;
}
.swg-mdetails__list {
  display: flex;
  flex-direction: column;
  margin: 0;
}
.swg-mdetails__row {
  display: grid;
  grid-template-columns: 9em minmax(0, 1fr);
  gap: 12px;
  padding-block: 8px;
  border-block-end: 1px solid var(--swg-border);
  font-size: 14px;
}
.swg-mdetails__row dt {
  color: var(--swg-muted);
  font-weight: 600;
}
.swg-mdetails__row dd {
  margin: 0;
  overflow-wrap: anywhere;
}
.swg-mdetails__note {
  font-size: 13px;
}
</style>
