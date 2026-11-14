import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { Notify, Quasar } from 'quasar';
import iconSet from 'quasar/icon-set/material-icons-round';
import 'quasar/dist/quasar.prod.css';
import '@quasar/extras/material-icons-round/material-icons-round.css';
import './styles/fonts.css';
import './styles/app.css';

import App from './App.vue';
import { getConfig, resolveUiLocale } from './config';
import { createAppI18n } from './i18n';
import quasarLang from './i18n/quasarLang';
import { createAppRouter } from './router';

export const MOUNT_ID = 'swg-admin-root';
export const ROOT_CLASS = 'swg-root';

function showFatal(el: HTMLElement, message: string): void {
  el.classList.add(ROOT_CLASS);
  const p = document.createElement('p');
  p.setAttribute('role', 'alert');
  p.textContent = message; // textContent: never inject markup
  el.replaceChildren(p);
}

export function bootstrap(): void {
  const el = document.getElementById(MOUNT_ID);
  if (!el) {
    console.error(`sw-gallery: mount node #${MOUNT_ID} not found`);
    return;
  }
  // Idempotent: a second evaluation of this module (e.g. the entry imported under another URL) must never remount the app.
  if (el.dataset.swgBooted === '1') return;
  let config;
  try {
    config = getConfig();
  } catch (error) {
    console.error(error);
    // No bootstrap, so no user locale: fall back to the document language.
    const i18n = createAppI18n(document.documentElement.lang || 'en');
    showFatal(el, i18n.global.t('errors.bootstrap'));
    return;
  }

  el.classList.add(ROOT_CLASS, 'swg-fullbleed');
  el.setAttribute('dir', config.isRtl ? 'rtl' : 'ltr');

  const app = createApp(App);
  app.use(createPinia());
  app.use(createAppRouter());
  app.use(createAppI18n(config.locale));
  app.use(Quasar, {
    plugins: { Notify },
    iconSet,
    // The pack carries Quasar's `rtl` flag (components such as sliders and the table read `$q.lang.rtl`), but
    // `noHtmlAttrs` stops Quasar from rewriting `<html lang/dir>`, which belongs to WordPress.
    lang: { ...quasarLang(resolveUiLocale(config.locale)), rtl: config.isRtl },
    // Quasar portals (dialogs, menus, notifications) are appended to <body>; give them the root
    // class so the scoped Quasar CSS applies there as well.
    config: { lang: { noHtmlAttrs: true }, globalNodes: { class: `${ROOT_CLASS} swg-portal` } },
  });
  el.dataset.swgBooted = '1';
  app.mount(el);
}

bootstrap();
