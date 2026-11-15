<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { getConfig, hasCapability } from '@/config';
import { NAV_ITEMS, loadFailure } from '@/router';
import { useAppStore } from '@/stores/app';
import LoadFailure from '@/layouts/LoadFailure.vue';
import NoAccess from '@/layouts/NoAccess.vue';
import SessionBanner from '@/layouts/SessionBanner.vue';

/** Capability every user of the app needs; the PHP page enforces it, this is a UX hint. */
const ACCESS_CAPABILITY = 'edit_posts';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const fullWidth = computed(() => route.meta.fullWidth === true);
const app = useAppStore();
// The editor starts compact without changing the navigation preference on other pages.
const editorRailExpanded = ref(false);
const railCollapsed = computed(() =>
  fullWidth.value ? !editorRailExpanded.value : app.railCollapsed,
);
function toggleRail(): void {
  if (fullWidth.value) editorRailExpanded.value = !editorRailExpanded.value;
  else app.toggleRail();
}
const can = (capability: string): boolean => hasCapability(getConfig(), capability);
const version = getConfig().version;
const main = ref<HTMLElement | null>(null);

const allowed = computed(() => can(ACCESS_CAPABILITY));
const navItems = computed(() => NAV_ITEMS.filter((i) => !i.capability || can(i.capability)));
/** A route whose section is hidden for this user (deep link) shows the no-access state. */
const routeAllowed = computed(() => {
  const item = NAV_ITEMS.find((i) => i.name === route.name);
  return allowed.value && (!item?.capability || can(item.capability));
});

// Top navigation on narrow screens scrolls horizontally: fades and chevrons show that more tabs exist.
const navEl = ref<HTMLElement | null>(null);
const more = reactive({ start: false, end: false });
function updateMore(): void {
  const el = navEl.value;
  if (el === null) return;
  // `scrollLeft` is negative in RTL: its magnitude is the distance from the start edge in both directions.
  const offset = Math.abs(el.scrollLeft);
  more.start = offset > 1;
  more.end = offset + el.clientWidth < el.scrollWidth - 1;
}
let resizeObserver: ResizeObserver | null = null;
onMounted(() => {
  updateMore();
  if (typeof ResizeObserver === 'function' && navEl.value !== null) {
    resizeObserver = new ResizeObserver(updateMore);
    resizeObserver.observe(navEl.value);
  }
});
onBeforeUnmount(() => resizeObserver?.disconnect());
/** Keep the active tab visible after navigation (also from a deep link). */
async function revealActive(): Promise<void> {
  await nextTick();
  navEl.value
    ?.querySelector<HTMLElement>('[aria-current="page"]')
    ?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
  updateMore();
}
watch(
  () => route.fullPath,
  () => void revealActive(),
);

