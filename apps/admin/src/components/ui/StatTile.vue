<script setup lang="ts">
withDefaults(
  defineProps<{
    label: string;
    value?: string;
    hint?: string;
    icon?: string;
    loading?: boolean;
    /** Accessible name of the loading state (the skeleton itself is hidden from assistive technology). */
    loadingLabel?: string;
    tone?: 'default' | 'success' | 'warning' | 'danger';
  }>(),
  {
    value: undefined,
    hint: undefined,
    icon: undefined,
    loading: false,
    loadingLabel: undefined,
    tone: 'default',
  },
);
</script>

<template>
  <div class="swg-stat" :class="`swg-stat--${tone}`" :aria-busy="loading ? 'true' : undefined">
    <div class="swg-stat__top">
      <span class="swg-stat__label">{{ label }}</span>
      <q-icon v-if="icon" :name="icon" size="20px" class="swg-stat__icon" aria-hidden="true" />
    </div>
    <template v-if="loading">
      <span v-if="loadingLabel" class="swg-sr-only" role="status">{{ loadingLabel }}</span>
      <q-skeleton type="text" width="60%" height="32px" aria-hidden="true" />
      <q-skeleton type="text" width="40%" aria-hidden="true" />
    </template>
    <template v-else>
      <div class="swg-stat__value swg-num">
        <slot>{{ value ?? '–' }}</slot>
      </div>
      <div v-if="hint || $slots.hint" class="swg-stat__hint">
        <slot name="hint">{{ hint }}</slot>
      </div>
    </template>
  </div>
</template>

<style scoped>
.swg-stat {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 18px 20px;
  background: var(--swg-surface);
  border: 1px solid var(--swg-border);
  border-radius: var(--swg-radius);
  box-shadow: var(--swg-shadow-1);
  min-width: 0;
}
.swg-stat__top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}
.swg-stat__label {
  font-size: 13px;
  font-weight: 600;
  color: var(--swg-muted);
}
.swg-stat__icon {
  color: var(--swg-primary);
}
.swg-stat__value {
  font-size: 26px;
  font-weight: 700;
  line-height: 1.2;
  letter-spacing: -0.01em;
  overflow-wrap: anywhere;
}
.swg-stat__hint {
  font-size: 13px;
  color: var(--swg-muted);
}
.swg-stat--success .swg-stat__icon {
  color: var(--swg-success);
}
.swg-stat--warning .swg-stat__icon {
  color: var(--swg-warning);
}
.swg-stat--danger .swg-stat__icon {
  color: var(--swg-danger);
}
</style>
