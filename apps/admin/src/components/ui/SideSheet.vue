<script setup lang="ts">
import { uid } from '@/utils/ids';

/** Right-hand dialog sheet (edit panels). Focus is trapped and restored by Quasar's dialog. */
const open = defineModel<boolean>({ required: true });
withDefaults(
  defineProps<{
    title: string;
    subtitle?: string;
    closeLabel: string;
    width?: number;
    persistent?: boolean;
  }>(),
  { subtitle: undefined, width: 720, persistent: false },
);
const emit = defineEmits<{ (e: 'hide'): void }>();
const titleId = uid('swg-sheet');
</script>

<template>
  <q-dialog
    v-model="open"
    position="right"
    full-height
    :persistent="persistent"
    :maximized="false"
    role="dialog"
    aria-modal="true"
    :aria-labelledby="titleId"
    @hide="emit('hide')"
  >
    <q-card class="swg-sheet" :style="{ '--sheet-w': `${width}px` }">
      <div class="swg-sheet__head">
        <div class="swg-sheet__titles">
          <h2 :id="titleId" class="swg-sheet__title swg-truncate">{{ title }}</h2>
          <p v-if="subtitle" class="swg-sheet__subtitle swg-truncate">{{ subtitle }}</p>
        </div>
        <q-btn
          flat
          round
          dense
          icon="r_close"
          class="swg-sheet__close"
          :aria-label="closeLabel"
          @click="open = false"
        />
      </div>
      <!-- Scrollable: keyboard users must be able to focus and scroll it, also when it holds nothing focusable (WCAG 2.1.1). -->
      <div class="swg-sheet__body" role="region" tabindex="0" :aria-label="title"><slot /></div>
      <div v-if="$slots.footer" class="swg-sheet__foot"><slot name="footer" /></div>
    </q-card>
  </q-dialog>
</template>

<style scoped>
.swg-sheet {
  width: min(var(--sheet-w), 100vw);
  max-width: 100vw;
  height: 100%;
  display: flex;
  flex-direction: column;
  border-start-start-radius: var(--swg-radius);
  border-end-start-radius: var(--swg-radius);
  border-start-end-radius: 0;
  border-end-end-radius: 0;
}
.swg-sheet__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 18px 22px;
  border-bottom: 1px solid var(--swg-border);
  background: var(--swg-gradient-soft);
}
.swg-sheet__titles {
  min-width: 0;
}
.swg-sheet__title {
  font-size: 19px;
  font-weight: 700;
  letter-spacing: -0.01em;
}
.swg-sheet__subtitle {
  font-size: 13px;
  color: var(--swg-muted);
}
.swg-sheet__body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 8px 22px 22px;
}
.swg-sheet__foot {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  flex-wrap: wrap;
  padding: 14px 22px;
  border-top: 1px solid var(--swg-border);
  background: var(--swg-surface);
}
@media (max-width: 600px) {
  .swg-sheet {
    border-radius: 0;
  }
  .swg-sheet__head,
  .swg-sheet__foot {
    padding-inline: 16px;
  }
  .swg-sheet__body {
    padding-inline: 16px;
  }
}
</style>
