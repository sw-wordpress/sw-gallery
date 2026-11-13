<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/ui/EmptyState.vue';

/** A lazy chunk of the app could not be loaded (FU-19): say so and offer a retry, then a full reload. */
defineProps<{ retrying: boolean }>();
const emit = defineEmits<{ (e: 'retry'): void; (e: 'reload'): void }>();
const { t } = useI18n();
</script>

<template>
  <div role="alert">
    <EmptyState
      illustration="error"
      :heading-level="2"
      :title="t('errors.loadFailureTitle')"
      :text="t('errors.loadFailureText')"
    >
      <q-btn
        color="primary"
        unelevated
        no-caps
        icon="r_refresh"
        :loading="retrying"
        :label="t('errors.loadFailureRetry')"
        @click="emit('retry')"
      />
      <q-btn
        outline
        no-caps
        color="primary"
        icon="r_restart_alt"
        :label="t('errors.loadFailureReload')"
        @click="emit('reload')"
      />
    </EmptyState>
  </div>
</template>
