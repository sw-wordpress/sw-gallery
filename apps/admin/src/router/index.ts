import { createRouter, createWebHashHistory } from 'vue-router';
import type { Router, RouteRecordRaw } from 'vue-router';

export interface NavItem {
  name: string;
  to: string;
  icon: string;
  /** Message key of the label (also the section name on the placeholder page). */
  labelKey: string;
  /** WordPress capability the entry needs (UX hint only; the server enforces). Omitted = every user with access. */
  capability?: string;
}

/** Only sections that will exist. Nothing for unshipped features (collections, presets, ...). */
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

/** Sections that are implemented; every other one stays an honest placeholder. */
const VIEWS: Record<string, RouteRecordRaw['component']> = {
  galleries: () => import('@/features/galleries/GalleriesView.vue'),
  media: () => import('@/features/media/MediaView.vue'),
};

const routes: RouteRecordRaw[] = [
  ...NAV_ITEMS.map((item): RouteRecordRaw => ({
    path: item.to,
    name: item.name,
    component: VIEWS[item.name] ?? (() => import('@/views/PlaceholderView.vue')),
    meta: { labelKey: item.labelKey },
  })),
  {
    path: '/galleries/:id(\\d+)',
    name: 'gallery-editor',
    component: () => import('@/features/editor/GalleryEditorView.vue'),
    meta: { labelKey: 'editor.title' },
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
];

/** Hash history: the WordPress admin URL (`admin.php?page=...`) stays untouched. */
export function createAppRouter(history = createWebHashHistory()): Router {
  return createRouter({ history, routes });
}
