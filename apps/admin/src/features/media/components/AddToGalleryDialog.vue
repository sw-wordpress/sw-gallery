<script setup lang="ts">
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import { errorMessage } from '@/api/errorMessage';
import EmptyState from '@/components/ui/EmptyState.vue';
import { uid } from '@/utils/ids';
import { GALLERY_CHOICES } from '../useAddToGallery';
import type { AddToGallery } from '../useAddToGallery';

/** Target picker for "Add to gallery": searchable list of the galleries the user can edit. */
const props = defineProps<{ flow: AddToGallery }>();
/** Unwrapped view of the flow's refs (stable object, provided by the page). */
const f = reactive(props.flow);
const { t } = useI18n();
const titleId = uid('swg-atg');
const groupId = uid('swg-atg-list');

const count = computed(() => f.attachmentIds.length);
const loadError = computed(() => (f.loadError === null ? null : errorMessage(f.loadError, t)));
const submitError = computed(() => (f.error === null ? null : errorMessage(f.error, t)));
const truncated = computed(() => f.total > GALLERY_CHOICES);

function onSearch(value: string | number | null): void {
  f.setSearch(value === null ? '' : String(value));
}
function title(name: string): string {
  return name === '' ? t('galleries.card.untitled') : name;
}
</script>

<template>
  <q-dialog
    v-model="f.open"
    persistent
    role="dialog"
    aria-modal="true"
    :aria-labelledby="titleId"
    @keyup.esc="f.open = false"
  >
    <q-card class="swg-atg">
      <q-card-section class="swg-atg__head">
        <h2 :id="titleId" class="swg-atg__title">{{ t('media.add.title') }}</h2>
        <p class="swg-muted">{{ t('media.add.subtitle', { count }, count) }}</p>
      </q-card-section>
      <q-card-section class="swg-atg__body">
        <q-input
          :model-value="f.search"
          type="search"
          outlined
          dense
          autocomplete="off"
          data-autofocus
          :label="t('media.add.search')"
          @update:model-value="onSearch"
        >
          <template #prepend><q-icon name="r_search" aria-hidden="true" /></template>
        </q-input>

        <div v-if="f.loading" class="swg-atg__state" role="status">
          {{ t('media.add.loading') }}
        </div>
        <div v-else-if="loadError" class="swg-atg__state" role="alert">
          <span>{{ loadError }}</span>
          <q-btn
            flat
            dense
            no-caps
            color="primary"
            :label="t('common.retry')"
            @click="f.reload()"
          />
        </div>
        <EmptyState
          v-else-if="f.choices.length === 0"
          compact
          illustration="search"
          :title="t('media.add.noneTitle')"
          :text="t('media.add.noneText')"
        />
        <fieldset v-else :id="groupId" class="swg-atg__list">
          <legend class="swg-sr-only">{{ t('media.add.listLabel') }}</legend>
          <label
            v-for="gallery in f.choices"
            :key="gallery.id"
            class="swg-atg__opt"
            :class="{ 'is-on': f.selectedId === gallery.id }"
          >
            <input
              v-model="f.selectedId"
              type="radio"
              name="swg-atg-target"
              :value="gallery.id"
              v-bind="{ 'data-gallery-id': gallery.id }"
            />
            <span class="swg-atg__name swg-truncate">{{ title(gallery.title) }}</span>
            <span class="swg-muted swg-num">{{
              t('galleries.card.images', { count: gallery.itemCount }, gallery.itemCount)
            }}</span>
          </label>
        </fieldset>
        <p v-if="truncated && !f.loading" class="swg-muted swg-atg__more">
          {{ t('media.add.refine', { shown: f.choices.length, total: f.total }) }}
        </p>
        <p v-if="submitError" class="swg-atg__error" role="alert">
          <q-icon name="r_error" size="18px" aria-hidden="true" /> {{ submitError }}
        </p>
      </q-card-section>
      <q-card-actions align="right" class="swg-atg__actions">
        <q-btn
          flat
          no-caps
          :label="t('media.add.cancel')"
          :disable="f.submitting"
          @click="f.open = false"
        />
        <q-btn
          unelevated
          no-caps
          color="primary"
          :label="t('media.add.submit', { count }, count)"
          :loading="f.submitting"
          :disable="f.selectedId === null"
          @click="f.submit()"
        />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<style scoped>
.swg-atg {
  inline-size: min(520px, calc(100vw - 24px));
  max-inline-size: none;
}
.swg-atg__title {
  font-size: 18px;
  font-weight: 700;
}
.swg-atg__body {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding-block-start: 0;
}
.swg-atg__state {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 0;
  color: var(--swg-muted);
}
.swg-atg__list {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin: 0;
  padding: 0;
  border: 0;
  max-block-size: 300px;
  overflow-y: auto;
}
.swg-atg__opt {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: 10px;
  min-block-size: 44px;
  padding: 6px 12px;
  border: 1px solid var(--swg-border);
  border-radius: var(--swg-radius-sm);
  cursor: pointer;
}
.swg-atg__opt.is-on {
  border-color: var(--swg-primary);
  background: var(--swg-primary-soft);
}
.swg-atg__opt input {
  inline-size: 18px;
  block-size: 18px;
  accent-color: var(--swg-primary);
}
.swg-atg__name {
  font-weight: 600;
}
.swg-atg__more {
  font-size: 13px;
}
.swg-atg__error {
  display: flex;
  gap: 6px;
  align-items: flex-start;
  padding: 10px 12px;
  border-radius: var(--swg-radius-sm);
  background: var(--swg-danger-bg);
  color: var(--swg-danger-text);
  font-size: 14px;
}
.swg-atg__actions {
  padding: 8px 16px 16px;
}
</style>