const retrying = ref(false);
async function retryLoad(): Promise<void> {
  const target = loadFailure.value;
  if (target === null) return;
  retrying.value = true;
  try {
    await router.push(target);
  } catch {
    /* a repeated failure is reported through router.onError and keeps the message on screen */
  } finally {
    retrying.value = false;
  }
}
function reloadPage(): void {
  window.location.reload();
}

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
  <div
    class="swg-shell"
    :class="{ 'swg-shell--collapsed': railCollapsed, 'swg-shell--editor': fullWidth }"
  >
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

      <div
        v-if="allowed"
        class="swg-rail__navwrap"
        :class="{ 'has-more-start': more.start, 'has-more-end': more.end }"
      >
        <nav
          ref="navEl"
          class="swg-rail__nav"
          :aria-label="t('nav.label')"
          @scroll.passive="updateMore"
        >
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
                  v-if="railCollapsed"
                  anchor="center right"
                  self="center left"
                  :offset="[8, 0]"
                  >{{ t(item.labelKey) }}</q-tooltip
                >
              </router-link>
            </li>
          </ul>
        </nav>
        <span class="swg-rail__more swg-rail__more--start" aria-hidden="true"
          ><q-icon name="r_chevron_left" size="20px" class="swg-flip-rtl"
        /></span>
        <span class="swg-rail__more swg-rail__more--end" aria-hidden="true"
          ><q-icon name="r_chevron_right" size="20px" class="swg-flip-rtl"
        /></span>
      </div>

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
          :icon="railCollapsed ? 'r_keyboard_double_arrow_right' : 'r_keyboard_double_arrow_left'"
          :aria-label="railCollapsed ? t('app.expandNav') : t('app.collapseNav')"
          :aria-expanded="railCollapsed ? 'false' : 'true'"
          @click="toggleRail"
        />
      </div>
    </aside>

    <!-- WordPress' own page wrapper is the document's `main` landmark; a second one would duplicate it. -->
    <div
      id="swg-main"
      ref="main"
      class="swg-main"
      :class="{ 'swg-main--full': fullWidth }"
      role="region"
      :aria-label="t('app.content')"
      tabindex="-1"
    >
      <SessionBanner v-if="app.sessionExpired" />
      <NoAccess v-if="!routeAllowed" />
      <LoadFailure
        v-else-if="loadFailure !== null"
        :retrying="retrying"
        @retry="retryLoad"
        @reload="reloadPage"
      />
      <router-view v-else />
    </div>

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
.swg-rail__navwrap {
  position: relative;
  display: flex;
  flex-direction: column;
  flex: 1;
  min-height: 0;
}
.swg-rail__more {
  display: none;
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
/* Editor workspace fills the available viewport; other routes keep the regular page shell. */
.swg-main--full > * {
  max-width: none;
}

.swg-shell--editor .swg-main {
  padding: 0;
  min-height: 0;
}
.swg-shell--editor .swg-main > * {
  margin-inline: 0;
}
@media (min-width: 901px) {
  .swg-shell--editor {
    height: calc(100dvh - var(--bar-h));
    min-height: 0;
    overflow: hidden;
  }
  .swg-shell--editor .swg-main {
    overflow: hidden;
    display: flex;
    flex-direction: column;
  }
  .swg-shell--editor .swg-rail {
    height: 100%;
    min-height: 0;
    top: 0;
  }
  .swg-shell--editor.swg-shell--collapsed {
    --rail-w: 56px;
  }
  .swg-shell--editor.swg-shell--collapsed .swg-rail {
    padding: 12px 6px;
  }
  .swg-shell--editor.swg-shell--collapsed .swg-rail__logo {
    width: 32px;
    height: 32px;
    border-radius: 4px;
    box-shadow: none;
  }
  .swg-shell--editor.swg-shell--collapsed .swg-nav-link {
    padding: 8px;
    border-radius: 4px;
  }
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
  .swg-rail__navwrap {
    flex-direction: row;
    min-width: 0;
  }
  .swg-rail__nav {
    flex: 1;
    min-width: 0;
    overflow-x: auto;
    overflow-y: hidden;
    scrollbar-width: none;
  }
  .swg-rail__nav::-webkit-scrollbar {
    display: none;
  }
  /* Edge fade plus chevron: more tabs exist beyond the visible ones (WCAG 1.4.1: not colour alone). */
  .swg-rail__more {
    position: absolute;
    inset-block: 0;
    z-index: 1;
    display: none;
    align-items: center;
    inline-size: 32px;
    color: var(--swg-muted);
    pointer-events: none;
  }
  .swg-rail__more--start {
    inset-inline-start: 0;
    justify-content: flex-start;
    background: linear-gradient(to right, var(--swg-rail-bg) 30%, transparent);
  }
  .swg-rail__more--end {
    inset-inline-end: 0;
    justify-content: flex-end;
    background: linear-gradient(to left, var(--swg-rail-bg) 30%, transparent);
  }
  .swg-root[dir='rtl'] .swg-rail__more--start {
    background: linear-gradient(to left, var(--swg-rail-bg) 30%, transparent);
  }
  .swg-root[dir='rtl'] .swg-rail__more--end {
    background: linear-gradient(to right, var(--swg-rail-bg) 30%, transparent);
  }
  .has-more-start > .swg-rail__more--start,
  .has-more-end > .swg-rail__more--end {
    display: flex;
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
