<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { NAV_ITEMS } from '@/router';

defineProps<{ version: string }>();

const { t } = useI18n();
</script>

<template>
  <div class="swg-shell">
    <header class="swg-shell__header">
      <h1 class="swg-shell__title">{{ t('app.title') }}</h1>
      <span v-if="version !== ''" class="swg-shell__version">
        {{ t('app.version', { version }) }}
      </span>
    </header>
    <div class="swg-shell__body">
      <nav class="swg-nav" :aria-label="t('nav.label')">
        <q-list>
          <q-item
            v-for="item in NAV_ITEMS"
            :key="item.name"
            :to="item.to"
            exact
            clickable
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
