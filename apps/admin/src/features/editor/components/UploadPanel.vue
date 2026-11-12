<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ACCEPTED_TYPES } from '../useUploads';
import type { UploadEntry, Uploads } from '../useUploads';

/**
 * Upload zone (M2-28): dashed drop target with a file button, per-file progress (real XHR bytes),
 * cancel, retry and a dismiss action. The drop handlers live on the editor section; this panel
 * only shows `dragging` state and the list.
 */
const props = defineProps<{
  uploads: Uploads;
  dragging: boolean;
  disabled: boolean;
  reason: string | null;
}>();
const { t } = useI18n();
const input = ref<HTMLInputElement | null>(null);
const accept = ACCEPTED_TYPES.join(',');

const entries = computed(() => props.uploads.entries.value);
const finished = computed(() =>
  entries.value.filter((e) => ['error', 'cancelled', 'added'].includes(e.status)),
);

function pick(): void {
  input.value?.click();
}
function onPicked(event: Event): void {
  const el = event.target as HTMLInputElement;
  props.uploads.add(Array.from(el.files ?? []));
  el.value = '';
}

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
    <div class="swg-up__zone" :class="{ 'is-over': dragging, 'is-disabled': disabled }">
      <q-icon name="r_cloud_upload" size="28px" aria-hidden="true" />
      <div class="swg-up__text">
        <strong>{{ dragging ? t('editor.upload.dropNow') : t('editor.upload.zone') }}</strong>
        <span v-if="reason" class="swg-muted">{{ reason }}</span>
        <span v-else class="swg-muted">{{ t('editor.upload.hint') }}</span>
      </div>
      <q-btn
        outline
        no-caps
        color="primary"
        icon="r_add_photo_alternate"
        :label="t('editor.upload.button')"
        :disable="disabled"
        @click="pick"
      />
      <input
        ref="input"
        class="swg-sr-only"
        type="file"
        multiple
        tabindex="-1"
        aria-hidden="true"
        :accept="accept"
        @change="onPicked"
      />
    </div>

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
.swg-up__zone {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  padding: 10px 14px;
  border: 2px dashed var(--swg-border);
  border-radius: var(--swg-radius);
  background: var(--swg-surface-2);
  color: var(--swg-text);
}
.swg-up__zone.is-over {
  border-color: var(--swg-primary);
  background: var(--swg-primary-soft);
}
.swg-up__zone.is-disabled {
  opacity: 0.8;
}
.swg-up__text {
  display: flex;
  flex-direction: column;
  flex: 1 1 200px;
  font-size: 14px;
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
