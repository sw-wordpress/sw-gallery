import { flushPromises, mount } from '@vue/test-utils';
import { Quasar } from 'quasar';
import iconSet from 'quasar/icon-set/material-icons-round';
import { createMemoryHistory } from 'vue-router';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../src/App.vue';
import { createAppI18n } from '../src/i18n';
import { NAV_ITEMS, createAppRouter } from '../src/router';
import { rewriteSelector, swgScope } from '../tools/postcssScope';
import postcss from 'postcss';

async function mountApp(locale = 'en_US', path = '/') {
  const router = createAppRouter(createMemoryHistory());
  await router.push(path);
  await router.isReady();
  const wrapper = mount(App, {
    props: { version: '1.0.0' },
    attachTo: document.body,
    global: { plugins: [router, createAppI18n(locale), [Quasar, { iconSet }]] as never[] },
  });
  await flushPromises();
  return { wrapper, router };
}

afterEach(() => {
  document.body.innerHTML = '';
  delete window.swgAdmin;
  vi.resetModules();
});

describe('app shell', () => {
  it('renders exactly the planned navigation entries, no more', async () => {
    const { wrapper } = await mountApp();
    const labels = wrapper.findAll('.swg-nav__item .q-item__section--main').map((n) => n.text());
    expect(labels).toEqual([
      'Overview',
      'Galleries',
      'Media Overview',
      'Settings',
      'System Status',
    ]);
    expect(NAV_ITEMS).toHaveLength(5);
    expect(wrapper.find('nav').attributes('aria-label')).toBe('Gallery Management navigation');
    expect(wrapper.text()).toContain('Version 1.0.0');
  });

  it('shows an honest empty state on every section and offers no actions', async () => {
    const { wrapper, router } = await mountApp();
    for (const item of NAV_ITEMS) {
      await router.push(item.to);
      await flushPromises();
      const label = wrapper
        .findAll('.swg-nav__item')
        .find((n) => n.classes('swg-nav__item--active'));
      expect(label).toBeDefined();
      const section = wrapper.find('.swg-placeholder');
      expect(section.text()).toContain('not yet available');
      expect(section.find('button, a').exists()).toBe(false);
    }
  });

  it('marks only the active entry with aria-current and keeps the page heading in PHP', async () => {
    const { wrapper, router } = await mountApp();
    for (const item of NAV_ITEMS) {
      await router.push(item.to);
      await flushPromises();
      const current = wrapper.findAll('.swg-nav__item[aria-current="page"]');
      expect(current).toHaveLength(1);
      expect(current[0]?.classes()).toContain('swg-nav__item--active');
    }
    // The h1 is printed by the PHP admin page; the app must not add a second one.
    expect(wrapper.find('h1').exists()).toBe(false);
    expect(wrapper.find('.swg-shell__title').attributes('aria-hidden')).toBe('true');
    expect(wrapper.find('nav [role="list"]').exists()).toBe(false);
  });

  it('follows the server locale', async () => {
    const { wrapper } = await mountApp('de_DE', '/galleries');
    expect(wrapper.find('.swg-placeholder__title').text()).toBe('Galerien: noch nicht verfügbar');
    expect(wrapper.find('nav').attributes('aria-label')).toBe('Navigation der Galerieverwaltung');
  });

  it('redirects unknown routes to the overview and hides the version when empty', async () => {
    const { wrapper, router } = await mountApp('en_US', '/nope');
    expect(router.currentRoute.value.path).toBe('/');
    await wrapper.setProps({ version: '' });
    expect(wrapper.find('.swg-shell__version').exists()).toBe(false);
  });
});

describe('bootstrap (main.ts)', () => {
  it('mounts under #swg-admin-root with the scoped root class and direction', async () => {
    document.body.innerHTML = '<div id="swg-admin-root"></div>';
    window.swgAdmin = {
      restRoot: 'https://x.test/wp-json/swg/v1/',
      nonce: 'n',
      locale: 'en_US',
      version: '9.9.9',
      adminUrl: '',
      capabilities: [],
      isRtl: true,
    };
    await import('../src/main');
    await flushPromises();
    const root = document.getElementById('swg-admin-root');
    expect(root?.classList.contains('swg-root')).toBe(true);
    expect(root?.getAttribute('dir')).toBe('rtl');
    expect(root?.textContent).toContain('Gallery Management');
  });

  it('shows a translated fatal message when the bootstrap is missing', async () => {
    document.body.innerHTML = '<div id="swg-admin-root"></div>';
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});
    await import('../src/main');
    const alert = document.querySelector('#swg-admin-root [role="alert"]');
    expect(alert?.textContent).toContain('server configuration is missing');
    expect(error).toHaveBeenCalled();
  });

  it('logs when the mount node is absent', async () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});
    await import('../src/main');
    expect(error).toHaveBeenCalledWith(expect.stringContaining('#swg-admin-root'));
  });
});

describe('Quasar CSS isolation (postcss plugin)', () => {
  it('rewrites global selectors into the swg-root scope', () => {
    const r = (s: string) => rewriteSelector(s, '.swg-root', 'swg-dark');
    expect(r('html')).toBe('.swg-root');
    expect(r('body')).toBe('.swg-root');
    expect(r('body.body--dark .q-card')).toBe('.swg-root.swg-dark .q-card');
    expect(r('body.body--light .q-card')).toBe('.swg-root:not(.swg-dark) .q-card');
    expect(r('body.q-body--prevent-scroll')).toBe('.swg-root.q-body--prevent-scroll');
    expect(r('.q-btn')).toBe('.swg-root .q-btn');
    expect(r('*')).toBe('.swg-root *');
    expect(r('.swg-root .x')).toBe('.swg-root .x');
    expect(r('  ')).toBe('');
  });

  it('only touches Quasar files and leaves keyframes alone', async () => {
    const css = 'body{margin:0} .a,.a{color:red} @keyframes k{from{opacity:0}}';
    const run = (file: string) => postcss([swgScope()]).process(css, { from: file }).css;
    expect(run('/p/node_modules/quasar/dist/q.css')).toContain('.swg-root{margin:0}');
    expect(run('/p/node_modules/quasar/dist/q.css')).toContain('from{opacity:0}');
    expect(run('/p/src/own.css')).toContain('body{margin:0}');
  });
});
