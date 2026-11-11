<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { Gallery, WritableGalleryStatus } from '@/api/galleries';
import FormSheet from '@/components/ui/FormSheet.vue';

export interface GalleryFormValues {
  title: string;
  description: string;
  status: WritableGalleryStatus;
}

const open = defineModel<boolean>({ required: true });
const props = withDefaults(
  defineProps<{
    mode: 'create' | 'edit';
    /** The row being edited (edit mode). Its `canPublish` hint decides which statuses are offered. */
    gallery?: Gallery | null;
    loading?: boolean;
    errorMessage?: string | null;
    fieldErrors?: Record<string, string>;
  }>(),
  { gallery: null, loading: false, errorMessage: null, fieldErrors: () => ({}) },
);
const emit = defineEmits<{ (e: 'submit', values: GalleryFormValues): void }>();
const { t } = useI18n();

const MAX_TITLE = 200;
const MAX_DESCRIPTION = 10000;

const title = ref('');
const description = ref('');
const status = ref<WritableGalleryStatus>('draft');

/** A trashed or unknown status is never written back through this form; it is shown as draft. */
function editableStatus(value: Gallery['status'] | undefined): WritableGalleryStatus {
  return value === 'pending' || value === 'publish' || value === 'private' ? value : 'draft';
}

// The form is initialised when the sheet opens only: a background refresh of the row (e.g. after a 409)
// must never overwrite what the user typed.
watch(
  open,
  (isOpen) => {
    if (!isOpen) return;
    title.value = props.mode === 'edit' ? (props.gallery?.title ?? '') : '';
    description.value = props.mode === 'edit' ? (props.gallery?.description ?? '') : '';
    status.value = props.mode === 'edit' ? editableStatus(props.gallery?.status) : 'draft';
  },
  { immediate: true },
);

const canPublish = computed(() => props.gallery?.canPublish === true);
const statusOptions = computed(() =>
  (['draft', 'pending', 'publish', 'private'] as const).map((value) => {
    const restricted = (value === 'publish' || value === 'private') && !canPublish.value;
    const base = t(`galleries.status.${value}`);
    return {
      value,
      label: restricted ? `${base} (${t('galleries.form.statusNeedsRight')})` : base,
      disable: restricted,
    };
  }),
);

const titleRules = [
  (v: string | null) => (v ?? '').trim() !== '' || t('galleries.form.titleRequired'),
  (v: string | null) => (v ?? '').length <= MAX_TITLE || t('galleries.form.titleTooLong'),
];
const descriptionRules = [
  (v: string | null) =>
    (v ?? '').length <= MAX_DESCRIPTION || t('galleries.form.descriptionTooLong'),
];

function str(value: string | number | null): string {
  return value === null ? '' : String(value);
}

function submit(): void {
  emit('submit', {
    title: title.value.trim(),
    description: description.value,
    status: status.value,
  });
}
</script>

<template>
  <FormSheet
    v-model="open"
    :title="mode === 'create' ? t('galleries.form.createTitle') : t('galleries.form.editTitle')"
    :subtitle="
      mode === 'create'
        ? t('galleries.form.createSubtitle')
        : t('galleries.form.editSubtitle', { title: gallery?.title ?? '' })
    "
    :submit-label="
      mode === 'create' ? t('galleries.form.createSubmit') : t('galleries.form.editSubmit')
    "
    :cancel-label="t('galleries.form.cancel')"
    :close-label="t('galleries.form.close')"
    :loading="loading"
    :error-message="errorMessage"
    :field-errors="fieldErrors"
    :width="520"
    @submit="submit"
  >
    <div class="swg-gform">
      <q-input
        :model-value="title"
        outlined
        autofocus
        autocomplete="off"
        :label="t('galleries.form.title')"
        :rules="titleRules"
        lazy-rules="ondemand"
        @update:model-value="(v) => (title = str(v))"
      />
      <q-input
        :model-value="description"
        outlined
        type="textarea"
        autogrow
        :label="t('galleries.form.description')"
        :hint="t('galleries.form.descriptionHint')"
        :rules="descriptionRules"
        lazy-rules="ondemand"
        @update:model-value="(v) => (description = str(v))"
      />
      <q-select
        v-if="mode === 'edit'"
        v-model="status"
        outlined
        emit-value
        map-options
        option-disable="disable"
        :options="statusOptions"
        :label="t('galleries.form.status')"
        :hint="canPublish ? undefined : t('galleries.form.statusHint')"
      />
    </div>
  </FormSheet>
</template>

<style scoped>
.swg-gform {
  display: flex;
  flex-direction: column;
  gap: 20px;
  margin-block-start: 20px;
}
</style>
