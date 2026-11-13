import { flushPromises } from '@vue/test-utils';
import { createMemoryHistory } from 'vue-router';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { NAV_ITEMS, createAppRouter } from '../src/router';
import { rewriteSelector, swgScope } from '../tools/postcssScope';
import postcss from 'postcss';
import { mountApp, resetEnvironment } from './support/mountApp';

afterEach(() => {
  resetEnvironment();
  vi.resetModules();
});

describe('app shell', () => {
  it('renders exactly the planned navigation entries, no more', async () => {
    const { wrapper } = await mountApp();
    const labels = wrapper.findAll('.swg-nav-link__label').map((n) => n.text());
    expect(labels).toEqual([
      'Overview',
      'Galleries',
      'Media Overview',
      'Settings',
      'System Status',
    ]);
    expect(NAV_ITEMS).toHaveLength(5);
    expect(wrapper.find('nav').attributes('aria-label')).toBe('Gallery Management navigation');
    expect(wrapper.find('.swg-rail__name').text()).toContain('Gallery');
    expect(wrapper.find('.swg-rail__name').text()).toContain('v1.0.0');
  });

  it('shows an honest empty state on every unimplemented section and offers no actions', async () => {
    const { wrapper, router } = await mountApp();
    // The galleries section is implemented (features/galleries, tested separately).
    for (const item of NAV_ITEMS.filter((i) => !['galleries', 'media'].includes(i.name))) {
      await router.push(item.to);
      await flushPromises();
      expect(wrapper.findAll('.swg-nav-link--active')).toHaveLength(1);
      const section = wrapper.find('.swg-placeholder');
      expect(section.text()).toContain('not yet available');
      expect(section.find('button, a').exists()).toBe(false);
    }
  });

  it('marks only the active entry with aria-current and keeps the single h1 in PHP', async () => {
    const { wrapper, router } = await mountApp();
    for (const item of NAV_ITEMS) {
      await router.push(item.to);
      await flushPromises();
      const current = wrapper.findAll('.swg-nav-link[aria-current="page"]');
      expect(current).toHaveLength(1);
      expect(current[0]?.classes()).toContain('swg-nav-link--active');
    }
    // The h1 is printed by the PHP admin page; the app must not add a second one.
    expect(wrapper.find('h1').exists()).toBe(false);
    const headings = wrapper.findAll('h2, h3').map((h) => h.element.tagName);
    expect(headings).toEqual(['H2', 'H3']);
  });

  it('follows the server locale', async () => {
    const { wrapper } = await mountApp({ locale: 'de_DE', path: '/settings' });
    expect(wrapper.find('.swg-empty__title').text()).toBe('Einstellungen: noch nicht verfügbar');
    expect(wrapper.find('nav').attributes('aria-label')).toBe('Navigation der Galerieverwaltung');
    expect(wrapper.find('.swg-rail__name').text()).toContain('Galerie');
  });

  it('redirects unknown routes to the overview and hides the version when empty', async () => {
    const { wrapper, router } = await mountApp({ path: '/nope', version: '' });
    expect(router.currentRoute.value.path).toBe('/');
    expect(wrapper.find('.swg-rail__name small').exists()).toBe(false);
  });

  it('creates a router that is ready for memory history', () => {
    expect(createAppRouter(createMemoryHistory())).toBeDefined();
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
    expect(root?.classList.contains('swg-fullbleed')).toBe(true);
    expect(root?.textContent).toContain('Gallery');
    expect(root?.textContent).toContain('v9.9.9');
  });

  it('does not remount when the entry runs a second time (idempotent bootstrap)', async () => {
    document.body.innerHTML = '<div id="swg-admin-root"></div>';
    window.swgAdmin = {
      restRoot: 'https://x.test/wp-json/swg/v1/',
      nonce: 'n',
      locale: 'en_US',
      version: '1.0.0',
      adminUrl: '',
      capabilities: [],
    };
    const { bootstrap } = await import('../src/main');
    bootstrap();
    await flushPromises();
    const root = document.getElementById('swg-admin-root') as HTMLElement;
    expect(root.dataset.swgBooted).toBe('1');
    const rail = root.querySelector('.swg-rail') as HTMLElement;
    rail.setAttribute('data-touched', 'yes');
    bootstrap();
    await flushPromises();
    expect(root.querySelector('.swg-rail')?.getAttribute('data-touched')).toBe('yes');
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
