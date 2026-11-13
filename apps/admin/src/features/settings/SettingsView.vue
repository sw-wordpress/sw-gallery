<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { errorMessage } from '@/api/errorMessage';
import { ITEMS_PER_PAGE_MAX, ITEMS_PER_PAGE_MIN } from '@/api/settings';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import DataState from '@/components/ui/DataState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import SwgCard from '@/components/ui/SwgCard.vue';
import { useUnsavedGuard } from '@/features/editor/useUnsavedGuard';
import { useFeedback } from '@/features/galleries/feedback';
import { uid } from '@/utils/ids';
import { useSettingsFeatureApi, useSettingsForm } from './useSettingsForm';
import type { FieldProblem, SettingsField } from './useSettingsForm';

const { t } = useI18n();
const feedback = useFeedback();
const form = useSettingsForm(useSettingsFeatureApi());
const f = reactive(form);
void f.load();

const guard = useUnsavedGuard(() => form.dirty.value);
const guardState = reactive(guard);

const layoutId = uid('swg-set-layout');
const layoutHintId = uid('swg-set-layout-hint');
const deleteHintId = uid('swg-set-delete-hint');

const confirmOpen = ref(false);

const loadErrorText = computed(() => (f.loadError === null ? null : errorMessage(f.loadError, t)));
const loadErrorDetail = computed(() => {
  const error = f.loadError;
  return error === null
    ? undefined
    : error.status > 0
      ? `${error.code} (HTTP ${error.status})`
      : error.code;
});

/** A stored layout that is not registered (any more) still shows up so the select never lies. */
const layoutOptions = computed(() => {
  const options = f.layouts.map((layout) => ({ id: layout.id, label: layout.label }));
  if (!options.some((o) => o.id === f.draft.defaultLayout)) {
    options.unshift({
      id: f.draft.defaultLayout,
      label: t('settings.layoutUnavailable', { layout: f.draft.defaultLayout }),
    });
  }
  return options;
});
function defaultLayoutLabel(): string {
  const id = f.server?.defaults.defaultLayout ?? '';
  return f.layouts.find((l) => l.id === id)?.label ?? id;
}

function problemText(field: SettingsField): string | undefined {
  const problem: FieldProblem | undefined = f.problems[field];
  if (problem === undefined) return undefined;
  switch (problem) {
    case 'required':
      return t('settings.problem.required');
    case 'integer':
      return t('settings.problem.integer');
    case 'min':
      return t('settings.problem.min', { min: ITEMS_PER_PAGE_MIN });
    case 'max':
      return t('settings.problem.max', { max: ITEMS_PER_PAGE_MAX });
    case 'layout':
      return t('settings.problem.layout');
    default:
      return t('settings.problem.server');
  }
}

function onLayout(event: Event): void {
  f.setLayout((event.target as HTMLSelectElement).value);
}
function onDeleteToggle(on: boolean): void {
  if (on) confirmOpen.value = true;
  else f.setDeleteData(false, false);
}
function confirmDelete(): void {
  f.setDeleteData(true, true);
  confirmOpen.value = false;
}

const saveErrorText = computed(() => {
  const error = f.saveError;
  if (error === null) return null;
  if (error.code === 'swg_confirmation_required') return t('settings.problem.confirmation');
  if (error.code === 'swg_unknown_layout') return t('settings.problem.unknownLayout');
  return errorMessage(error, t);
});

async function save(): Promise<void> {
  const saved = await form.save();
  if (saved !== null) feedback.notify(t('settings.saved'));
}
</script>

