<script setup lang="ts">
withDefaults(
  defineProps<{
    title?: string;
    subtitle?: string;
    icon?: string;
    padding?: boolean;
    interactive?: boolean;
    tag?: string;
    accent?: boolean;
    /** Level of the card title (the page title is h2, so cards default to h3). */
    headingLevel?: 2 | 3 | 4;
  }>(),
  {
    title: undefined,
    subtitle: undefined,
    icon: undefined,
    padding: true,
    interactive: false,
    tag: 'section',
    accent: false,
    headingLevel: 3,
  },
);
</script>

<template>
  <component
    :is="tag"
    class="swg-card"
    :class="{ 'swg-card--interactive': interactive, 'swg-card--accent': accent }"
  >
    <header v-if="title || $slots.header || $slots.actions" class="swg-card__header">
      <slot name="header">
        <div class="swg-card__heading">
          <span v-if="icon" class="swg-card__icon" aria-hidden="true"
            ><q-icon :name="icon" size="20px"
          /></span>
          <div class="swg-card__titles">
            <component :is="`h${headingLevel}`" class="swg-card__title">{{ title }}</component>
            <p v-if="subtitle" class="swg-card__subtitle">{{ subtitle }}</p>
          </div>
        </div>
      </slot>
      <div v-if="$slots.actions" class="swg-card__actions"><slot name="actions" /></div>
    </header>
    <div class="swg-card__body" :class="{ 'swg-card__body--flush': !padding }"><slot /></div>
    <footer v-if="$slots.footer" class="swg-card__footer"><slot name="footer" /></footer>
  </component>
</template>

<style scoped>
.swg-card {
  position: relative;
  background: var(--swg-surface);
  border: 1px solid var(--swg-border);
  border-radius: var(--swg-radius);
  box-shadow: var(--swg-shadow-1);
  min-width: 0;
  transition:
    box-shadow 0.2s ease,
    transform 0.2s ease,
    border-color 0.2s ease;
}
.swg-card--interactive:hover {
  box-shadow: var(--swg-shadow-2);
  transform: translateY(-1px);
}
.swg-card--accent::before {
  content: '';
  position: absolute;
  inset: 0 0 auto 0;
  height: 3px;
  border-radius: var(--swg-radius) var(--swg-radius) 0 0;
  background: var(--swg-gradient);
}
.swg-card__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  padding: 18px 22px 0;
}
.swg-card__heading {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}
.swg-card__icon {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  flex: none;
  border-radius: 10px;
  color: var(--swg-primary);
  background: var(--swg-gradient-soft);
}
.swg-card__title {
  font-size: 16px;
  font-weight: 650;
  line-height: 1.3;
}
.swg-card__subtitle {
  font-size: 13px;
  color: var(--swg-muted);
}
.swg-card__actions {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
}
.swg-card__body {
  padding: 18px 22px 22px;
}
.swg-card__body--flush {
  padding: 12px 0 0;
}
.swg-card__footer {
  padding: 14px 22px;
  border-top: 1px solid var(--swg-border);
}
@media (max-width: 520px) {
  .swg-card__header {
    padding: 16px 16px 0;
  }
  .swg-card__body {
    padding: 14px 16px 18px;
  }
}
</style>
