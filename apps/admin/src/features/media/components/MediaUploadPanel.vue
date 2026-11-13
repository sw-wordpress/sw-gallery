<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
// Read-only dependency on the editor's upload queue (its pure constants and types are shared as they are).
import { ACCEPTED_TYPES } from '@/features/editor/useUploads';
import type { UploadEntry, Uploads } from '@/features/editor/useUploads';

/**
 * Upload zone of the Media Overview: file button plus the per-file list (real XHR progress,
 * cancel, retry, dismiss). The drop handlers live on the page section; this panel only shows the
 * `dragging` state.
 */
const props = defineProps<{ uploads: Uploads; dragging: boolean }>();
const { t } = useI18n();
const input = ref<HTMLInputElement | null>(null);
const accept = ACCEPTED_TYPES.join(',');
/** Hook for tests (the strict template check rejects a literal `data-*` attribute on native inputs). */
const fileAttrs: Record<string, string> = { 'data-testid': 'media-file-input' };
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
      return t('media.upload.status.queued');
    case 'uploading': {
      const p = percent(e);
      return p === null
        ? t('media.upload.status.uploadingUnknown')
        : t('media.upload.status.uploading', { percent: Math.round(p * 100) });
    }
    case 'done':
    case 'added':
      return t('media.upload.status.done');
    case 'cancelled':
      return t('media.upload.status.cancelled');
    default:
      return t(`media.upload.problem.${e.problem ?? 'other'}`);
  }
}
defineExpose({ pick });
</script>

<template>
  <div class="swg-mup">
    <div class="swg-mup__zone" :class="{ 'is-over': dragging }">
      <q-icon name="r_cloud_upload" size="28px" aria-hidden="true" />
      <div class="swg-mup__text">
        <strong>{{ dragging ? t('media.upload.dropNow') : t('media.upload.zone') }}</strong>
        <span class="swg-muted">{{ t('media.upload.hint') }}</span>
      </div>
      <q-btn
        outline
        no-caps
        color="primary"
        icon="r_add_photo_alternate"
        :label="t('media.upload.button')"
        @click="pick"
      />
      <input
        ref="input"
        class="swg-sr-only"
        type="file"
        multiple
        tabindex="-1"
        aria-hidden="true"
        v-bind="fileAttrs"
        :accept="accept"
        @change="onPicked"
      />
    </div>
    <ul v-if="entries.length > 0" class="swg-mup__list" :aria-label="t('media.upload.list')">
      <li
        v-for="e in entries"
        :key="e.key"
        class="swg-mup__row"
        :class="{ 'is-error': e.status === 'error' }"
        v-bind="{ 'data-upload-status': e.status }"
      >
        <span class="swg-mup__name swg-truncate">{{ e.name }}</span>
        <span class="swg-mup__state">{{ statusText(e) }}</span>
        <q-linear-progress
          v-if="e.status === 'uploading' || e.status === 'queued'"
          class="swg-mup__bar"
          :value="percent(e) ?? 0"
          :indeterminate="e.status === 'uploading' && percent(e) === null"
          :aria-label="t('media.upload.progressFor', { name: e.name })"
        />
        <span class="swg-mup__actions">
          <q-btn
            v-if="e.status === 'uploading' || e.status === 'queued'"
            flat
            dense
            round
            icon="r_close"
            :aria-label="t('media.upload.cancel', { name: e.name })"
            @click="uploads.cancel(e.key)"
          />
          <q-btn
            v-if="(e.status === 'error' || e.status === 'cancelled') && uploads.canRetry(e.key)"
            flat
            dense
            no-caps
            icon="r_refresh"
            :label="t('media.upload.retry')"
            :aria-label="t('media.upload.retryFor', { name: e.name })"
            @click="uploads.retry(e.key)"
          />
          <q-btn
            v-if="e.status === 'error' || e.status === 'cancelled' || e.status === 'added'"
            flat
            dense
            round
            icon="r_close"
            :aria-label="t('media.upload.dismiss', { name: e.name })"
            @click="uploads.dismiss(e.key)"
          />
        </span>
      </li>
    </ul>
    <q-btn
      v-if="finished.length > 1"
      flat
      dense
      no-caps
      size="sm"
      :label="t('media.upload.clear')"
      @click="uploads.dismiss()"
    />
  </div>
</template>

<style scoped>
.swg-mup {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-block-end: 20px;
}
.swg-mup__zone {
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
.swg-mup__zone.is-over {
  border-color: var(--swg-primary);
  background: var(--swg-primary-soft);
}
.swg-mup__text {
  display: flex;
  flex-direction: column;
  flex: 1 1 200px;
  font-size: 14px;
}
.swg-mup__list {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.swg-mup__row {
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
.swg-mup__row.is-error {
  border-color: var(--swg-danger);
  color: var(--swg-danger-text);
}
.swg-mup__bar {
  grid-column: 1 / -1;
}
</style>
