<script setup lang="ts">
import SideSheet from './SideSheet.vue';
import { uid } from '@/utils/ids';

/** Side sheet around a form: submit/cancel footer, a server error summary above the fields. */
const open = defineModel<boolean>({ required: true });
withDefaults(
  defineProps<{
    title: string;
    subtitle?: string;
    submitLabel: string;
    cancelLabel: string;
    closeLabel: string;
    loading?: boolean;
    readonly?: boolean;
    /** Translated summary of the last failed submit. */
    errorMessage?: string | null;
    /** Per-field messages of the last failed submit (field label -> message). */
    fieldErrors?: Record<string, string>;
    width?: number;
  }>(),
  {
    subtitle: undefined,
    loading: false,
    readonly: false,
    errorMessage: null,
    fieldErrors: () => ({}),
    width: 760,
  },
);
const emit = defineEmits<{
  (e: 'submit'): void;
  /** Emits the `data-tab` value of the first invalid field's tab container (if any). */
  (e: 'invalid-tab', tab: string): void;
}>();
const formId = uid('swg-form');

function onValidationError(component: { $el?: HTMLElement } | undefined): void {
  const tab = component?.$el?.closest<HTMLElement>('[data-tab]')?.dataset.tab;
  if (tab) emit('invalid-tab', tab);
}
</script>

<template>
  <SideSheet
    v-model="open"
    :title="title"
    :subtitle="subtitle"
    :close-label="closeLabel"
    :width="width"
    persistent
  >
    <q-form :id="formId" greedy @submit="emit('submit')" @validation-error="onValidationError">
      <div v-if="errorMessage" class="swg-fe" role="alert">
        <q-icon name="r_error" size="20px" aria-hidden="true" />
        <div>
          <strong>{{ errorMessage }}</strong>
          <ul v-if="Object.keys(fieldErrors).length > 0">
            <li v-for="(msg, field) in fieldErrors" :key="field">
              <strong>{{ field }}</strong> {{ msg }}
            </li>
          </ul>
        </div>
      </div>
      <slot />
    </q-form>
    <template #footer>
      <q-btn flat no-caps :label="cancelLabel" :disable="loading" @click="open = false" />
      <q-btn
        v-if="!readonly"
        unelevated
        no-caps
        color="primary"
        type="submit"
        :form="formId"
        :loading="loading"
        :label="submitLabel"
      />
    </template>
  </SideSheet>
</template>

<style scoped>
.swg-fe {
  display: flex;
  gap: 10px;
  margin-top: 16px;
  padding: 12px 14px;
  border-radius: var(--swg-radius-sm);
  background: var(--swg-danger-bg);
  color: var(--swg-danger-text);
  font-size: 14px;
}
.swg-fe ul {
  margin-top: 4px;
}
</style>
