import { ref } from 'vue';
import type { Component } from 'vue';
import { createRouter, createWebHashHistory } from 'vue-router';
import type { Router, RouteRecordRaw } from 'vue-router';

export interface NavItem {
  name: string;
  to: string;
  icon: string;
  /** Message key of the label (also used for the screen-reader announcement on navigation). */
  labelKey: string;
  /** WordPress capability the entry needs (UX hint only; the server enforces). Omitted = every user with access. */
  capability?: string;
}

/** Only sections that exist. Nothing for unshipped features (collections, presets, ...). */
export const NAV_ITEMS: readonly NavItem[] = [
  { name: 'overview', to: '/', icon: 'r_space_dashboard', labelKey: 'nav.overview' },
  { name: 'galleries', to: '/galleries', icon: 'r_photo_library', labelKey: 'nav.galleries' },
  {
    name: 'media',
    to: '/media',
    icon: 'r_perm_media',
    labelKey: 'nav.media',
    // `GET /media` needs `upload_files`: contributors (D11) have no Media Overview.
    capability: 'upload_files',
  },
  {
    name: 'settings',
    to: '/settings',
    icon: 'r_settings',
    labelKey: 'nav.settings',
    capability: 'manage_options',
  },
  {
    name: 'status',
    to: '/status',
    icon: 'r_monitor_heart',
    labelKey: 'nav.status',
    capability: 'manage_options',
  },
];

type Loader = () => Promise<Component>;

/** Lazy views (one chunk each). Dynamic imports only: a lazy chunk never imports the entry. */
export const VIEWS: Record<string, Loader> = {
  overview: () => import('@/features/overview/OverviewView.vue'),
  galleries: () => import('@/features/galleries/GalleriesView.vue'),
  media: () => import('@/features/media/MediaView.vue'),
  settings: () => import('@/features/settings/SettingsView.vue'),
  status: () => import('@/features/status/StatusView.vue'),
  'gallery-editor': () => import('@/features/editor/GalleryEditorView.vue'),
};

/**
 * Target (`fullPath`) of the last navigation that failed because a lazy view could not be loaded
 * (network drop, or a stale page after a plugin update removed the old chunk), else `null`. The
 * shell shows a translated message with a retry; navigation never fails silently.
 */
export const loadFailure = ref<string | null>(null);

function loaderFor(views: Record<string, Loader>, name: string): Loader {
  // A missing loader would be a programming error: fail the navigation loudly, never render nothing.
  return views[name] ?? (() => Promise.reject(new Error(`No view registered for "${name}"`)));
}

function buildRoutes(views: Record<string, Loader>): RouteRecordRaw[] {
  return [
    ...NAV_ITEMS.map((item): RouteRecordRaw => ({
      path: item.to,
      name: item.name,
      component: loaderFor(views, item.name),
      meta: { labelKey: item.labelKey },
    })),
    {
      path: '/galleries/:id(\\d+)',
      name: 'gallery-editor',
      component: loaderFor(views, 'gallery-editor'),
      /* fullWidth: the editor drops the shell's content-width cap (ShellLayout.vue). */
      meta: { labelKey: 'editor.title', fullWidth: true },
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ];
}

/** Hash history: the WordPress admin URL (`admin.php?page=...`) stays untouched. `views` is injectable for tests. */
export function createAppRouter(
  history = createWebHashHistory(),
  views: Record<string, Loader> = VIEWS,
): Router {
  loadFailure.value = null;
  const router = createRouter({ history, routes: buildRoutes(views) });
  router.onError((error, to) => {
    console.error('sw-gallery: navigation failed', error);
    loadFailure.value = to.fullPath;
  });
  router.afterEach((_to, _from, failure) => {
    if (!failure) loadFailure.value = null;
  });
  return router;
}
