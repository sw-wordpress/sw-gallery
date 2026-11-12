<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { GalleryStatus } from '@/api/galleries';
import type { EditorSession } from '../useGalleryEditor';

/** Title, plain-text description, cover and status of the gallery (staged, saved with the rest). */
const props = defineProps<{
  session: EditorSession;
  canPublish: boolean;
  readonly: boolean;
}>();
const { t } = useI18n();

const details = computed(() => props.session.working.value?.details ?? null);
const invalid = (field: string): boolean => props.session.invalidDetails.value.includes(field);

interface CoverOption {
  value: number | null;
  label: string;
}
const coverOptions = computed<CoverOption[]>(() => {
  const options: CoverOption[] = [{ value: null, label: t('editor.details.coverAuto') }];
  const seen = new Set<number>();
  props.session.order.value.forEach((id, index) => {
    const view = props.session.itemView(id);
    if (view === null || view.item.missing || seen.has(view.item.attachmentId)) return;
    seen.add(view.item.attachmentId);
    const name = view.effective.title || t('editor.grid.untitled', { id: view.item.attachmentId });
    options.push({ value: view.item.attachmentId, label: `${index + 1}. ${name}` });
  });
  const current = details.value?.coverAttachmentId ?? null;
  if (current !== null && !seen.has(current)) {
    options.push({ value: current, label: t('editor.details.coverOther', { id: current }) });
  }
  return options;
});

const STATUSES: GalleryStatus[] = ['draft', 'pending', 'publish', 'private'];
const statusOptions = computed(() =>
  STATUSES.map((value) => ({
    value,
    label: t(`galleries.status.${value}`),
    // Publishing needs the publish right; a contributor can still keep draft/pending.
    disable: (value === 'publish' || value === 'private') && !props.canPublish,
  })),
);
const coverIsAuto = computed(() => details.value?.coverAttachmentId === null);
</script>

<template>
  <div v-if="details" class="swg-gdp">
    <q-input
      outlined
      dense
      maxlength="200"
      counter
      :model-value="details.title"
      :label="t('editor.details.title')"
      :disable="readonly"
      :error="invalid('title')"
      :error-message="t('errors.fieldInvalid')"
      @update:model-value="(v) => session.setDetail('title', v === null ? '' : String(v))"
    />
    <q-input
      outlined
      type="textarea"
      autogrow
      maxlength="10000"
      counter
      :model-value="details.description"
      :label="t('editor.details.description')"
      :hint="t('editor.details.descriptionHint')"
      :disable="readonly"
      :error="invalid('description')"
      :error-message="t('errors.fieldInvalid')"
      @update:model-value="(v) => session.setDetail('description', v === null ? '' : String(v))"
    />
    <q-select
      outlined
      dense
      emit-value
      map-options
      :model-value="details.coverAttachmentId"
      :options="coverOptions"
      :label="t('editor.details.cover')"
      :hint="coverIsAuto ? t('editor.details.coverAutoHint') : undefined"
      :disable="readonly"
      :error="invalid('coverAttachmentId')"
      :error-message="t('errors.invalidCover')"
      @update:model-value="(v: number | null) => session.setDetail('coverAttachmentId', v)"
    />
    <q-select
      outlined
      dense
      emit-value
      map-options
      option-disable="disable"
      :model-value="details.status"
      :options="statusOptions"
      :label="t('editor.details.status')"
      :hint="canPublish ? undefined : t('editor.details.cannotPublish')"
      :disable="readonly || details.status === 'trash'"
      :error="invalid('status')"
      :error-message="t('errors.cannotPublish')"
      @update:model-value="(v: GalleryStatus) => session.setDetail('status', v)"
    />
  </div>
</template>

<style scoped>
.swg-gdp {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
</style>
