<script setup lang="ts" generic="T extends string">
import { ref } from 'vue';

/**
 * WAI-ARIA tabs (automatic activation): roving tabindex, Left/Right/Home/End (mirrored in RTL).
 * Focus stays on the tab that was activated; the panel is the next Tab stop. The consumer renders
 * the panels with the matching ids.
 */
const model = defineModel<T>({ required: true });
const props = defineProps<{
  options: Array<{ value: T; label: string }>;
  label: string;
  /** Ids are `<idBase>-tab-<value>` and `<idBase>-panel-<value>`; the consumer renders the panels. */
  idBase: string;
}>();
const tabs = ref<HTMLElement[]>([]);

const tabId = (value: string): string => `${props.idBase}-tab-${value}`;
const panelId = (value: string): string => `${props.idBase}-panel-${value}`;

function isRtl(): boolean {
  return document.documentElement.getAttribute('dir') === 'rtl';
}

function onKey(event: KeyboardEvent, index: number): void {
  const last = props.options.length - 1;
  const forward = isRtl() ? 'ArrowLeft' : 'ArrowRight';
  const backward = isRtl() ? 'ArrowRight' : 'ArrowLeft';
  let next = -1;
  if (event.key === forward) next = index === last ? 0 : index + 1;
  else if (event.key === backward) next = index === 0 ? last : index - 1;
  else if (event.key === 'Home') next = 0;
  else if (event.key === 'End') next = last;
  const target = props.options[next];
  if (target === undefined) return;
  event.preventDefault();
  model.value = target.value;
  tabs.value[next]?.focus();
}
</script>

<template>
  <div class="swg-vt" role="tablist" :aria-label="label">
    <button
      v-for="(option, index) in options"
      :id="tabId(option.value)"
      :key="option.value"
      ref="tabs"
      type="button"
      role="tab"
      class="swg-vt__tab"
      :class="{ 'is-on': model === option.value }"
      :aria-selected="model === option.value"
      :aria-controls="panelId(option.value)"
      :tabindex="model === option.value ? 0 : -1"
      @click="model = option.value"
      @keydown="onKey($event, index)"
    >
      {{ option.label }}
    </button>
  </div>
</template>

<style scoped>
.swg-vt {
  display: inline-flex;
  gap: 2px;
  padding: 4px;
  border-radius: 10px;
  background: var(--swg-surface-3);
  max-inline-size: 100%;
}
.swg-vt__tab {
  block-size: 32px;
  padding-inline: 16px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: var(--swg-muted);
  font: inherit;
  font-size: 14px;
  cursor: pointer;
  white-space: nowrap;
}
.swg-vt__tab:hover {
  color: var(--swg-text);
}
.swg-vt__tab.is-on {
  background: var(--swg-surface);
  color: var(--swg-text);
  font-weight: 600;
  box-shadow: var(--swg-shadow-1);
}
.swg-vt__tab:focus-visible {
  outline: 2px solid var(--swg-focus);
  outline-offset: 2px;
}
</style>
