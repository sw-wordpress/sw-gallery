<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { NAV_ITEMS } from '@/router';

defineProps<{ version: string }>();

const { t } = useI18n();
const route = useRoute();

/** Marks the active navigation entry for assistive technology (the class alone is only visual). */
function ariaCurrent(name: string): Record<string, string> {
  return route.name === name ? { 'aria-current': 'page' } : {};
}
</script>

<template>
  <div class="swg-shell">
    <header class="swg-shell__header">
      <!-- The page heading (h1) is printed by the PHP admin page; this visible title must not repeat it. -->
      <p class="swg-shell__title" aria-hidden="true">{{ t('app.title') }}</p>
      <span v-if="version !== ''" class="swg-shell__version">
        {{ t('app.version', { version }) }}
      </span>
    </header>
    <div class="swg-shell__body">
      <nav class="swg-nav" :aria-label="t('nav.label')">
        <q-list role="presentation">
          <q-item
            v-for="item in NAV_ITEMS"
            :key="item.name"
            :to="item.to"
            exact
            clickable
            v-bind="ariaCurrent(item.name)"
            class="swg-nav__item"
            active-class="swg-nav__item--active"
          >
            <q-item-section avatar>
              <q-icon :name="item.icon" />
            </q-item-section>
            <q-item-section>{{ t(item.labelKey) }}</q-item-section>
          </q-item>
        </q-list>
      </nav>
      <main class="swg-shell__main">
        <router-view />
      </main>
    </div>
  </div>
</template>
