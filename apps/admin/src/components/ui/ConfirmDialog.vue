<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { uid } from '@/utils/ids';

/**
 * Confirmation for irreversible or bulk actions. It must name the affected objects (`targets`);
 * "Cancel" has the initial focus so Enter never confirms by accident. Texts come from the caller.
 */
const open = defineModel<boolean>({ required: true });
const props = withDefaults(
  defineProps<{
    title: string;
    message?: string;
    /** Names of the affected objects (galleries, attachments, ...). Shown as a list. */
    targets: string[];
    /** Heading of the target list, e.g. "Affects". */
    targetsLabel: string;
    consequences?: string[];
    confirmLabel: string;
    cancelLabel: string;
    danger?: boolean;
    loading?: boolean;
    /** If set, the user must type exactly this text to enable the confirm button. */
    requireText?: string;
    /** Label of the type-to-confirm input (required when `requireText` is set). */
    requireTextLabel?: string;
    /** Error of the last attempt (e.g. a 409), shown inside the dialog. */
    error?: string | null;
  }>(),
  {
    message: undefined,
    consequences: () => [],
    danger: true,
    loading: false,
    requireText: undefined,
    requireTextLabel: undefined,
    error: null,
  },
);
const emit = defineEmits<{ (e: 'confirm'): void }>();

const typed = ref('');
const titleId = uid('swg-cd');
const ready = computed(
  () => props.requireText === undefined || typed.value.trim() === props.requireText,
);

watch(open, (value) => {
  if (value) typed.value = '';
});

function confirm(): void {
  if (ready.value && !props.loading) emit('confirm');
}
</script>

<template>
  <q-dialog
    v-model="open"
    persistent
    role="alertdialog"
    aria-modal="true"
    :aria-labelledby="titleId"
    @keyup.esc="open = false"
  >
    <q-card class="swg-cd">
      <q-card-section class="swg-cd__head">
        <span class="swg-cd__icon" :class="{ 'swg-cd__icon--danger': danger }" aria-hidden="true">
          <q-icon :name="danger ? 'r_warning' : 'r_help'" size="24px" />
        </span>
        <div>
          <h2 :id="titleId" class="swg-cd__title">{{ title }}</h2>
          <p v-if="message" class="swg-cd__msg">{{ message }}</p>
        </div>
      </q-card-section>
      <q-card-section class="swg-cd__body">
        <div v-if="targets.length > 0" class="swg-cd__targets">
          <span class="swg-eyebrow">{{ targetsLabel }}</span>
          <ul class="swg-cd__target-list">
            <li v-for="name in targets" :key="name" class="swg-truncate">
              <strong>{{ name }}</strong>
            </li>
          </ul>
        </div>
        <ul v-if="consequences.length > 0" class="swg-cd__list">
          <li v-for="item in consequences" :key="item">
            <q-icon name="r_chevron_right" size="18px" class="swg-flip-rtl" aria-hidden="true" />{{
              item
            }}
          </li>
        </ul>
        <q-input
          v-if="requireText !== undefined"
          v-model="typed"
          outlined
          dense
          autocomplete="off"
          :label="requireTextLabel"
          @keyup.enter="confirm"
        />
        <p v-if="error" class="swg-cd__error" role="alert">
          <q-icon name="r_error" size="18px" aria-hidden="true" /> {{ error }}
        </p>
        <slot />
      </q-card-section>
      <q-card-actions align="right" class="swg-cd__actions">
        <q-btn
          flat
          no-caps
          :label="cancelLabel"
          :disable="loading"
          data-autofocus
          @click="open = false"
        />
        <q-btn
          unelevated
          no-caps
          :color="danger ? 'negative' : 'primary'"
          :label="confirmLabel"
          :loading="loading"
          :disable="!ready"
          @click="confirm"
        />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<style scoped>
.swg-cd {
  width: min(520px, calc(100vw - 24px));
  max-width: none;
}
.swg-cd__head {
  display: flex;
  gap: 14px;
  align-items: flex-start;
}
.swg-cd__icon {
  display: grid;
  place-items: center;
  flex: none;
  width: 44px;
  height: 44px;
  border-radius: 12px;
  background: var(--swg-primary-bg);
  color: var(--swg-primary-text);
}
.swg-cd__icon--danger {
  background: var(--swg-danger-bg);
  color: var(--swg-danger-text);
}
.swg-cd__title {
  font-size: 18px;
  font-weight: 700;
  line-height: 1.3;
}
.swg-cd__msg {
  margin-top: 4px;
  color: var(--swg-muted);
}
.swg-cd__body {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding-top: 0;
}
.swg-cd__targets {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 10px 14px;
  border-radius: var(--swg-radius-sm);
  background: var(--swg-surface-2);
  border: 1px solid var(--swg-border);
}
.swg-cd__target-list {
  max-height: 160px;
  overflow-y: auto;
}
.swg-cd__list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 14px;
}
.swg-cd__list li {
  display: flex;
  gap: 4px;
  align-items: flex-start;
}
.swg-cd__error {
  display: flex;
  gap: 6px;
  align-items: flex-start;
  padding: 10px 12px;
  border-radius: var(--swg-radius-sm);
  background: var(--swg-danger-bg);
  color: var(--swg-danger-text);
  font-size: 14px;
}
.swg-cd__actions {
  padding: 8px 16px 16px;
}
</style>
