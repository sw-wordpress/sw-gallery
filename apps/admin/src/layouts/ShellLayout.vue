<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { getConfig } from '@/config';
import { useCapabilities } from '@/composables/useCapabilities';
import { NAV_ITEMS } from '@/router';
import { useAppStore } from '@/stores/app';
import NoAccess from '@/layouts/NoAccess.vue';

/** Capability every user of the app needs; the PHP page enforces it, this is a UX hint. */
const ACCESS_CAPABILITY = 'edit_posts';

const { t } = useI18n();
const route = useRoute();
const app = useAppStore();
const { can } = useCapabilities();
const version = getConfig().version;
const main = ref<HTMLElement | null>(null);

const allowed = computed(() => can(ACCESS_CAPABILITY));
const navItems = computed(() => NAV_ITEMS.filter((i) => !i.capability || can(i.capability)));
/** A route whose section is hidden for this user (deep link) shows the no-access state. */
const routeAllowed = computed(() => {
  const item = NAV_ITEMS.find((i) => i.name === route.name);
  return allowed.value && (!item?.capability || can(item.capability));
});

function focusMain(): void {
  main.value?.focus();
}

function toggleTheme(): void {
  app.toggleTheme();
  app.announce(app.isDark ? t('app.themeDarkOn') : t('app.themeLightOn'));
}

// Screen-reader feedback for client-side navigation (the document title does not change).
watch(
  () => route.name,
  () => {
    const key = route.meta.labelKey;
    if (typeof key === 'string') app.announce(t(key));
  },
);
</script>

<template>
  <div class="swg-shell" :class="{ 'swg-shell--collapsed': app.railCollapsed }">
    <a href="#swg-main" class="swg-skip" @click.prevent="focusMain">{{ t('app.skipToContent') }}</a>

    <aside class="swg-rail" :aria-label="t('app.title')">
      <div class="swg-rail__brand">
        <span class="swg-rail__logo" aria-hidden="true"
          ><q-icon name="r_photo_library" size="22px"
        /></span>
        <span class="swg-rail__name">
          <strong>{{ t('app.brand') }}</strong>
          <small v-if="version" class="swg-num">{{ t('app.versionShort', { version }) }}</small>
        </span>
      </div>

      <nav v-if="allowed" class="swg-rail__nav" :aria-label="t('nav.label')">
        <ul>
          <li v-for="item in navItems" :key="item.name">
            <router-link
              :to="item.to"
              class="swg-nav-link"
              :active-class="item.to === '/' ? '' : 'swg-nav-link--active'"
              exact-active-class="swg-nav-link--active"
            >
              <q-icon :name="item.icon" size="22px" aria-hidden="true" />
              <span class="swg-nav-link__label">{{ t(item.labelKey) }}</span>
              <q-tooltip
                v-if="app.railCollapsed"
                anchor="center right"
                self="center left"
                :offset="[8, 0]"
                >{{ t(item.labelKey) }}</q-tooltip
              >
            </router-link>
          </li>
        </ul>
      </nav>

      <div class="swg-rail__tools">
        <q-btn
          class="swg-rail__theme"
          flat
          round
          dense
          :icon="app.isDark ? 'r_light_mode' : 'r_dark_mode'"
          :aria-label="t('app.darkMode')"
          :aria-pressed="app.isDark ? 'true' : 'false'"
          @click="toggleTheme"
        >
          <q-tooltip>{{ app.isDark ? t('app.switchToLight') : t('app.switchToDark') }}</q-tooltip>
        </q-btn>
        <q-btn
          class="swg-rail__collapse swg-flip-rtl"
          flat
          round
          dense
          :icon="
            app.railCollapsed ? 'r_keyboard_double_arrow_right' : 'r_keyboard_double_arrow_left'
          "
          :aria-label="app.railCollapsed ? t('app.expandNav') : t('app.collapseNav')"
          :aria-expanded="app.railCollapsed ? 'false' : 'true'"
          @click="app.toggleRail()"
        />
      </div>
    </aside>

    <main id="swg-main" ref="main" class="swg-main" tabindex="-1">
      <NoAccess v-if="!routeAllowed" />
      <router-view v-else />
    </main>

    <div class="swg-sr-only" role="status" aria-live="polite" aria-atomic="true">
      {{ app.announcement }}
    </div>
  </div>
</template>

