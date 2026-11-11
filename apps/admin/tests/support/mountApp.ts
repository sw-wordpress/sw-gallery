import { flushPromises, mount } from '@vue/test-utils';
import { createPinia } from 'pinia';
import { Dark, Notify, Quasar } from 'quasar';
import iconSet from 'quasar/icon-set/material-icons-round';
import { createMemoryHistory } from 'vue-router';
import App from '../../src/App.vue';
import { resetConfigForTests } from '../../src/config';
import { createAppI18n } from '../../src/i18n';
import { GALLERIES_API } from '../../src/features/galleries/injection';
import type { GalleriesApi } from '../../src/api/galleries';
import { createAppRouter } from '../../src/router';

export const ALL_CAPS = ['edit_posts', 'edit_others_posts', 'upload_files', 'manage_options'];

export function setBootstrap(
  capabilities: string[] = ALL_CAPS,
  version = '1.0.0',
  locale = 'en_US',
  timezone = 'UTC',
): void {
  resetConfigForTests();
  window.swgAdmin = {
    restRoot: 'https://x.test/wp-json/swg/v1/',
    nonce: 'n',
    locale,
    version,
    adminUrl: '',
    capabilities,
    timezone,
  };
}

export function resetEnvironment(): void {
  // Quasar creates the notification container once per page: keep it attached for the next test.
  const notify = document.getElementById('q-notify');
  document.body.innerHTML = '';
  if (notify) document.body.appendChild(notify);
  delete window.swgAdmin;
  resetConfigForTests();
  window.localStorage.clear();
  Dark.set(false);
}

export interface MountOptions {
  locale?: string;
  path?: string;
  capabilities?: string[];
  version?: string;
  timezone?: string;
  galleriesApi?: GalleriesApi;
}

/** Default for tests that do not care about the list: the server has no galleries. */
export function emptyGalleriesApi(): GalleriesApi {
  const unused = (): never => {
    throw new Error('not expected in this test');
  };
  return {
    list: () => Promise.resolve({ items: [], total: 0, totalPages: 0 }),
    create: unused,
    get: unused,
    update: unused,
    trash: unused,
    deletePermanently: unused,
    duplicate: unused,
    restore: unused,
  };
}

export async function mountApp(options: MountOptions = {}) {
  const {
    locale = 'en_US',
    path = '/',
    capabilities = ALL_CAPS,
    version = '1.0.0',
    timezone = 'UTC',
    galleriesApi = emptyGalleriesApi(),
  } = options;
  setBootstrap(capabilities, version, locale, timezone);
  const root = document.createElement('div');
  root.id = 'swg-admin-root';
  root.className = 'swg-root';
  document.body.appendChild(root);
  const router = createAppRouter(createMemoryHistory());
  await router.push(path);
  await router.isReady();
  const wrapper = mount(App, {
    attachTo: root,
    global: {
      plugins: [
        createPinia(),
        router,
        createAppI18n(locale),
        [Quasar, { iconSet, plugins: { Notify } }],
      ] as never[],
      provide: { [GALLERIES_API as symbol]: galleriesApi },
    },
  });
  await flushPromises();
  return { wrapper, router, root };
}
