<script setup lang="ts">
import { nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { uid } from '@/utils/ids';

/** Fallback when the Clipboard API is unavailable: the shortcode is shown selected, ready for Ctrl+C. */
const open = defineModel<boolean>({ required: true });
defineProps<{ shortcode: string }>();
const { t } = useI18n();
const titleId = uid('swg-sc');
const input = ref<HTMLInputElement | null>(null);

async function selectAll(): Promise<void> {
  await nextTick();
  input.value?.focus();
  input.value?.select();
}
</script>

<template>
  <q-dialog
    v-model="open"
    role="dialog"
    aria-modal="true"
    :aria-labelledby="titleId"
    @show="selectAll"
  >
    <q-card class="swg-scd">
      <q-card-section>
        <h2 :id="titleId" class="swg-scd__title">{{ t('galleries.shortcode.title') }}</h2>
        <p class="swg-muted">{{ t('galleries.shortcode.text') }}</p>
      </q-card-section>
      <q-card-section>
        <label class="swg-scd__label">
          <span>{{ t('galleries.shortcode.label') }}</span>
          <input
            ref="input"
            class="swg-scd__input swg-mono"
            type="text"
            readonly
            :value="shortcode"
            @focus="input?.select()"
          />
        </label>
      </q-card-section>
      <q-card-actions align="right">
        <q-btn flat no-caps :label="t('galleries.shortcode.close')" @click="open = false" />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<style scoped>
.swg-scd {
  width: min(480px, calc(100vw - 24px));
  max-width: none;
}
.swg-scd__title {
  font-size: 18px;
  font-weight: 650;
  margin-block-end: 6px;
}
.swg-scd__label {
  display: flex !important;
  flex-direction: column;
  gap: 6px;
  font-size: 13px;
  font-weight: 600;
}
.swg-scd__input {
  inline-size: 100%;
  padding: 10px 12px;
  border: 1px solid var(--swg-border);
  border-radius: var(--swg-radius-sm);
  background: var(--swg-surface-2);
  color: var(--swg-text);
  font-size: 15px;
}
</style>