<style scoped>
.swg-shell {
  --rail-w: 244px;
  --bar-h: var(--wp-admin--admin-bar--height, 32px);
  display: grid;
  grid-template-columns: var(--rail-w) minmax(0, 1fr);
  min-height: calc(100vh - var(--bar-h));
  background: var(--swg-bg);
  position: relative;
}
.swg-shell--collapsed {
  --rail-w: 76px;
}
.swg-skip {
  position: absolute;
  inset-inline-start: 12px;
  top: -60px;
  z-index: 100;
  padding: 8px 14px;
  border-radius: 8px;
  background: var(--swg-primary);
  color: #fff !important;
  font-weight: 600;
}
.swg-skip:focus {
  top: 10px;
}
.swg-rail {
  position: sticky;
  top: var(--bar-h);
  align-self: start;
  height: calc(100vh - var(--bar-h));
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 18px 14px;
  background: var(--swg-rail-bg);
  border-inline-end: 1px solid var(--swg-border);
}
.swg-rail__brand {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 4px 8px 16px;
  min-width: 0;
}
.swg-rail__logo {
  display: grid;
  place-items: center;
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: 12px;
  color: #fff;
  background: var(--swg-gradient);
  box-shadow: 0 6px 16px rgba(79, 70, 229, 0.35);
}
.swg-rail__name {
  display: flex;
  flex-direction: column;
  line-height: 1.2;
  min-width: 0;
}
.swg-rail__name strong {
  font-size: 16px;
  letter-spacing: -0.01em;
}
.swg-rail__name small {
  color: var(--swg-muted);
  font-size: 12px;
}
.swg-rail__nav {
  flex: 1;
  overflow-y: auto;
}
.swg-rail__nav ul {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.swg-nav-link {
  position: relative;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border-radius: 12px;
  color: var(--swg-muted) !important;
  font-weight: 600;
  text-decoration: none !important;
  transition:
    background-color 0.15s ease,
    color 0.15s ease;
}
.swg-nav-link:hover {
  background: var(--swg-surface-3);
  color: var(--swg-text) !important;
}
.swg-nav-link--active {
  background: var(--swg-primary-bg);
  color: var(--swg-primary-text) !important;
}
.swg-nav-link--active::before {
  content: '';
  position: absolute;
  inset-inline-start: -14px;
  top: 10px;
  bottom: 10px;
  width: 4px;
  border-start-end-radius: 4px;
  border-end-end-radius: 4px;
  background: var(--swg-gradient);
}
.swg-rail__tools {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 4px 0;
  border-top: 1px solid var(--swg-border);
  color: var(--swg-muted);
}
.swg-shell--collapsed .swg-rail__name,
.swg-shell--collapsed .swg-nav-link__label {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
}
.swg-shell--collapsed .swg-nav-link {
  justify-content: center;
}
.swg-shell--collapsed .swg-rail__tools {
  flex-direction: column;
  gap: 4px;
}
.swg-shell--collapsed .swg-rail__brand {
  justify-content: center;
  padding-inline: 0;
}
.swg-main {
  min-width: 0;
  padding: 28px clamp(16px, 3vw, 40px) 48px;
  outline: none;
}
.swg-main > * {
  max-width: 1320px;
  margin-inline: auto;
}

@media (max-width: 860px) {
  .swg-shell,
  .swg-shell--collapsed {
    grid-template-columns: minmax(0, 1fr);
    grid-template-rows: auto 1fr;
  }
  .swg-rail {
    position: sticky;
    z-index: 20;
    height: auto;
    flex-direction: row;
    align-items: center;
    gap: 6px;
    padding: 8px 10px;
    border-inline-end: 0;
    border-bottom: 1px solid var(--swg-border);
  }
  .swg-rail__brand {
    padding: 0;
  }
  .swg-rail__name {
    display: none;
  }
  .swg-rail__nav {
    flex: 1;
    min-width: 0;
    overflow-x: auto;
    overflow-y: hidden;
    scrollbar-width: thin;
  }
  .swg-rail__nav ul {
    flex-direction: row;
  }
  .swg-nav-link {
    flex-direction: column;
    gap: 2px;
    padding: 6px 10px;
    font-size: 11px;
  }
  .swg-shell--collapsed .swg-nav-link__label {
    position: static;
    width: auto;
    height: auto;
    clip: auto;
  }
  .swg-nav-link--active::before {
    inset-inline: 10px;
    top: auto;
    bottom: 0;
    width: auto;
    height: 3px;
    border-radius: 3px 3px 0 0;
  }
  .swg-rail__tools {
    border-top: 0;
    padding: 0;
    flex-direction: row;
  }
  .swg-rail__collapse {
    display: none;
  }
  .swg-main {
    padding-top: 20px;
  }
}
</style>