<template>
  <div class="swg-settings">
    <PageHeader :title="t('nav.settings')" :subtitle="t('settings.subtitle')" />

    <DataState
      :loading="f.loading"
      :error="loadErrorText"
      :error-detail="loadErrorDetail"
      :empty="f.server === null"
      :error-title="t('settings.errorTitle')"
      :retry-label="t('common.retry')"
      :loading-label="t('settings.loading')"
      variant="block"
      @retry="f.load()"
    >
      <form class="swg-settings__form" novalidate @submit.prevent="save">
        <div v-if="saveErrorText" class="swg-settings__error" role="alert">
          <q-icon name="r_error" size="20px" aria-hidden="true" />
          <span>{{ saveErrorText }}</span>
        </div>

        <SwgCard
          :title="t('settings.display.title')"
          :subtitle="t('settings.display.subtitle')"
          icon="r_tune"
        >
          <div class="swg-settings__fields">
            <div class="swg-settings__field">
              <label :for="layoutId" class="swg-settings__label">{{
                t('settings.defaultLayout.label')
              }}</label>
              <select
                :id="layoutId"
                class="swg-settings__select"
                :value="f.draft.defaultLayout"
                :aria-invalid="f.problems.defaultLayout ? 'true' : 'false'"
                :aria-describedby="layoutHintId"
                @change="onLayout"
              >
                <option v-for="o in layoutOptions" :key="o.id" :value="o.id">{{ o.label }}</option>
              </select>
              <p :id="layoutHintId" class="swg-settings__hint">
                {{ t('settings.defaultLayout.hint') }}
                {{ t('settings.default', { value: defaultLayoutLabel() }) }}
              </p>
              <p v-if="problemText('defaultLayout')" class="swg-settings__problem" role="alert">
                {{ problemText('defaultLayout') }}
              </p>
              <q-btn
                v-if="f.server && f.draft.defaultLayout !== f.server.defaults.defaultLayout"
                flat
                dense
                no-caps
                color="primary"
                :label="t('settings.reset')"
                @click="f.resetToDefault('defaultLayout')"
              />
            </div>

            <div class="swg-settings__field">
              <q-input
                :model-value="f.itemsPerPageText"
                type="number"
                outlined
                dense
                autocomplete="off"
                :label="t('settings.itemsPerPage.label')"
                :error="problemText('itemsPerPage') !== undefined"
                :error-message="problemText('itemsPerPage')"
                :hint="
                  t('settings.itemsPerPage.hint', {
                    min: ITEMS_PER_PAGE_MIN,
                    max: ITEMS_PER_PAGE_MAX,
                    value: f.server?.defaults.itemsPerPage ?? '',
                  })
                "
                @update:model-value="f.setItemsPerPage"
              />
              <q-btn
                v-if="f.server && f.draft.itemsPerPage !== f.server.defaults.itemsPerPage"
                flat
                dense
                no-caps
                color="primary"
                :label="t('settings.reset')"
                @click="f.resetToDefault('itemsPerPage')"
              />
            </div>

            <div class="swg-settings__field">
              <q-toggle
                v-model="f.draft.lightbox"
                :label="t('settings.lightbox.label')"
                color="primary"
              />
              <p class="swg-settings__hint">
                {{ t('settings.lightbox.hint') }}
                {{
                  t('settings.default', {
                    value: f.server?.defaults.lightbox ? t('settings.on') : t('settings.off'),
                  })
                }}
              </p>
            </div>
          </div>
        </SwgCard>

        <SwgCard
          :title="t('settings.data.title')"
          :subtitle="t('settings.data.subtitle')"
          icon="r_database"
        >
          <div class="swg-settings__fields">
            <div class="swg-settings__field">
              <q-toggle
                :model-value="f.draft.deleteDataOnUninstall"
                :label="t('settings.data.label')"
                color="negative"
                :aria-describedby="deleteHintId"
                @update:model-value="onDeleteToggle"
              />
              <p :id="deleteHintId" class="swg-settings__hint">
                {{ f.server?.deleteDataNotice }}
              </p>
              <p class="swg-settings__hint swg-settings__safe">
                <q-icon name="r_verified_user" size="18px" aria-hidden="true" />
                {{ t('settings.data.attachmentsSafe') }}
              </p>
              <p class="swg-settings__hint">
                {{
                  t('settings.default', {
                    value: f.server?.defaults.deleteDataOnUninstall
                      ? t('settings.on')
                      : t('settings.off'),
                  })
                }}
              </p>
            </div>
          </div>
        </SwgCard>

        <div class="swg-settings__actions">
          <q-btn
            unelevated
            no-caps
            color="primary"
            type="submit"
            icon="r_save"
            :label="t('settings.save')"
            :loading="f.saving"
            :disable="!f.dirty || !f.valid"
          />
          <q-btn
            flat
            no-caps
            :label="t('settings.discard')"
            :disable="!f.dirty || f.saving"
            @click="f.discard()"
          />
          <span v-if="f.dirty" class="swg-muted" role="status">{{ t('settings.unsaved') }}</span>
        </div>
      </form>
    </DataState>

    <ConfirmDialog
      v-model="confirmOpen"
      :title="t('settings.confirm.title')"
      :message="f.server?.deleteDataNotice"
      :targets="[]"
      :targets-label="t('settings.confirm.affects')"
      :consequences="[t('settings.confirm.galleries'), t('settings.confirm.attachments')]"
      :confirm-label="t('settings.confirm.confirm')"
      :cancel-label="t('settings.confirm.cancel')"
      :require-text="t('settings.confirm.word')"
      :require-text-label="t('settings.confirm.typeLabel', { word: t('settings.confirm.word') })"
      @confirm="confirmDelete"
    />
    <ConfirmDialog
      v-model="guardState.open"
      :title="t('settings.leave.title')"
      :message="t('settings.leave.message')"
      :targets="[]"
      :targets-label="t('settings.confirm.affects')"
      :confirm-label="t('settings.leave.confirm')"
      :cancel-label="t('settings.leave.cancel')"
      :danger="false"
      @confirm="guard.confirmLeave"
    />
  </div>
</template>

<style scoped>
.swg-settings__form {
  display: flex;
  flex-direction: column;
  gap: 20px;
  max-inline-size: 760px;
}
.swg-settings__fields {
  display: flex;
  flex-direction: column;
  gap: 22px;
}
.swg-settings__field {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 6px;
}
.swg-settings__field > .q-field {
  inline-size: min(100%, 320px);
}
.swg-settings__label {
  font-weight: 600;
}
.swg-settings__select {
  inline-size: min(100%, 320px);
  min-block-size: 40px;
  padding: 0 12px;
  border: 1px solid var(--swg-border);
  border-radius: var(--swg-radius-sm);
  background: var(--swg-surface);
  color: var(--swg-text);
  font: inherit;
}
.swg-settings__hint {
  font-size: 13px;
  color: var(--swg-muted);
  max-inline-size: 62ch;
}
.swg-settings__safe {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--swg-success-text);
}
.swg-settings__problem {
  font-size: 13px;
  color: var(--swg-danger-text);
}
.swg-settings__error {
  display: flex;
  gap: 8px;
  align-items: flex-start;
  padding: 10px 14px;
  border-radius: var(--swg-radius-sm);
  background: var(--swg-danger-bg);
  color: var(--swg-danger-text);
}
.swg-settings__actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}
</style>
