<script setup lang="ts">
import { computed } from 'vue';

export type ChipKind =
  'success' | 'warning' | 'danger' | 'info' | 'neutral' | 'running' | 'primary';

/** Icon per kind: status is never conveyed by colour alone. */
const ICONS: Record<ChipKind, string> = {
  success: 'r_check_circle',
  warning: 'r_warning',
  danger: 'r_error',
  info: 'r_info',
  neutral: 'r_circle',
  running: 'r_autorenew',
  primary: 'r_circle',
};

const props = withDefaults(
  defineProps<{
    label: string;
    kind?: ChipKind;
    /** Overrides the default icon of the kind. */
    icon?: string;
    dense?: boolean;
  }>(),
  { kind: 'neutral', icon: undefined, dense: false },
);

const iconName = computed(() => props.icon ?? ICONS[props.kind]);
</script>

<template>
  <span class="swg-chip" :class="[`swg-chip--${kind}`, { 'swg-chip--dense': dense }]">
    <q-icon :name="iconName" class="swg-chip__icon" size="1.15em" aria-hidden="true" />
    <span class="swg-chip__text">{{ label }}</span>
  </span>
</template>

<style scoped>
.swg-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 10px 3px 8px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 600;
  line-height: 1.4;
  white-space: nowrap;
  max-width: 100%;
  background: var(--swg-neutral-bg);
  color: var(--swg-neutral-text);
  border: 1px solid transparent;
}
.swg-chip--dense {
  font-size: 12px;
  padding: 1px 8px 1px 6px;
}
.swg-chip__text {
  overflow: hidden;
  text-overflow: ellipsis;
}
.swg-chip--success {
  background: var(--swg-success-bg);
  color: var(--swg-success-text);
}
.swg-chip--warning {
  background: var(--swg-warning-bg);
  color: var(--swg-warning-text);
}
.swg-chip--danger {
  background: var(--swg-danger-bg);
  color: var(--swg-danger-text);
}
.swg-chip--info {
  background: var(--swg-info-bg);
  color: var(--swg-info-text);
}
.swg-chip--primary,
.swg-chip--running {
  background: var(--swg-primary-bg);
  color: var(--swg-primary-text);
}
.swg-chip--running .swg-chip__icon {
  animation: swg-spin 1.6s linear infinite;
}
</style>
