<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { Gallery } from '@/api/galleries';
import StatusChip from '@/components/ui/StatusChip.vue';
import { STATUS_META } from '../format';

defineProps<{ gallery: Pick<Gallery, 'status' | 'passwordProtected'> }>();
const { t } = useI18n();
</script>

<template>
  <span class="swg-gstatus">
    <StatusChip
      dense
      :kind="STATUS_META[gallery.status].kind"
      :icon="STATUS_META[gallery.status].icon"
      :label="t(`galleries.status.${gallery.status}`)"
    />
    <StatusChip
      v-if="gallery.passwordProtected"
      dense
      kind="neutral"
      icon="r_key"
      :label="t('galleries.passwordProtected')"
    />
  </span>
</template>

<style scoped>
.swg-gstatus {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 6px;
}
</style>
