<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import SwgCard from '@/components/ui/SwgCard.vue';

const { t } = useI18n();
const route = useRoute();

const section = computed(() => {
  const key = route.meta.labelKey;
  return typeof key === 'string' ? t(key) : '';
});
const illustration = computed(() => {
  const name = route.name;
  if (name === 'galleries') return 'gallery';
  if (name === 'media') return 'media';
  return 'settings';
});
</script>

<template>
  <div class="swg-placeholder">
    <!-- The h1 is printed by the PHP admin page; the visible page title is an h2. -->
    <PageHeader :title="section" />
    <SwgCard :padding="false" tag="div" accent>
      <EmptyState
        :illustration="illustration"
        :title="t('placeholder.title', { section })"
        :text="t('placeholder.body')"
      />
    </SwgCard>
  </div>
</template>
