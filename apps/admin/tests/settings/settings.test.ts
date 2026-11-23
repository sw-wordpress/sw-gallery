import { flushPromises } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { ApiError } from '../../src/api/client';
import { mountApp, resetEnvironment } from '../support/mountApp';
import { NOTICE, fakeSettingsApi, makeSettings } from './support';

let mounted: VueWrapper | null = null;

afterEach(() => {
  mounted?.unmount();
  mounted = null;
  resetEnvironment();
});

const $ = <T extends Element = HTMLElement>(selector: string): T | null =>
  document.body.querySelector<T>(selector);
const $$ = (selector: string): HTMLElement[] =>
  Array.from(document.body.querySelectorAll<HTMLElement>(selector));
const live = (): string => $('.swg-shell > [role="status"]')?.textContent?.trim() ?? '';
const button = (text: string): HTMLElement | undefined =>
  $$('button').find((b) => b.textContent?.includes(text));

async function settle(): Promise<void> {
  await flushPromises();
  await new Promise((resolve) => setTimeout(resolve, 0));
  await flushPromises();
}

async function open(
  api: ReturnType<typeof fakeSettingsApi>,
  options: { capabilities?: string[]; locale?: string } = {},
) {
  const result = await mountApp({ path: '/settings', settingsApi: api, ...options });
  mounted = result.wrapper;
  await settle();
  return result;
}

async function typeItems(value: string): Promise<void> {
  const input = $<HTMLInputElement>('.swg-settings input[type="number"]');
  if (input === null) throw new Error('no number input');
  input.value = value;
  input.dispatchEvent(new Event('input', { bubbles: true }));
  await settle();
}
const saveButton = (): HTMLButtonElement => {
  const el = button('Save settings');
  if (el === undefined) throw new Error('no save button');
  return el as HTMLButtonElement;
};
const isDisabled = (el: HTMLElement): boolean =>
  el.hasAttribute('disabled') || el.getAttribute('aria-disabled') === 'true';
const deleteToggle = (): HTMLElement => {
  const el = $$('.q-toggle').find((t) => t.textContent?.includes('Delete all plugin data'));
  if (el === undefined) throw new Error('no delete toggle');
  return el;
};

