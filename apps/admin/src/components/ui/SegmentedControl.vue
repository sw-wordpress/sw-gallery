<script setup lang="ts" generic="T extends string">
import { uid } from '@/utils/ids';

/** Radio group styled as a segmented control (native radios: arrow keys, labels, focus ring). */
const model = defineModel<T | null>({ required: true });
defineProps<{ options: Array<{ value: T; label: string }>; legend: string; disabled?: boolean }>();
const name = uid('swg-seg');
</script>

<template>
  <fieldset class="swg-seg" :disabled="disabled">
    <legend class="swg-sr-only">{{ legend }}</legend>
    <label
      v-for="o in options"
      :key="o.value"
      class="swg-seg__opt"
      :class="{ 'is-on': model === o.value }"
    >
      <input v-model="model" class="swg-sr-only" type="radio" :name="name" :value="o.value" />
      <span>{{ o.label }}</span>
    </label>
  </fieldset>
</template>

<style scoped>
.swg-seg {
  display: inline-grid;
  grid-auto-flow: column;
  grid-auto-columns: 1fr;
  gap: 2px;
  margin: 0;
  padding: 4px;
  border: 0;
  border-radius: 10px;
  background: var(--swg-surface-3);
  max-width: 100%;
}
.swg-seg__opt {
  display: grid;
  place-items: center;
  height: 32px;
  padding: 0 16px;
  border-radius: 8px;
  color: var(--swg-muted);
  font-size: 14px;
  cursor: pointer;
  white-space: nowrap;
  transition:
    background-color 0.15s ease,
    color 0.15s ease;
}
.swg-seg__opt:hover {
  color: var(--swg-text);
}
.swg-seg__opt.is-on {
  background: var(--swg-surface);
  color: var(--swg-text);
  font-weight: 600;
  box-shadow: var(--swg-shadow-1);
}
.swg-seg__opt:focus-within {
  outline: 3px solid var(--swg-focus);
  outline-offset: 1px;
}
@media (max-width: 480px) {
  .swg-seg {
    display: grid;
    width: 100%;
  }
  .swg-seg__opt {
    padding: 0 6px;
  }
}
</style>
