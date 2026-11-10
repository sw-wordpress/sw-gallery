import { createRouter, createWebHashHistory } from 'vue-router';
import type { Router, RouteRecordRaw } from 'vue-router';

export interface NavItem {
  name: string;
  to: string;
  icon: string;
  /** Message key of the label (also the section name on the placeholder page). */
  labelKey: string;
}

/** Only sections that will exist. Nothing for unshipped features (collections, presets, ...). */
export const NAV_ITEMS: readonly NavItem[] = [
  { name: 'overview', to: '/', icon: 'r_space_dashboard', labelKey: 'nav.overview' },
  { name: 'galleries', to: '/galleries', icon: 'r_photo_library', labelKey: 'nav.galleries' },
  { name: 'media', to: '/media', icon: 'r_perm_media', labelKey: 'nav.media' },
  { name: 'settings', to: '/settings', icon: 'r_settings', labelKey: 'nav.settings' },
  { name: 'status', to: '/status', icon: 'r_monitor_heart', labelKey: 'nav.status' },
];

const routes: RouteRecordRaw[] = [
  ...NAV_ITEMS.map((item): RouteRecordRaw => ({
    path: item.to,
    name: item.name,
    component: () => import('@/views/PlaceholderView.vue'),
    meta: { labelKey: item.labelKey },
  })),
  { path: '/:pathMatch(.*)*', redirect: '/' },
];

/** Hash history: the WordPress admin URL (`admin.php?page=...`) stays untouched. */
export function createAppRouter(history = createWebHashHistory()): Router {
  return createRouter({ history, routes });
}
