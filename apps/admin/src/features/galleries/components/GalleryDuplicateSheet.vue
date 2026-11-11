<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { Gallery } from '@/api/galleries';
import FormSheet from '@/components/ui/FormSheet.vue';

const open = defineModel<boolean>({ required: true });
const props = withDefaults(
  defineProps<{
    gallery: Gallery | null;
    loading?: boolean;
    errorMessage?: string | null;
  }>(),
  { loading: false, errorMessage: null },
);
const emit = defineEmits<{ (e: 'submit', title: string): void }>();
const { t } = useI18n();

const title = ref('');
// Suggestion "<title> (copy)" whenever the sheet opens.
watch(
  open,
  (isOpen) => {
    if (isOpen) {
      title.value = t('galleries.duplicate.suggestion', { title: props.gallery?.title ?? '' });
    }
  },
  { immediate: true },
);

const rules = [
  (v: string | null) => (v ?? '').trim() !== '' || t('galleries.form.titleRequired'),
  (v: string | null) => (v ?? '').length <= 200 || t('galleries.form.titleTooLong'),
];
</script>

<template>
  <FormSheet
    v-model="open"
    :title="t('galleries.duplicate.title')"
    :subtitle="t('galleries.duplicate.subtitle', { title: gallery?.title ?? '' })"
    :submit-label="t('galleries.duplicate.submit')"
    :cancel-label="t('galleries.form.cancel')"
    :close-label="t('galleries.form.close')"
    :loading="loading"
    :error-message="errorMessage"
    :width="480"
    @submit="emit('submit', title.trim())"
  >
    <div class="swg-gdup">
      <q-input
        :model-value="title"
        outlined
        autofocus
        autocomplete="off"
        :label="t('galleries.duplicate.titleLabel')"
        :hint="t('galleries.duplicate.hint')"
        :rules="rules"
        lazy-rules="ondemand"
        @update:model-value="(v) => (title = v === null ? '' : String(v))"
      />
    </div>
  </FormSheet>
</template>

<style scoped>
.swg-gdup {
  margin-block-start: 20px;
}
</style>
