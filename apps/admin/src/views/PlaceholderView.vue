<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';

const { t } = useI18n();
const route = useRoute();

const section = computed(() => {
  const key = route.meta.labelKey;
  return typeof key === 'string' ? t(key) : '';
});
</script>

<template>
  <section class="swg-placeholder" aria-labelledby="swg-placeholder-title">
    <q-icon name="r_construction" size="40px" />
    <h2 id="swg-placeholder-title" class="swg-placeholder__title">
      {{ t('placeholder.title', { section }) }}
    </h2>
    <p class="swg-placeholder__body">{{ t('placeholder.body') }}</p>
  </section>
</template>
