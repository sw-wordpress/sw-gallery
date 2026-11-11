import { flushPromises, mount } from '@vue/test-utils';
import { createPinia } from 'pinia';
import { Dark, Quasar } from 'quasar';
import iconSet from 'quasar/icon-set/material-icons-round';
import { createMemoryHistory } from 'vue-router';
import App from '../../src/App.vue';
import { resetConfigForTests } from '../../src/config';
import { createAppI18n } from '../../src/i18n';
import { createAppRouter } from '../../src/router';

export const ALL_CAPS = ['edit_posts', 'edit_others_posts', 'upload_files', 'manage_options'];

export function setBootstrap(capabilities: string[] = ALL_CAPS, version = '1.0.0'): void {
  resetConfigForTests();
  window.swgAdmin = {
    restRoot: 'https://x.test/wp-json/swg/v1/',
    nonce: 'n',
    locale: 'en_US',
    version,
    adminUrl: '',
    capabilities,
  };
}

export function resetEnvironment(): void {
  document.body.innerHTML = '';
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
}

export async function mountApp(options: MountOptions = {}) {
  const { locale = 'en_US', path = '/', capabilities = ALL_CAPS, version = '1.0.0' } = options;
  setBootstrap(capabilities, version);
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
      plugins: [createPinia(), router, createAppI18n(locale), [Quasar, { iconSet }]] as never[],
    },
  });
  await flushPromises();
  return { wrapper, router, root };
}
