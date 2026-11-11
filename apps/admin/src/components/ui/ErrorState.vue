<script setup lang="ts">
import EmptyState from './EmptyState.vue';

/** Failed load: message, optional technical code and a retry button. Texts come from the caller (already translated). */
withDefaults(
  defineProps<{
    title: string;
    message?: string;
    /** Technical detail (error code / HTTP status), shown in monospace. */
    detail?: string;
    retryLabel: string;
    compact?: boolean;
  }>(),
  { message: undefined, detail: undefined, compact: false },
);
const emit = defineEmits<{ (e: 'retry'): void }>();
</script>

<template>
  <div role="alert">
    <EmptyState illustration="error" :title="title" :text="message" :compact="compact">
      <q-btn
        color="primary"
        unelevated
        no-caps
        icon="r_refresh"
        :label="retryLabel"
        @click="emit('retry')"
      />
      <span v-if="detail" class="swg-muted swg-mono">{{ detail }}</span>
    </EmptyState>
  </div>
</template>
