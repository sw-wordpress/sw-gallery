import { flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { Dark } from 'quasar';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { RAIL_KEY, THEME_KEY, useAppStore } from '../src/stores/app';
import { mountApp, resetEnvironment } from './support/mountApp';

function mockSystemDark(matches: boolean): void {
  vi.stubGlobal(
    'matchMedia',
    vi.fn().mockImplementation(() => ({
      matches,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    })),
  );
}

beforeEach(() => {
  mockSystemDark(false);
});

afterEach(() => {
  resetEnvironment();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('capability based navigation', () => {
  it('hides settings and status without manage_options (data-driven, UX hint only)', async () => {
    const { wrapper } = await mountApp({ capabilities: ['edit_posts', 'upload_files'] });
    expect(wrapper.findAll('.swg-nav-link__label').map((n) => n.text())).toEqual([
      'Overview',
      'Galleries',
      'Media Overview',
    ]);
  });

  it('shows the no-access state without edit_posts and renders no navigation', async () => {
    const { wrapper } = await mountApp({ capabilities: [] });
    expect(wrapper.find('nav').exists()).toBe(false);
    expect(wrapper.find('.swg-empty__title').text()).toBe('No access');
    expect(wrapper.find('.swg-placeholder').exists()).toBe(false);
  });

  it('shows no-access for a deep link into a hidden section', async () => {
    const { wrapper } = await mountApp({ capabilities: ['edit_posts'], path: '/settings' });
    expect(wrapper.find('.swg-empty__title').text()).toBe('No access');
    expect(wrapper.find('.swg-placeholder').exists()).toBe(false);
  });
});

describe('theme toggle', () => {
  it('defaults to the system preference and toggles with aria-pressed, persisting the choice', async () => {
    mockSystemDark(true);
    const { wrapper, root } = await mountApp();
    const button = wrapper.find('.swg-rail__theme');
    expect(button.attributes('aria-pressed')).toBe('true');
    expect(root.classList.contains('swg-dark')).toBe(true);
    expect(Dark.isActive).toBe(true);
    expect(window.localStorage.getItem(THEME_KEY)).toBeNull();

    await button.trigger('click');
    await flushPromises();
    expect(button.attributes('aria-pressed')).toBe('false');
    expect(root.classList.contains('swg-dark')).toBe(false);
    expect(window.localStorage.getItem(THEME_KEY)).toBe('light');
    expect(wrapper.find('[role="status"]').text()).toBe('Light theme on');

    await button.trigger('click');
    await flushPromises();
    expect(root.classList.contains('swg-dark')).toBe(true);
    expect(window.localStorage.getItem(THEME_KEY)).toBe('dark');
    expect(wrapper.find('[role="status"]').text()).toBe('Dark theme on');
  });

  it('restores a stored preference over the system preference', async () => {
    window.localStorage.setItem(THEME_KEY, 'dark');
    const { wrapper, root } = await mountApp();
    expect(root.classList.contains('swg-dark')).toBe(true);
    expect(wrapper.find('.swg-rail__theme').attributes('aria-pressed')).toBe('true');
  });

  it('marks Quasar portal nodes added later with the dark class', async () => {
    window.localStorage.setItem(THEME_KEY, 'dark');
    await mountApp();
    const portal = document.createElement('div');
    portal.className = 'swg-root';
    document.body.appendChild(portal);
    await flushPromises();
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(portal.classList.contains('swg-dark')).toBe(true);
  });

  it('works when localStorage throws', async () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    const { wrapper, root } = await mountApp();
    await wrapper.find('.swg-rail__theme').trigger('click');
    await wrapper.find('.swg-rail__collapse').trigger('click');
    await flushPromises();
    expect(root.classList.contains('swg-dark')).toBe(true);
    expect(wrapper.find('.swg-shell--collapsed').exists()).toBe(true);
  });
});

describe('rail collapse', () => {
  it('collapses and expands, persists, exposes aria-expanded and shows tooltips only when collapsed', async () => {
    const { wrapper } = await mountApp();
    const button = wrapper.find('.swg-rail__collapse');
    expect(button.attributes('aria-expanded')).toBe('true');
    expect(button.attributes('aria-label')).toBe('Collapse navigation');
    expect(wrapper.find('.swg-shell--collapsed').exists()).toBe(false);

    await button.trigger('click');
    expect(button.attributes('aria-expanded')).toBe('false');
    expect(button.attributes('aria-label')).toBe('Expand navigation');
    expect(wrapper.find('.swg-shell--collapsed').exists()).toBe(true);
    expect(window.localStorage.getItem(RAIL_KEY)).toBe('1');
    // Labels stay in the DOM (visually hidden) so the links keep their accessible names.
    expect(wrapper.findAll('.swg-nav-link__label')).toHaveLength(5);

    await button.trigger('click');
    expect(wrapper.find('.swg-shell--collapsed').exists()).toBe(false);
    expect(window.localStorage.getItem(RAIL_KEY)).toBeNull();
  });

  it('restores the collapsed state', async () => {
    window.localStorage.setItem(RAIL_KEY, '1');
    const { wrapper } = await mountApp();
    expect(wrapper.find('.swg-shell--collapsed').exists()).toBe(true);
  });
});

describe('accessibility helpers', () => {
  it('has a skip link that moves focus to the main region, and a polite live region', async () => {
    const { wrapper } = await mountApp();
    const skip = wrapper.find('.swg-skip');
    expect(skip.attributes('href')).toBe('#swg-main');
    await skip.trigger('click');
    expect(document.activeElement?.id).toBe('swg-main');
    const live = wrapper.find('[role="status"]');
    expect(live.attributes('aria-live')).toBe('polite');
  });

  it('announces navigation to assistive technology', async () => {
    const { wrapper, router } = await mountApp();
    await router.push('/galleries');
    await flushPromises();
    expect(wrapper.find('.swg-shell > [role="status"]').text()).toBe('Galleries');
  });
});

describe('app store', () => {
  it('reads defaults when storage is empty and follows the system for "system"', () => {
    setActivePinia(createPinia());
    const store = useAppStore();
    expect(store.themePreference).toBe('system');
    expect(store.isDark).toBe(false);
    store.systemDark = true;
    expect(store.isDark).toBe(true);
    store.setTheme('light');
    expect(store.isDark).toBe(false);
    store.setTheme('system');
    expect(window.localStorage.getItem(THEME_KEY)).toBeNull();
    expect(store.isDark).toBe(true);
  });

  it('survives a missing matchMedia', () => {
    vi.stubGlobal('matchMedia', undefined);
    setActivePinia(createPinia());
    expect(useAppStore().systemDark).toBe(false);
  });
});
