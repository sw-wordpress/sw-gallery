<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import StatusChip from '@/components/ui/StatusChip.vue';

/** "Used in n galleries" (global count of live galleries); 0 reads "not used yet", never a bare 0. */
const props = defineProps<{ count: number }>();
const { t } = useI18n();
const label = computed(() => t('media.usage', { count: props.count }, props.count));
</script>

<template>
  <StatusChip
    :label="label"
    :kind="count > 0 ? 'info' : 'neutral'"
    :icon="count > 0 ? 'r_collections' : 'r_hide_image'"
    dense
  />
</template>