describe('Settings page', () => {
  it('shows the stored values with their defaults and the attachment guarantee', async () => {
    const api = fakeSettingsApi(makeSettings({ itemsPerPage: 40, lightbox: false }));
    await open(api);
    expect($<HTMLInputElement>('.swg-settings input[type="number"]')?.value).toBe('40');
    expect($<HTMLSelectElement>('.swg-settings__select')?.value).toBe('grid');
    expect($$('.swg-settings__select option').map((o) => o.textContent)).toEqual([
      'Grid',
      'Masonry',
    ]);
    const text = $('.swg-settings')?.textContent ?? '';
    expect(text).toContain('Default: Grid');
    expect(text).toContain('Default: 24');
    expect(text).toContain('Default: on');
    expect(text).toContain('Images in the Media Library are never deleted');
    expect(text).toContain(NOTICE);
    expect(text).not.toContain('MCP access');
    expect(isDisabled(saveButton())).toBe(true);
  });

  it('shows the configured and effective MCP levels and saves only the changed level', async () => {
    const api = fakeSettingsApi(
      makeSettings({ mcpAccessLevel: 'write', mcpEffectiveLevel: 'read' }),
    );
    await open(api);
    const select = $<HTMLSelectElement>('select[id^="swg-set-mcp"]');
    expect(select?.value).toBe('write');
    expect($('.swg-settings')?.textContent).toContain('Effective level: Read only');
    if (!select) throw new Error('MCP access control missing');
    select.value = 'off';
    select.dispatchEvent(new Event('change', { bubbles: true }));
    await settle();
    saveButton().click();
    await settle();
    expect(api.settings.update).toHaveBeenCalledExactlyOnceWith({ mcpAccessLevel: 'off' });
  });

  it('validates the number inline with the server bounds and blocks saving', async () => {
    const api = fakeSettingsApi();
    await open(api);
    for (const [value, message] of [
      ['0', 'Must be at least 1.'],
      ['201', 'Must be at most 200.'],
      ['', 'Enter a number.'],
      ['2.5', 'Enter a whole number.'],
    ] as const) {
      await typeItems(value);
      expect($('.swg-settings .q-field__messages')?.textContent).toContain(message);
      expect(isDisabled(saveButton())).toBe(true);
    }
    await typeItems('200');
    expect($('.swg-settings .q-field--error')).toBeNull();
    expect(isDisabled(saveButton())).toBe(false);
    expect(api.settings.update).not.toHaveBeenCalled();
  });

  it('sends only the changed fields and reports the save honestly', async () => {
    const api = fakeSettingsApi();
    await open(api);
    await typeItems('50');
    expect($('.swg-settings [role="status"]')?.textContent).toContain('unsaved changes');
    saveButton().click();
    await settle();
    expect(api.settings.update).toHaveBeenCalledTimes(1);
    expect(api.settings.update).toHaveBeenCalledWith({ itemsPerPage: 50 });
    expect(live()).toBe('Settings saved.');
    expect(isDisabled(saveButton())).toBe(true);
    // lightbox switch and layout select
    $$('.q-toggle')
      .find((t) => t.textContent?.includes('lightbox'))
      ?.click();
    const select = $<HTMLSelectElement>('.swg-settings__select');
    if (select === null) throw new Error('no select');
    select.value = 'masonry';
    select.dispatchEvent(new Event('change', { bubbles: true }));
    await settle();
    saveButton().click();
    await settle();
    expect(api.settings.update).toHaveBeenLastCalledWith({
      defaultLayout: 'masonry',
      lightbox: false,
    });
  });

  it('sends the hover defaults when the checkboxes change and shows the animation select', async () => {
    const api = fakeSettingsApi();
    await open(api);
    const boxes = $$('.swg-settings .q-checkbox');
    const title = boxes.find((b) => b.textContent?.includes('Show the title on hover'));
    const caption = boxes.find((b) => b.textContent?.includes('Show the caption on hover'));
    if (title === undefined || caption === undefined) throw new Error('no hover checkboxes');
    expect($('.swg-settings')?.textContent).toContain('Animation');
    title.click();
    caption.click();
    await settle();
    saveButton().click();
    await settle();
    expect(api.settings.update).toHaveBeenCalledWith({ hoverTitle: true, hoverCaption: true });
  });

  it('discards edits and resets single fields to the default', async () => {
    const api = fakeSettingsApi(makeSettings({ itemsPerPage: 60, defaultLayout: 'masonry' }));
    await open(api);
    expect(button('Reset to default')).toBeDefined();
    $$('button')
      .filter((b) => b.textContent?.includes('Reset to default'))
      .forEach((b) => b.click());
    await settle();
    expect($<HTMLInputElement>('.swg-settings input[type="number"]')?.value).toBe('24');
    expect($<HTMLSelectElement>('.swg-settings__select')?.value).toBe('grid');
    expect(isDisabled(saveButton())).toBe(false);
    button('Discard changes')?.click();
    await settle();
    expect($<HTMLInputElement>('.swg-settings input[type="number"]')?.value).toBe('60');
    expect(isDisabled(saveButton())).toBe(true);
  });

  it('turning the uninstall deletion on needs the typed word and sends confirmDeleteData', async () => {
    const api = fakeSettingsApi();
    await open(api);
    deleteToggle().click();
    await settle();
    const dialog = $('.swg-cd');
    expect(dialog).not.toBeNull();
    expect(dialog?.textContent).toContain(NOTICE);
    expect(dialog?.textContent).toContain('Images in the Media Library stay untouched.');
    const confirm = $$('.swg-cd button').find((b) => b.textContent?.includes('Turn on'));
    expect(isDisabled(confirm as HTMLElement)).toBe(true);
    const typed = $<HTMLInputElement>('.swg-cd input');
    if (typed === null) throw new Error('no confirmation input');
    typed.value = 'delete';
    typed.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();
    expect(isDisabled(confirm as HTMLElement)).toBe(true);
    typed.value = 'DELETE';
    typed.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();
    confirm?.click();
    await settle();
    expect($('.swg-cd')).toBeNull();
    expect(api.settings.update).not.toHaveBeenCalled(); // staged until "Save"
    saveButton().click();
    await settle();
    expect(api.settings.update).toHaveBeenCalledWith({
      deleteDataOnUninstall: true,
      confirmDeleteData: true,
    });
    // turning it off again needs no confirmation
    deleteToggle().click();
    await settle();
    expect($('.swg-cd')).toBeNull();
    saveButton().click();
    await settle();
    expect(api.settings.update).toHaveBeenLastCalledWith({ deleteDataOnUninstall: false });
  });

  it('cancelling the confirmation keeps the setting off', async () => {
    const api = fakeSettingsApi();
    await open(api);
    deleteToggle().click();
    await settle();
    $$('.swg-cd button')
      .find((b) => b.textContent?.includes('Cancel'))
      ?.click();
    await settle();
    expect(deleteToggle().getAttribute('aria-checked')).toBe('false');
    expect(isDisabled(saveButton())).toBe(true);
  });

  it('handles swg_confirmation_required gracefully (nothing changed, asks again)', async () => {
    const api = fakeSettingsApi();
    api.settings.update.mockRejectedValueOnce(
      new ApiError('swg_confirmation_required', 'confirm', 422),
    );
    await open(api);
    deleteToggle().click();
    await settle();
    const typed = $<HTMLInputElement>('.swg-cd input');
    if (typed === null) throw new Error('no confirmation input');
    typed.value = 'DELETE';
    typed.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();
    $$('.swg-cd button')
      .find((b) => b.textContent?.includes('Turn on'))
      ?.click();
    await settle();
    saveButton().click();
    await settle();
    expect($('.swg-settings__error')?.textContent).toContain('must be confirmed explicitly');
    expect(deleteToggle().getAttribute('aria-checked')).toBe('false');
  });

  it('shows server-side field problems and keeps the draft', async () => {
    const api = fakeSettingsApi();
    api.settings.update.mockRejectedValueOnce(
      new ApiError('rest_invalid_param', 'bad', 400, { itemsPerPage: 'bad' }),
    );
    await open(api);
    await typeItems('50');
    saveButton().click();
    await settle();
    expect($('.swg-settings .q-field__messages')?.textContent).toContain('server rejected');
    expect($<HTMLInputElement>('.swg-settings input[type="number"]')?.value).toBe('50');
    // editing the field clears its problem
    await typeItems('51');
    expect($('.swg-settings .q-field--error')).toBeNull();
  });

  it('refreshes the layouts when the server does not know the chosen one', async () => {
    const api = fakeSettingsApi();
    api.settings.update.mockRejectedValueOnce(new ApiError('swg_unknown_layout', 'x', 422));
    await open(api);
    const select = $<HTMLSelectElement>('.swg-settings__select');
    if (select === null) throw new Error('no select');
    select.value = 'masonry';
    select.dispatchEvent(new Event('change', { bubbles: true }));
    await settle();
    saveButton().click();
    await settle();
    expect(api.layouts.list).toHaveBeenCalledTimes(2);
    expect($('.swg-settings__error')?.textContent).toContain('layout is not available');
    expect($('.swg-settings__problem')?.textContent).toContain('Pick another one');
    select.value = 'grid';
    select.dispatchEvent(new Event('change', { bubbles: true }));
    await settle();
    expect($('.swg-settings__problem')).toBeNull();
  });

  it('shows a generic failure and a stored layout that is no longer registered', async () => {
    const api = fakeSettingsApi(makeSettings({ defaultLayout: 'gone' }));
    api.settings.update.mockRejectedValueOnce(new ApiError('swg_http_500', 'x', 500));
    await open(api);
    expect($$('.swg-settings__select option').map((o) => o.textContent)[0]).toBe(
      'gone (not available)',
    );
    await typeItems('30');
    saveButton().click();
    await settle();
    expect($('.swg-settings__error')?.textContent).toContain('server');
    expect(isDisabled(saveButton())).toBe(false);
  });

  it('shows a load error with retry', async () => {
    const api = fakeSettingsApi();
    api.settings.get.mockRejectedValueOnce(new ApiError('swg_http_500', 'x', 500));
    await open(api);
    expect(document.body.textContent).toContain('The settings could not be loaded');
    button('Retry')?.click();
    await settle();
    expect($('.swg-settings__form')).not.toBeNull();
  });

  it('asks before leaving with unsaved changes', async () => {
    const api = fakeSettingsApi();
    const { router } = await open(api);
    await typeItems('77');
    void router.push('/galleries');
    await settle();
    expect(router.currentRoute.value.path).toBe('/settings');
    expect($('.swg-cd')?.textContent).toContain('Leave without saving?');
    $$('.swg-cd button')
      .find((b) => b.textContent?.includes('Keep editing'))
      ?.click();
    await settle();
    expect(router.currentRoute.value.path).toBe('/settings');
    const second = router.push('/galleries');
    await settle();
    $$('.swg-cd button')
      .reverse()
      .find((b) => b.textContent?.includes('Leave without saving'))
      ?.click();
    await second;
    expect(router.currentRoute.value.path).toBe('/galleries');
  });

  it('is not available without manage_options', async () => {
    const api = fakeSettingsApi();
    await open(api, { capabilities: ['edit_posts', 'upload_files'] });
    expect(api.settings.get).not.toHaveBeenCalled();
    expect(document.body.textContent).toContain('No access');
    expect($$('.swg-nav-link').map((a) => a.textContent?.trim())).not.toContain('Settings');
  });

  it('is available in German with the German confirmation word', async () => {
    const api = fakeSettingsApi();
    await open(api, { locale: 'de_DE' });
    expect($('.swg-settings')?.textContent).toContain('Bilder pro Seite');
    $$('.q-toggle')
      .find((t) => t.textContent?.includes('Alle Plugin-Daten'))
      ?.click();
    await settle();
    expect($('.swg-cd')?.textContent).toContain('Tippe LÖSCHEN zur Bestätigung');
  });
});
