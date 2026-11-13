import { flushPromises } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { VIEWS, loadFailure } from '../src/router';
import { mountApp, resetEnvironment } from './support/mountApp';

let mounted: VueWrapper | null = null;

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
  mounted?.unmount();
  mounted = null;
  resetEnvironment();
});

const $ = <T extends Element = HTMLElement>(selector: string): T | null =>
  document.body.querySelector<T>(selector);
const $$ = (selector: string): HTMLElement[] =>
  Array.from(document.body.querySelectorAll<HTMLElement>(selector));
const button = (text: string): HTMLElement | undefined =>
  $$('button').find((b) => b.textContent?.includes(text));

async function settle(): Promise<void> {
  await flushPromises();
  await new Promise((resolve) => setTimeout(resolve, 0));
  await flushPromises();
}

const Stub = defineComponent({ render: () => h('p', { class: 'stub' }, 'stub page') });

/** Views where `galleries` fails like a dynamic import that cannot be fetched, until `heal()` is called. */
function flakyViews() {
  let healthy = false;
  const galleries = vi.fn(async () => {
    if (!healthy) throw new TypeError('Failed to fetch dynamically imported module: /chunks/x.js');
    return Stub;
  });
  return {
    views: { ...VIEWS, galleries },
    galleries,
    heal: (): void => {
      healthy = true;
    },
  };
}

describe('lazy chunk failure (FU-19)', () => {
  it('shows a translated message with a retry instead of failing silently, then recovers', async () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const { views, galleries, heal } = flakyViews();
    const { router } = await mountApp({ views });
    mounted = null;
    await router.push('/galleries').catch(() => undefined);
    await settle();
    const alert = $('#swg-main [role="alert"]');
    expect(alert?.textContent).toContain('This section could not be loaded');
    expect(alert?.textContent).toContain('Try again');
    expect(alert?.textContent).toContain('Reload the page');
    expect(loadFailure.value).toBe('/galleries');
    expect(error).toHaveBeenCalled();
    // still failing: the message stays, nothing else renders
    button('Try again')?.click();
    await settle();
    expect(galleries).toHaveBeenCalledTimes(2);
    expect($('#swg-main [role="alert"]')).not.toBeNull();
    // the network is back: the retry loads the page and clears the message
    heal();
    button('Try again')?.click();
    await settle();
    expect($('.stub')?.textContent).toBe('stub page');
    expect($('#swg-main [role="alert"]')).toBeNull();
    expect(loadFailure.value).toBeNull();
  });

  it('also reports a failing first navigation and offers a full reload', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const reload = vi.fn();
    vi.stubGlobal('location', { ...window.location, reload });
    const { wrapper } = await mountApp({
      views: { ...VIEWS, overview: () => Promise.reject(new Error('boom')) },
    });
    mounted = wrapper;
    await settle();
    expect($('#swg-main [role="alert"]')?.textContent).toContain('could not be loaded');
    button('Reload the page')?.click();
    expect(reload).toHaveBeenCalledTimes(1);
  });

  it('is available in German', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const { wrapper } = await mountApp({
      locale: 'de_DE',
      views: { ...VIEWS, overview: () => Promise.reject(new Error('boom')) },
    });
    mounted = wrapper;
    await settle();
    expect($('#swg-main [role="alert"]')?.textContent).toContain(
      'Dieser Bereich konnte nicht geladen werden',
    );
    expect(button('Erneut versuchen')).toBeDefined();
  });

  it('a missing view registration fails loudly instead of rendering nothing', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const views = { ...VIEWS };
    delete (views as Record<string, unknown>).overview;
    const { wrapper } = await mountApp({ views });
    mounted = wrapper;
    await settle();
    expect($('#swg-main [role="alert"]')).not.toBeNull();
  });
});
