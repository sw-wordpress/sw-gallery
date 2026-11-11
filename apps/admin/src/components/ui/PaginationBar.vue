<script setup lang="ts">
/** Server-paging control. All texts (`info`, `pageLabel`, ...) are formatted by the caller. */
defineProps<{
  page: number;
  pages: number;
  /** e.g. "24 galleries". */
  info: string;
  /** e.g. "Page 2 of 5". */
  pageLabel: string;
  navLabel: string;
  previousLabel: string;
  nextLabel: string;
}>();
const emit = defineEmits<{ (e: 'update:page', value: number): void }>();
</script>

<template>
  <nav class="swg-pager" :aria-label="navLabel">
    <span class="swg-pager__info swg-num" aria-live="polite">{{ info }}</span>
    <div v-if="pages > 1" class="swg-pager__controls">
      <q-btn
        flat
        round
        dense
        icon="r_chevron_left"
        class="swg-flip-rtl"
        :disable="page <= 1"
        :aria-label="previousLabel"
        @click="emit('update:page', page - 1)"
      />
      <span class="swg-num">{{ pageLabel }}</span>
      <q-btn
        flat
        round
        dense
        icon="r_chevron_right"
        class="swg-flip-rtl"
        :disable="page >= pages"
        :aria-label="nextLabel"
        @click="emit('update:page', page + 1)"
      />
    </div>
  </nav>
</template>

<style scoped>
.swg-pager {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  padding: 10px 14px;
  font-size: 13px;
  color: var(--swg-muted);
}
.swg-pager__controls {
  display: flex;
  align-items: center;
  gap: 8px;
}
</style>
