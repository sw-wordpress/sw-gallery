import { computed, ref } from 'vue';
import { defineStore } from 'pinia';

export const THEME_KEY = 'swg-theme';
export const RAIL_KEY = 'swg-rail-collapsed';
export const GALLERY_VIEW_KEY = 'swg-gallery-view';
export const MEDIA_VIEW_KEY = 'swg-media-view';
export type GalleryView = 'grid' | 'table';
export type ThemePreference = 'light' | 'dark' | 'system';

/** Per-viewer UI conveniences only. Storage may be missing, blocked or throw: the app must work without it. */
function readStorage(key: string): string | null {
  try {
    return window.localStorage.getItem(key);
  } catch {
    return null;
  }
}

function writeStorage(key: string, value: string | null): void {
  try {
    if (value === null) window.localStorage.removeItem(key);
    else window.localStorage.setItem(key, value);
  } catch {
    /* storage unavailable (private mode, blocked): the preference just is not persisted */
  }
}

function readTheme(): ThemePreference {
  const value = readStorage(THEME_KEY);
  return value === 'light' || value === 'dark' ? value : 'system';
}

function systemPrefersDark(): boolean {
  return (
    typeof window.matchMedia === 'function' &&
    window.matchMedia('(prefers-color-scheme: dark)').matches
  );
}

export const useAppStore = defineStore('app', () => {
  const themePreference = ref<ThemePreference>(readTheme());
  const systemDark = ref(systemPrefersDark());
  const railCollapsed = ref(readStorage(RAIL_KEY) === '1');
  const galleryView = ref<GalleryView>(
    readStorage(GALLERY_VIEW_KEY) === 'table' ? 'table' : 'grid',
  );
  const mediaView = ref<GalleryView>(readStorage(MEDIA_VIEW_KEY) === 'table' ? 'table' : 'grid');
  /** Text for the polite live region. */
  const announcement = ref('');

  const isDark = computed(() =>
    themePreference.value === 'system' ? systemDark.value : themePreference.value === 'dark',
  );

  function setTheme(value: ThemePreference): void {
    themePreference.value = value;
    writeStorage(THEME_KEY, value === 'system' ? null : value);
  }
  function toggleTheme(): void {
    setTheme(isDark.value ? 'light' : 'dark');
  }
  function setRailCollapsed(value: boolean): void {
    railCollapsed.value = value;
    writeStorage(RAIL_KEY, value ? '1' : null);
  }
  function toggleRail(): void {
    setRailCollapsed(!railCollapsed.value);
  }
  function setGalleryView(value: GalleryView): void {
    galleryView.value = value;
    writeStorage(GALLERY_VIEW_KEY, value === 'table' ? 'table' : null);
  }
  function setMediaView(value: GalleryView): void {
    mediaView.value = value;
    writeStorage(MEDIA_VIEW_KEY, value === 'table' ? 'table' : null);
  }
  function announce(message: string): void {
    // Reset first so an identical message is announced again.
    announcement.value = '';
    queueMicrotask(() => {
      announcement.value = message;
    });
  }

  return {
    themePreference,
    systemDark,
    isDark,
    railCollapsed,
    announcement,
    galleryView,
    setGalleryView,
    mediaView,
    setMediaView,
    setTheme,
    toggleTheme,
    setRailCollapsed,
    toggleRail,
    announce,
  };
});
