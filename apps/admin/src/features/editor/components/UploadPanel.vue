<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ACCEPTED_TYPES } from '../useUploads';
import type { UploadEntry, Uploads } from '../useUploads';

/** File chooser and progress list; the editor owns the global drop target and toolbar trigger. */
const props = defineProps<{ uploads: Uploads; disabled: boolean }>();
const { t } = useI18n();
const input = ref<HTMLInputElement | null>(null);
const accept = ACCEPTED_TYPES.join(',');

const entries = computed(() => props.uploads.entries.value);
const finished = computed(() =>
  entries.value.filter((e) => ['error', 'cancelled', 'added'].includes(e.status)),
);

function pick(): void {
  if (!props.disabled) input.value?.click();
}
function onPicked(event: Event): void {
  const el = event.target as HTMLInputElement;
  if (!props.disabled) props.uploads.add(Array.from(el.files ?? []));
  el.value = '';
}

defineExpose({ pick });

function percent(e: UploadEntry): number | null {
  return e.total !== null && e.total > 0 ? Math.min(1, e.loaded / e.total) : null;
}
function statusText(e: UploadEntry): string {
  switch (e.status) {
    case 'queued':
      return t('editor.upload.status.queued');
    case 'uploading': {
      const p = percent(e);
      return p === null
        ? t('editor.upload.status.uploadingUnknown')
        : t('editor.upload.status.uploading', { percent: Math.round(p * 100) });
    }
    case 'done':
      return t('editor.upload.status.done');
    case 'added':
      return t('editor.upload.status.added');
    case 'cancelled':
      return t('editor.upload.status.cancelled');
    default:
      return t(`editor.upload.problem.${e.problem ?? 'other'}`);
  }
}
const KIND: Record<string, string> = {
  error: 'is-error',
  cancelled: 'is-muted',
  added: 'is-ok',
};
</script>

<template>
  <div class="swg-up">
    <input
      ref="input"
      class="swg-sr-only"
      type="file"
      multiple
      tabindex="-1"
      aria-hidden="true"
      :accept="accept"
      :disabled="disabled"
      @change="onPicked"
    />

    <ul v-if="entries.length > 0" class="swg-up__list" :aria-label="t('editor.upload.list')">
      <li
        v-for="e in entries"
        :key="e.key"
        class="swg-up__row"
        :class="KIND[e.status]"
        v-bind="{ 'data-upload-status': e.status }"
      >
        <span class="swg-up__name swg-truncate">{{ e.name }}</span>
        <span class="swg-up__state">{{ statusText(e) }}</span>
        <q-linear-progress
          v-if="e.status === 'uploading' || e.status === 'queued'"
          class="swg-up__bar"
          :value="percent(e) ?? 0"
          :indeterminate="e.status === 'uploading' && percent(e) === null"
          :aria-label="t('editor.upload.progressFor', { name: e.name })"
        />
        <span class="swg-up__actions">
          <q-btn
            v-if="e.status === 'uploading' || e.status === 'queued'"
            flat
            dense
            round
            icon="r_close"
            :aria-label="t('editor.upload.cancel', { name: e.name })"
            @click="uploads.cancel(e.key)"
          />
          <q-btn
            v-if="(e.status === 'error' || e.status === 'cancelled') && uploads.canRetry(e.key)"
            flat
            dense
            no-caps
            icon="r_refresh"
            :label="t('editor.upload.retry')"
            :aria-label="t('editor.upload.retryFor', { name: e.name })"
            @click="uploads.retry(e.key)"
          />
          <q-btn
            v-if="e.status === 'error' || e.status === 'cancelled' || e.status === 'added'"
            flat
            dense
            round
            icon="r_close"
            :aria-label="t('editor.upload.dismiss', { name: e.name })"
            @click="uploads.dismiss(e.key)"
          />
        </span>
      </li>
    </ul>
    <div v-if="uploads.unassigned.value > 0 && !uploads.busy.value" class="swg-up__pending">
      <span>{{ t('editor.upload.unassigned', { count: uploads.unassigned.value }) }}</span>
      <q-btn
        unelevated
        no-caps
        color="primary"
        :label="t('editor.upload.assign')"
        @click="uploads.assignPending()"
      />
    </div>
    <q-btn
      v-if="finished.length > 1"
      flat
      dense
      no-caps
      size="sm"
      :label="t('editor.upload.clear')"
      @click="uploads.dismiss()"
    />
  </div>
</template>

<style scoped>
.swg-up {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.swg-up__list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.swg-up__row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto auto;
  align-items: center;
  gap: 4px 12px;
  padding: 6px 10px;
  border: 1px solid var(--swg-border);
  border-radius: var(--swg-radius-sm);
  background: var(--swg-surface);
  font-size: 13px;
}
.swg-up__row.is-error {
  border-color: var(--swg-danger);
  color: var(--swg-danger-text);
}
.swg-up__row.is-ok .swg-up__state {
  color: var(--swg-success-text);
}
.swg-up__bar {
  grid-column: 1 / -1;
}
.swg-up__pending {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  font-size: 14px;
}
</style>
