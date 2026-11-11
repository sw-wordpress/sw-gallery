<script setup lang="ts">
/**
 * Page title block. The PHP admin page owns the single h1 of the document, so the visible title is
 * an h2 by default (`headingLevel`).
 */
withDefaults(
  defineProps<{
    title: string;
    subtitle?: string;
    headingLevel?: 2 | 3;
    backTo?: string;
    backLabel?: string;
  }>(),
  { subtitle: undefined, headingLevel: 2, backTo: undefined, backLabel: undefined },
);
</script>

<template>
  <header class="swg-page-header">
    <div class="swg-page-header__text">
      <router-link v-if="backTo" :to="backTo" class="swg-page-header__back">
        <q-icon name="r_arrow_back" size="18px" class="swg-flip-rtl" aria-hidden="true" />
        {{ backLabel }}
      </router-link>
      <component :is="`h${headingLevel}`" class="swg-page-header__title">{{ title }}</component>
      <p v-if="subtitle" class="swg-page-header__subtitle">{{ subtitle }}</p>
    </div>
    <div v-if="$slots.default" class="swg-page-header__actions"><slot /></div>
  </header>
</template>

<style scoped>
.swg-page-header {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: 24px;
}
.swg-page-header__title {
  font-size: 26px;
  line-height: 1.2;
  font-weight: 750;
  letter-spacing: -0.02em;
}
.swg-page-header__subtitle {
  margin-top: 4px;
  color: var(--swg-muted);
  max-width: 62ch;
}
.swg-page-header__back {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-bottom: 6px;
  font-size: 13px;
  font-weight: 600;
}
.swg-page-header__actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  align-items: center;
}
@media (max-width: 520px) {
  .swg-page-header__title {
    font-size: 22px;
  }
}
</style>
