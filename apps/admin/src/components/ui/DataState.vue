<script setup lang="ts">
import ErrorState from './ErrorState.vue';

/**
 * Standard state wrapper (CODE-STYLE section 7): loading (skeleton) / error + retry / empty / ready.
 * `loading` only shows the skeleton while there is nothing to display yet, so refreshes do not
 * flash; an error while data is already shown becomes an inline alert above the content.
 * All texts are passed in by the caller.
 */
withDefaults(
  defineProps<{
    loading: boolean;
    /** Translated error message; `null` = no error. */
    error: string | null;
    /** True while there is nothing to show (no data yet / zero results). */
    empty?: boolean;
    errorTitle: string;
    retryLabel: string;
    loadingLabel: string;
    errorDetail?: string;
    rows?: number;
    variant?: 'list' | 'cards' | 'block';
    compactError?: boolean;
  }>(),
  {
    empty: false,
    errorDetail: undefined,
    rows: 4,
    variant: 'list',
    compactError: false,
  },
);
const emit = defineEmits<{ (e: 'retry'): void }>();
</script>

<template>
  <div>
    <ErrorState
      v-if="error !== null && empty"
      :title="errorTitle"
      :message="error"
      :detail="errorDetail"
      :retry-label="retryLabel"
      :compact="compactError"
      @retry="emit('retry')"
    />
    <template v-else-if="loading && empty">
      <div class="swg-skeleton" :class="`swg-skeleton--${variant}`" aria-busy="true">
        <span class="swg-sr-only" role="status">{{ loadingLabel }}</span>
        <template v-if="variant === 'cards'">
          <q-skeleton
            v-for="n in rows"
            :key="n"
            height="150px"
            class="swg-skeleton__card"
            aria-hidden="true"
          />
        </template>
        <template v-else-if="variant === 'block'">
          <q-skeleton height="180px" aria-hidden="true" />
        </template>
        <template v-else>
          <div v-for="n in rows" :key="n" class="swg-skeleton__row" aria-hidden="true">
            <q-skeleton type="circle" size="36px" />
            <div class="swg-skeleton__lines">
              <q-skeleton type="text" width="45%" />
              <q-skeleton type="text" width="70%" />
            </div>
          </div>
        </template>
      </div>
    </template>
    <template v-else-if="empty"><slot name="empty" /></template>
    <template v-else>
      <div v-if="error !== null" class="swg-inline-error" role="alert">
        <q-icon name="r_error" size="18px" aria-hidden="true" />
        <span>{{ error }}</span>
        <q-btn
          flat
          dense
          no-caps
          color="primary"
          icon="r_refresh"
          :label="retryLabel"
          @click="emit('retry')"
        />
      </div>
      <slot />
    </template>
  </div>
</template>

<style scoped>
.swg-skeleton--cards {
  display: grid;
  gap: 16px;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr));
}
.swg-skeleton__row {
  display: flex;
  gap: 14px;
  align-items: center;
  padding: 14px 4px;
}
.swg-skeleton__lines {
  flex: 1;
}
.swg-inline-error {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  margin-bottom: 12px;
  border-radius: var(--swg-radius-sm);
  background: var(--swg-danger-bg);
  color: var(--swg-danger-text);
  font-size: 14px;
}
</style>
