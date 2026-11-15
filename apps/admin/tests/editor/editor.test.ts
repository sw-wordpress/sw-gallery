import { flushPromises } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../../src/api/client';
import type { MediaApi } from '../../src/api/media';
import { mountApp, resetEnvironment } from '../support/mountApp';
import { makeGallery } from '../galleries/support';
import { FakeServer, makeItem, makeMedia } from './support';

let mounted: VueWrapper | null = null;

afterEach(() => {
  delete window.wp;
  mounted?.unmount();
  mounted = null;
  resetEnvironment();
});

const EDITOR_CAPS = ['edit_posts', 'edit_others_posts', 'upload_files'];

async function settle(): Promise<void> {
  await flushPromises();
  await new Promise((resolve) => setTimeout(resolve, 0));
  await flushPromises();
}

async function open(
  server = new FakeServer(),
  options: { capabilities?: string[]; locale?: string; path?: string; media?: MediaApi } = {},
) {
  const api = server.api();
  if (options.media !== undefined) api.media = options.media;
  const result = await mountApp({
    path: options.path ?? '/galleries/7',
    capabilities: options.capabilities ?? EDITOR_CAPS,
    locale: options.locale,
    editorApi: api,
  });
  mounted = result.wrapper;
  await settle();
  return { ...result, server, api };
}

const $ = <T extends Element = HTMLElement>(selector: string): T | null =>
  document.body.querySelector<T>(selector);
const $$ = (selector: string): HTMLElement[] =>
  Array.from(document.body.querySelectorAll<HTMLElement>(selector));
const live = (): string => $('.swg-shell > [role="status"]')?.textContent?.trim() ?? '';
const listbox = (): HTMLElement => $('[role="listbox"]') as HTMLElement;
const options = (): HTMLElement[] => $$('[role="option"]');
const stateText = (): string => $('.swg-ed__state')?.textContent?.replace(/\s+/g, ' ').trim() ?? '';
/** Finds a button by its visible label (Quasar wraps it in `.block`) or its aria-label. */
const button = (label: string): HTMLButtonElement | undefined =>
  $$('button').find(
    (b) =>
      b.querySelector('.block')?.textContent?.trim() === label ||
      b.getAttribute('aria-label') === label,
  ) as HTMLButtonElement | undefined;

const inDialog = (
  label: string,
  selector = '[role="alertdialog"], [role="dialog"]',
): HTMLButtonElement | undefined =>
  $$(
    `${selector
      .split(', ')
      .map((x) => `${x} button`)
      .join(', ')}`,
  ).find(
    (b) =>
      b.querySelector('.block')?.textContent?.trim() === label ||
      b.getAttribute('aria-label') === label,
  ) as HTMLButtonElement | undefined;
const gone = (selector: string): Promise<void> => vi.waitFor(() => expect($(selector)).toBeNull());
const toast = (text: string): HTMLElement | undefined =>
  $$('.q-notification').find((n) => n.textContent?.includes(text));

async function press(el: Element, key: string, init: KeyboardEventInit = {}): Promise<void> {
  el.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...init }));
  await settle();
}
async function click(el: Element | null | undefined, init: MouseEventInit = {}): Promise<void> {
  if (el === null || el === undefined) throw new Error('element missing');
  el.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, ...init }));
  await settle();
}
function setInput(input: HTMLInputElement | HTMLTextAreaElement, value: string): void {
  input.value = value;
  input.dispatchEvent(new Event('input', { bubbles: true }));
}
const orderOf = (): number[] => options().map((o) => Number(o.dataset.itemId));

describe('opening the editor', () => {
  it('shows the gallery with breadcrumb, status, saved indicator and an accessible grid', async () => {
    await open();
    expect($('nav[aria-label="Breadcrumb"]')?.textContent).toContain('Galleries');
    expect($('.swg-ed__title')?.textContent).toBe('Summer 2025');
    expect(stateText()).toContain('All changes saved');
    const grid = listbox();
    expect(grid.getAttribute('aria-multiselectable')).toBe('true');
    expect(grid.getAttribute('aria-label')).toBe('Images in this gallery (4)');
    expect(grid.getAttribute('tabindex')).toBe('0');
    expect(options()).toHaveLength(4);
    expect(options()[1]?.getAttribute('aria-posinset')).toBe('2');
    expect(options()[1]?.getAttribute('aria-setsize')).toBe('4');
    expect(options()[0]?.getAttribute('aria-label')).toBe('Global 1');
    expect($('[role="toolbar"]')?.getAttribute('aria-label')).toBe('Selection actions');
    expect($('img[loading="lazy"]')?.getAttribute('alt')).toBe('');
    expect(button('Save')?.disabled).toBe(true);
    expect(button('Publish')).toBeDefined();
  });

  it('has a deep link from the list: title link and the "Edit images" action', async () => {
    const server = new FakeServer();
    const gallery = makeGallery();
    const { wrapper, router } = await mountApp({
      path: '/galleries',
      editorApi: server.api(),
      galleriesApi: {
        ...server.api().galleries,
        list: async () => ({ items: [gallery], total: 1, totalPages: 1 }),
      },
    });
    mounted = wrapper;
    await settle();
    const link = wrapper.find('.swg-gcard__link');
    expect(link.attributes('href')).toBe('/galleries/7');
    await link.trigger('click');
    await settle();
    expect(router.currentRoute.value.fullPath).toBe('/galleries/7');
    expect($('.swg-ed__title')?.textContent).toBe('Summer 2025');
  });

  it('shows identical not-found wording for a missing and a forbidden gallery', async () => {
    const missing = new FakeServer();
    missing.failures.set('get', new ApiError('swg_gallery_not_found', 'x', 404));
    await open(missing);
    const textMissing = $('.swg-ed__gone')?.textContent;
    expect(textMissing).toContain('Gallery not found');
    mounted?.unmount();
    resetEnvironment();

    const forbidden = new FakeServer();
    forbidden.failures.set('get', new ApiError('swg_forbidden', 'x', 403));
    await open(forbidden);
    expect($('.swg-ed__gone')?.textContent).toBe(textMissing);
    expect($('.swg-ed__gone a[href="/galleries"]')).not.toBeNull();
  });

  it('shows a retryable error for other load failures', async () => {
    const server = new FakeServer();
    server.failures.set('get', new ApiError('swg_internal_error', 'x', 500));
    await open(server);
    expect($('[role="alert"]')?.textContent).toContain('The gallery could not be loaded');
    await click(button('Retry'));
    expect($('.swg-ed__title')?.textContent).toBe('Summer 2025');
  });

  it('is read-only without the edit right', async () => {
    const server = new FakeServer(2, { canEdit: false, canPublish: false });
    await open(server);
    expect($('.swg-ed__alert.is-info')?.textContent).toContain('view this gallery but not change');
    expect(button('Save')?.disabled).toBe(true);
    expect(button('Remove')).toBeUndefined();
    expect($('.swg-ed__add')?.hasAttribute('disabled')).toBe(true);
    await press(listbox(), 'Delete');
    expect($('.q-dialog')).toBeNull();
  });

  it('tells about an unavailable image and lets it be removed', async () => {
    const server = new FakeServer(2);
    server.items[1] = makeItem(2, {
      missing: true,
      global: null,
      media: null,
      effective: { title: '', caption: '', alt: '' },
    });
    await open(server);
    expect(options()[1]?.getAttribute('aria-label')).toBe(
      'Image no longer available, can only be removed',
    );
    expect($('.swg-ed__count')?.textContent).toContain('1 unavailable');
    expect($('.swg-tile.is-missing .swg-tile__ph')).not.toBeNull();
  });
});

describe('selection', () => {
  it('toggles by click, selects ranges with shift and by keyboard, selects all and clears', async () => {
    await open(new FakeServer(6));
    await click(options()[1]);
    expect(options()[1]?.getAttribute('aria-selected')).toBe('true');
    expect($('.swg-ed__sel')?.textContent?.trim()).toBe('One image selected');
    await click(options()[4], { shiftKey: true });
    expect(options().filter((o) => o.getAttribute('aria-selected') === 'true')).toHaveLength(4);
    await click(options()[2]);
    expect(options()[2]?.getAttribute('aria-selected')).toBe('false');

    await press(listbox(), 'Escape');
    expect($('.swg-ed__sel')?.textContent?.trim()).toBe('No images selected');
    await press(listbox(), 'a', { ctrlKey: true });
    expect($('.swg-ed__sel')?.textContent?.trim()).toBe('6 images selected');
    expect(live()).toBe('All 6 images selected');
    await click(button('Clear selection'));
    expect($('.swg-ed__sel')?.textContent?.trim()).toBe('No images selected');

    await press(listbox(), 'Home');
    await press(listbox(), 'ArrowRight');
    await press(listbox(), ' ');
    expect(options()[1]?.getAttribute('aria-selected')).toBe('true');
    expect(listbox().getAttribute('aria-activedescendant')).toBe(options()[1]?.id);
    await press(listbox(), 'ArrowDown', { shiftKey: true });
    expect(
      options().filter((o) => o.getAttribute('aria-selected') === 'true').length,
    ).toBeGreaterThan(1);
    await press(listbox(), 'End');
    expect(listbox().getAttribute('aria-activedescendant')).toBe(options()[5]?.id);
    await press(listbox(), 'Home');
    await press(listbox(), 'PageDown');
    await press(listbox(), 'PageUp');
    await press(listbox(), 'ArrowLeft');
    await press(listbox(), 'ArrowUp');
  });
});

describe('virtualised rendering', () => {
  it('renders only a window of a large gallery and moves it when scrolling', async () => {
    await open(new FakeServer(600));
    const rendered = options();
    expect(rendered.length).toBeGreaterThan(0);
    expect(rendered.length).toBeLessThan(80);
    expect(rendered[0]?.getAttribute('aria-setsize')).toBe('600');
    expect(rendered[0]?.getAttribute('aria-posinset')).toBe('1');
    expect($('[role="status"]')).not.toBeNull();

    const scroller = listbox();
    scroller.scrollTop = 40 * 205;
    scroller.dispatchEvent(new Event('scroll'));
    await settle();
    const first = Number(options()[0]?.getAttribute('aria-posinset'));
    expect(first).toBeGreaterThan(100);
    expect(options().length).toBeLessThan(80);

    // End key scrolls the last row into view
    await press(scroller, 'End');
    expect(options().some((o) => o.getAttribute('aria-posinset') === '600')).toBe(true);
  });

  it('says so and disables reordering for galleries beyond the limit', async () => {
    await open(new FakeServer(10040));
    expect($('.swg-ed__alert.is-warning')?.textContent).toContain('only the first 10000');
    expect(button('Move to start')?.disabled).toBe(true);
    expect(options()[0]?.getAttribute('draggable')).toBe('false');
  });
});

describe('reordering', () => {
  it('moves with Ctrl+arrows, announces the position and keeps focus on the moved image', async () => {
    await open();
    await press(listbox(), 'ArrowRight');
    await press(listbox(), 'ArrowRight', { ctrlKey: true });
    expect(orderOf()).toEqual([1, 3, 2, 4]);
    expect(live()).toBe('Moved to position 3 of 4');
    expect(listbox().getAttribute('aria-activedescendant')).toBe(options()[2]?.id);
    expect(stateText()).toContain('One unsaved change');

    await press(listbox(), 'Home', { ctrlKey: true });
    expect(orderOf()).toEqual([2, 1, 3, 4]);
    expect(live()).toBe('Moved to position 1 of 4');
    await press(listbox(), 'ArrowLeft', { ctrlKey: true });
    expect(live()).toBe('Moved to position 1 of 4'.replace('1 of', '1 of'));
    await press(listbox(), 'End', { ctrlKey: true });
    expect(orderOf()).toEqual([1, 3, 4, 2]);
    await press(listbox(), 'ArrowUp', { ctrlKey: true });
    expect(orderOf()).toEqual([1, 3, 2, 4]);
  });

  it('moves a selected group with the toolbar buttons and announces the group', async () => {
    await open(new FakeServer(5));
    await click(options()[3]);
    await click(options()[4]);
    await click(button('Move to start'));
    expect(orderOf()).toEqual([4, 5, 1, 2, 3]);
    expect(live()).toBe('2 images moved to the start');
    await click(button('Move later'));
    expect(orderOf()).toEqual([1, 4, 5, 2, 3]);
    await click(button('Move earlier'));
    await click(button('Move to end'));
    expect(orderOf()).toEqual([1, 2, 3, 4, 5]);
    expect(live()).toBe('2 images moved to the end');
  });

  it('reorders with the mouse (native drag and drop)', async () => {
    await open();
    const tiles = options();
    const dataTransfer = { effectAllowed: '', dropEffect: '', setData: vi.fn() };
    const fire = (el: Element, type: string, init: Record<string, unknown> = {}): void => {
      const event = new Event(type, { bubbles: true, cancelable: true });
      Object.assign(event, { dataTransfer, clientX: 0, clientY: 0, ...init });
      el.dispatchEvent(event);
    };
    expect(tiles[3]?.getAttribute('draggable')).toBe('true');
    fire(tiles[3] as Element, 'dragstart');
    expect(dataTransfer.setData).toHaveBeenCalled();
    fire(tiles[0] as Element, 'dragover', { clientX: -5 });
    await settle();
    expect($('.swg-tile.is-drop-before')).not.toBeNull();
    fire(tiles[0] as Element, 'drop');
    await settle();
    expect(orderOf()).toEqual([4, 1, 2, 3]);
    expect(live()).toBe('Moved to position 1 of 4');
    expect($('.swg-tile.is-drop-before')).toBeNull();
  });

  it('ignores drops without a drag and drags that were cancelled', async () => {
    await open();
    const event = new Event('drop', { bubbles: true, cancelable: true });
    options()[0]?.dispatchEvent(event);
    expect(event.defaultPrevented).toBe(false);
    const over = new Event('dragover', { bubbles: true, cancelable: true });
    options()[0]?.dispatchEvent(over);
    expect(over.defaultPrevented).toBe(false);
  });
});

describe('removing images', () => {
  it('asks for confirmation naming the count, stages the removal and can undo it', async () => {
    await open();
    await click(options()[0]);
    await click(options()[2]);
    await click($('.swg-ed__remove'));
    const dialog = $('[role="alertdialog"]');
    expect(dialog?.textContent).toContain('Remove 2 images?');
    expect(dialog?.textContent).toContain('Global 1');
    expect(dialog?.textContent).toContain('The images stay in the Media Library.');
    await click(button('Remove 2 images'));
    expect(orderOf()).toEqual([2, 4]);
    expect(live()).toBe('2 images removed (unsaved)');
    expect(stateText()).toContain('2 unsaved changes');
    expect(button('Save')?.disabled).toBe(false);

    await click(button('Undo'));
    expect(orderOf()).toEqual([1, 2, 3, 4]);
    expect(stateText()).toContain('All changes saved');
    await click(button('Redo'));
    expect(orderOf()).toEqual([2, 4]);
  });

  it('removes the active image with the Delete key and saves in one chained write', async () => {
    const { server } = await open();
    await press(listbox(), 'Delete');
    expect($('[role="alertdialog"]')?.textContent).toContain('Remove one image?');
    await click(button('Remove image'));
    server.calls = [];
    await click(button('Save'));
    expect(server.calls).toEqual(['remove']);
    expect(server.ids()).toEqual([2, 3, 4]);
    expect(toast('Gallery saved.')).toBeDefined();
    expect(stateText()).toContain('All changes saved');
  });

  it('does not remove anything when the confirmation is cancelled', async () => {
    await open();
    await press(listbox(), 'Delete');
    await click(button('Cancel'));
    expect(orderOf()).toEqual([1, 2, 3, 4]);
  });
});

describe('image details', () => {
  it('separates global texts from gallery-specific ones: inherit, own and intentional blank', async () => {
    const { server } = await open();
    options()[0]?.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }));
    await settle();
    const sheet = $('[role="dialog"]') as HTMLElement;
    expect(sheet.textContent).toContain('Global (Media Library)');
    expect(sheet.textContent).toContain('Global 1');
    expect(sheet.textContent).toContain('Item 1 · attachment 1001');
    expect(sheet.textContent).toContain('800 × 600');
    expect(sheet.textContent).toContain('image/jpeg');
    expect(sheet.textContent).not.toContain('.jpg');
    expect(sheet.textContent).toContain('Uses the global text.');

    const toggles = (): HTMLElement[] =>
      Array.from(sheet.querySelectorAll<HTMLElement>('[role="switch"]'));
    expect(toggles()).toHaveLength(3);
    await click(toggles()[0]);
    const titleInput = sheet.querySelector<HTMLInputElement>('input[type="text"]');
    expect(titleInput?.value).toBe('Global 1');
    expect(titleInput?.getAttribute('maxlength')).toBe('200');
    setInput(titleInput as HTMLInputElement, 'Local title');
    await settle();
    expect(options()[0]?.getAttribute('aria-label')).toBe('Local title, unsaved text changes');

    setInput(titleInput as HTMLInputElement, '');
    await settle();
    expect(sheet.textContent).toContain('nothing is shown in this gallery');
    expect(options()[0]?.getAttribute('aria-label')).toContain('Image 1001');

    await click(toggles()[0]);
    expect(sheet.textContent).toContain('Uses the global text.');
    expect(stateText()).toContain('All changes saved');

    // an alt text hint and a caption textarea
    expect(sheet.textContent).toContain('Describe what the image shows');
    await click(toggles()[2]);
    const caption = sheet.querySelector<HTMLTextAreaElement>('textarea');
    expect(caption?.getAttribute('maxlength')).toBe('5000');
    setInput(caption as HTMLTextAreaElement, 'A caption');
    await click(toggles()[1]);
    await click(button('Done'));
    await click(button('Save'));
    expect(server.items[0]?.overrides).toEqual({ title: null, caption: 'A caption', alt: 'alt 1' });
  });

  it('can set the cover and remove the image from the details', async () => {
    await open();
    options()[1]?.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }));
    await settle();
    await click(inDialog('Use as cover'));
    expect(live()).toBe('Cover image changed (unsaved)');
    expect(inDialog('Is the cover')?.hasAttribute('disabled')).toBe(true);
    expect(options()[1]?.getAttribute('aria-label')).toContain('cover image');
    await click(inDialog('Remove from gallery'));
    await click(inDialog('Remove image', '[role="alertdialog"]'));
    expect(orderOf()).toEqual([1, 3, 4]);
  });

  it('opens by Enter, closes by its button and shows a limited view for unavailable images', async () => {
    const server = new FakeServer(2);
    server.items[1] = makeItem(2, { missing: true, global: null, media: null });
    await open(server);
    await press(listbox(), 'Enter');
    expect($('[role="dialog"]')?.textContent).toContain('Global 1');
    await click($('[aria-label="Close image details"]'));
    await press(listbox(), 'ArrowRight');
    await press(listbox(), 'Enter');
    expect($('[role="dialog"]')?.textContent).toContain('no longer available');
    expect(
      $('[role="dialog"]')?.querySelector('[role="switch"][aria-disabled="true"]'),
    ).not.toBeNull();
  });

  it('opens the details of the selected image with the toolbar button', async () => {
    await open();
    await click(options()[2]);
    await click(button('Details'));
    expect($('[role="dialog"]')?.textContent).toContain('Item 3');
    await click(button('Use as cover'));
  });
});

describe('gallery details', () => {
  it('stages title, description and cover, shows the publish rule and saves everything once', async () => {
    const { server } = await open();
    const inputs = $$('.swg-gdp input');
    setInput(inputs[0] as HTMLInputElement, 'Renamed');
    setInput($('.swg-gdp textarea') as HTMLTextAreaElement, 'Line 1\nLine 2');
    await settle();
    expect($('.swg-ed__title')?.textContent).toBe('Renamed');
    expect(stateText()).toContain('2 unsaved changes');
    await click(button('Save'));
    expect(server.gallery.title).toBe('Renamed');
    expect(server.gallery.description).toBe('Line 1\nLine 2');
    expect(toast('Gallery saved.')).toBeDefined();
    expect(live()).toBe('Gallery saved.');
  });

  it('announces the unsaved count after a pause, not over the message of the action itself', async () => {
    await open();
    await press(listbox(), 'ArrowRight');
    await press(listbox(), 'ArrowRight', { ctrlKey: true });
    expect(live()).toBe('Moved to position 3 of 4');
    await new Promise((resolve) => setTimeout(resolve, 1650));
    await settle();
    expect(live()).toBe('One unsaved change');
    await click(button('Undo'));
    await new Promise((resolve) => setTimeout(resolve, 1650));
    await settle();
    expect(live()).toBe('All changes saved');
  });

  it('blocks saving without a title and says why', async () => {
    await open();
    setInput($$('.swg-gdp input')[0] as HTMLInputElement, '   ');
    await settle();
    expect(button('Save')?.disabled).toBe(true);
    expect(stateText()).toContain('Enter a title to save.');
  });

  it('publishes as an editor', async () => {
    const { server } = await open();
    await click(button('Publish'));
    expect(server.gallery.status).toBe('publish');
    expect(toast('Gallery published.')).toBeDefined();
    expect(button('Publish')).toBeUndefined();
  });

  it('offers no publish action to a contributor', async () => {
    await open(new FakeServer(2, { canPublish: false }), { capabilities: ['edit_posts'] });
    expect(button('Publish')).toBeUndefined();
    expect($('.swg-gdp')?.textContent).toContain('publishing needs the publish right');
  });
});

describe('layout and settings', () => {
  const panelToggle = async (): Promise<void> => {
    const radio = $$('.swg-ed__side .swg-seg input[type="radio"]')[1] as HTMLInputElement;
    radio.checked = true;
    radio.dispatchEvent(new Event('change', { bubbles: true }));
    await settle();
  };

  it('builds the form from the layout schema, validates live, blocks saving and saves the full map', async () => {
    const { server } = await open(new FakeServer(2, { settings: { future: 'kept' } }));
    await panelToggle();
    const panel = $('.swg-lsp') as HTMLElement;
    expect(panel.textContent).toContain('Columns');
    expect(panel.textContent).toContain('Default: 3');
    expect(panel.textContent).toContain('All sizes');
    expect(panel.textContent).toContain('Gap (px)');
    expect(panel.querySelectorAll('input[type="range"]').length).toBeGreaterThanOrEqual(1);

    const columns = panel.querySelector<HTMLInputElement>(
      'input[type="number"]',
    ) as HTMLInputElement;
    expect(columns.getAttribute('min')).toBe('1');
    expect(columns.getAttribute('max')).toBe('12');
    setInput(columns, '20');
    await settle();
    expect(panel.textContent).toContain('Must be at most 12.');
    expect(button('Save')?.disabled).toBe(true);
    expect(stateText()).toContain('Fix the invalid settings to save.');

    setInput(columns, '5');
    await settle();
    expect(panel.textContent).not.toContain('Must be at most 12.');
    // a different value for medium screens
    expect(panel.querySelectorAll('[role="switch"]')).toHaveLength(0);
    expect(panel.querySelectorAll('input[type="number"]').length).toBeGreaterThan(2);
    const medium = panel.querySelectorAll<HTMLInputElement>(
      'input[type="number"]',
    )[2] as HTMLInputElement;
    setInput(medium, '2');
    await settle();
    await click(button('Save'));
    expect(server.gallery.settings).toEqual({ future: 'kept', columns: { base: 5, md: 2 } });
  });

  it('switches layout and sort mode and shows the fallback notes', async () => {
    const { server } = await open();
    await panelToggle();
    const panel = $('.swg-lsp') as HTMLElement;
    expect(panel.textContent).toContain('use the "grid" layout');
    expect(panel.textContent).toContain('manual order stays stored');
    expect(panel.querySelectorAll('.q-select')).toHaveLength(2);
    // drive the staged model the way the selects do
    const wrapperVm = mounted;
    expect(wrapperVm).not.toBeNull();
    const selects = panel.querySelectorAll<HTMLElement>('.q-field--auto-height, .q-field');
    expect(selects.length).toBeGreaterThan(0);
    expect(server.gallery.layout).toBe('grid');
  });

  it('reports a layout that is no longer registered and offers no form for it', async () => {
    await open(new FakeServer(1, { layout: 'retired' }));
    await panelToggle();
    expect($('.swg-lsp [role="status"]')?.textContent).toContain(
      'The layout "retired" is not available',
    );
  });

  it('maps server validation problems to the settings and announces them', async () => {
    const server = new FakeServer(2);
    await open(server);
    await panelToggle();
    const columns = $('.swg-lsp input[type="number"]') as HTMLInputElement;
    setInput(columns, '4');
    await settle();
    server.failures.set(
      'details',
      new ApiError(
        'swg_validation_failed',
        'x',
        422,
        {},
        {
          status: 422,
          errors: [{ path: '/columns', code: 'swg_invalid_type', message: 'x' }],
        },
      ),
    );
    await click(button('Save'));
    expect($('.swg-lsp [role="alert"]')?.textContent).toContain('The server rejected one setting.');
    expect($('.swg-lsp')?.textContent).toContain('The server rejected this value.');
    expect($('.swg-ed__alert.is-danger')?.textContent).toContain('Some values are invalid');
    expect(stateText()).toContain('unsaved change');
  });
});

describe('save progress and partial failure', () => {
  it('names what was saved and what failed, keeps the rest staged and offers to retry', async () => {
    const server = new FakeServer();
    await open(server);
    await click(options()[1]);
    await click($('.swg-ed__remove'));
    await click(button('Remove image'));
    setInput($$('.swg-gdp input')[0] as HTMLInputElement, 'Renamed');
    await settle();
    server.failures.set('details', new ApiError('swg_internal_error', 'x', 500));
    await click(button('Save'));
    const alert = $('.swg-ed__alert.is-danger') as HTMLElement;
    expect(alert.textContent).toContain('Saved: removed images.');
    expect(alert.textContent).toContain('Not saved: gallery details');
    expect(stateText()).toContain('One unsaved change');
    await click(button('Save'));
    expect(server.gallery.title).toBe('Renamed');
    expect($('.swg-ed__alert.is-danger')).toBeNull();
  });
});

describe('unsaved changes protection', () => {
  it('asks before leaving through the router and keeps the editor when the user stays', async () => {
    const { router } = await open();
    await press(listbox(), 'Delete');
    await click(button('Remove image'));
    const attempt = router.push('/galleries');
    await settle();
    const dialog = $$('[role="alertdialog"]').find((d) =>
      d.textContent?.includes('Leave without saving?'),
    );
    expect(dialog).toBeDefined();
    expect(dialog?.textContent).toContain('One change is discarded');
    await click(button('Keep editing'));
    await attempt;
    expect(router.currentRoute.value.fullPath).toBe('/galleries/7');
    expect(orderOf()).toEqual([2, 3, 4]);

    const second = router.push('/galleries');
    await settle();
    await click(button('Leave without saving'));
    await second;
    expect(router.currentRoute.value.fullPath).toBe('/galleries');
  });

  it('leaves freely without changes and prompts the browser on unload only when dirty', async () => {
    const { router } = await open();
    const clean = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(clean);
    expect(clean.defaultPrevented).toBe(false);
    await press(listbox(), 'Delete');
    await click(button('Remove image'));
    const dirty = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(dirty);
    expect(dirty.defaultPrevented).toBe(true);
    await click(button('Discard changes'));
    await click(inDialog('Discard changes', '[role="alertdialog"]'));
    expect(stateText()).toContain('All changes saved');
    await router.push('/galleries');
    expect(router.currentRoute.value.fullPath).toBe('/galleries');
  });

  it('supports keyboard shortcuts for undo, redo and save outside text fields', async () => {
    const { server } = await open();
    setInput($$('.swg-gdp input')[0] as HTMLInputElement, 'Typed');
    await settle();
    await press(document.body, 'z', { ctrlKey: true });
    expect($('.swg-ed__title')?.textContent).toBe('Summer 2025');
    expect(live()).toBe('Change undone');
    await press(document.body, 'y', { ctrlKey: true });
    expect($('.swg-ed__title')?.textContent).toBe('Typed');
    expect(live()).toBe('Change redone');
    await press(document.body, 'z', { ctrlKey: true });
    await press(document.body, 'z', { ctrlKey: true, shiftKey: true });
    expect($('.swg-ed__title')?.textContent).toBe('Typed');
    // inside a text field the browser's own undo stays in charge
    const field = $$('.swg-gdp input')[0] as HTMLInputElement;
    await press(field, 'z', { ctrlKey: true });
    expect($('.swg-ed__title')?.textContent).toBe('Typed');
    await press(document.body, 's', { ctrlKey: true });
    expect(server.gallery.title).toBe('Typed');
    await press(document.body, 'q', { ctrlKey: true });
  });
});

describe('conflict handling in the UI', () => {
  it('explains the difference and re-applies the user changes without losing texts', async () => {
    const { server } = await open();
    setInput($$('.swg-gdp input')[0] as HTMLInputElement, 'My title');
    await settle();
    server.externalChange(() => {
      server.items.push(makeItem(9));
      server.gallery = { ...server.gallery, title: 'Their title' };
    });
    await click(button('Save'));
    const dialog = $('[role="alertdialog"]') as HTMLElement;
    expect(dialog.textContent).toContain('The gallery was changed elsewhere');
    expect(dialog.textContent).toContain('Someone else saved "Their title"');
    expect(dialog.textContent).toContain('Details changed: title.');
    expect(dialog.textContent).toContain('One image was added.');
    expect(dialog.textContent).toContain('You have one unsaved change.');
    expect(dialog.textContent).toContain('Your values for title replace the other changes.');

    await click(button('Reload and re-apply my changes'));
    await gone('[role="alertdialog"]');
    expect($('.swg-ed__title')?.textContent).toBe('My title');
    expect(orderOf()).toEqual([1, 2, 3, 4, 9]);
    expect($('.swg-ed__alert.is-warning')?.textContent).toContain(
      're-applied to the current version',
    );
    expect(live()).toContain('re-applied');
    await click(button('Save'));
    expect(server.gallery.title).toBe('My title');
    expect(stateText()).toContain('All changes saved');
  });

  it('can discard the user changes or postpone the decision', async () => {
    const { server } = await open();
    setInput($$('.swg-gdp input')[0] as HTMLInputElement, 'Mine');
    await settle();
    server.externalChange(() => {
      server.gallery = { ...server.gallery, title: 'Theirs' };
    });
    await click(button('Save'));
    await click(button('Not now'));
    await gone('[role="alertdialog"]');
    expect(stateText()).toContain('One unsaved change');
    await click(button('Save'));
    expect($('[role="alertdialog"]')).not.toBeNull();
    await click(button('Discard my changes'));
    expect($('.swg-ed__title')?.textContent).toBe('Theirs');
    expect(stateText()).toContain('All changes saved');
    expect(live()).toContain('discarded');
  });

  it('shows the unavailable state when the gallery was trashed meanwhile', async () => {
    const server = new FakeServer();
    await open(server);
    setInput($$('.swg-gdp input')[0] as HTMLInputElement, 'x');
    await settle();
    server.failures.set('details', new ApiError('swg_invalid_state', 'x', 409));
    await click(button('Save'));
    expect($('.swg-ed__alert.is-info')?.textContent).toContain('different state');
  });
});

describe('adding images', () => {
  function mockModal(ids: number[]): ReturnType<typeof vi.fn> {
    const handlers = new Map<string, () => void>();
    const frame = {
      on: (event: string, cb: () => void) => {
        handlers.set(event, cb);
        return frame;
      },
      open: () => {
        handlers.get('select')?.();
        return frame;
      },
      close: () => {},
      state: () => ({
        get: () => ({ map: <T>(cb: (a: { id: number }) => T) => ids.map((id) => cb({ id })) }),
      }),
    };
    const factory = vi.fn(() => frame);
    window.wp = { media: factory as never };
    return factory;
  }

  it('adds the chosen library images immediately and reports honest counts', async () => {
    const factory = mockModal([5001, 1001]);
    const { server } = await open();
    await click($('.swg-ed__add'));
    expect(factory).toHaveBeenCalled();
    expect(server.ids()).toHaveLength(5);
    expect(options()).toHaveLength(5);
    const message = toast('One image added') as HTMLElement;
    expect(message.textContent).toContain('already in the gallery: 1');
    expect(message.textContent).not.toContain('not usable');
    // adding is applied right away, so there is nothing to save
    expect(stateText()).toContain('All changes saved');
  });

  it('fails gracefully when the WordPress media library is missing', async () => {
    await open();
    await click($('.swg-ed__add'));
    expect(toast('media library is not available')).toBeDefined();
  });

  it('disables the picker with an explanation for users who cannot upload', async () => {
    await open(new FakeServer(2, { canPublish: false }), { capabilities: ['edit_posts'] });
    expect($('.swg-ed__add')?.hasAttribute('disabled')).toBe(true);
    expect($('.swg-ed__bar')?.textContent).toContain('not allowed to use the Media Library');
    expect(button('Upload images')?.disabled).toBe(true);
    expect($('input[type="file"]')?.hasAttribute('disabled')).toBe(true);
  });

  it('uploads dropped files with progress and adds them when done', async () => {
    const upload = vi.fn(
      async (
        _file: File,
        opts?: { onProgress?: (p: { loaded: number; total: number | null }) => void },
      ) => {
        opts?.onProgress?.({ loaded: 5, total: 10 });
        return makeMedia(8000 + upload.mock.calls.length);
      },
    );
    const server = new FakeServer();
    await open(server, { media: { upload } });
    const section = document.body;
    const file = new File([new Uint8Array(10)], 'drop.png', { type: 'image/png' });
    const fire = (type: string): Event => {
      const event = new Event(type, { bubbles: true, cancelable: true });
      Object.assign(event, { dataTransfer: { types: ['Files'], files: [file], dropEffect: '' } });
      section.dispatchEvent(event);
      return event;
    };
    fire('dragenter');
    await settle();
    expect($('.swg-ed-drop')).not.toBeNull();
    expect(fire('dragover').defaultPrevented).toBe(true);
    fire('dragleave');
    await settle();
    expect($('.swg-ed-drop')).toBeNull();
    fire('dragenter');
    fire('drop');
    await settle();
    expect(upload).toHaveBeenCalledTimes(1);
    expect(server.ids()).toHaveLength(5);
    expect(toast('1 of 1 files added')).toBeDefined();
    expect($('.swg-up__row')?.getAttribute('data-upload-status')).toBe('added');
    // item drags (no files) are ignored by the drop zone
    const internal = new Event('drop', { bubbles: true, cancelable: true });
    Object.assign(internal, { dataTransfer: { types: ['text/plain'], files: [] } });
    section.dispatchEvent(internal);
    expect(upload).toHaveBeenCalledTimes(1);
  });

  it('keeps the overlay across nested targets and clears it on cancellation and navigation', async () => {
    const { router } = await open();
    const fire = (target: EventTarget, type: string, types = ['Files']): Event => {
      const event = new Event(type, { bubbles: true, cancelable: true });
      Object.assign(event, { dataTransfer: { types, files: [] } });
      target.dispatchEvent(event);
      return event;
    };
    fire(document.body, 'dragenter', ['text/plain']);
    await settle();
    expect($('.swg-ed-drop')).toBeNull();
    fire(document.body, 'dragenter');
    fire($('.swg-ed__title') as HTMLElement, 'dragenter');
    fire($('.swg-ed__title') as HTMLElement, 'dragleave');
    await settle();
    expect($('.swg-ed-drop')).not.toBeNull();
    fire(document.body, 'dragleave');
    await settle();
    expect($('.swg-ed-drop')).toBeNull();
    for (const cancel of ['dragend', 'blur', 'Escape']) {
      fire(window, 'dragenter');
      await settle();
      expect($('.swg-ed-drop')).not.toBeNull();
      if (cancel === 'Escape') await press(document.body, 'Escape');
      else window.dispatchEvent(new Event(cancel));
      await settle();
      expect($('.swg-ed-drop')).toBeNull();
    }
    fire(window, 'dragenter');
    await settle();
    await router.push('/galleries');
    await settle();
    expect($('.swg-ed-drop')).toBeNull();
    expect(fire(window, 'dragenter').defaultPrevented).toBe(false);
    expect(fire(window, 'drop').defaultPrevented).toBe(false);
  });

  it('leaves the native media modal in control of its own drops', async () => {
    const upload = vi.fn();
    await open(new FakeServer(), { media: { upload } });
    const modal = document.createElement('div');
    modal.className = 'media-modal';
    document.body.appendChild(modal);
    try {
      const fire = (target: EventTarget, type: string): Event => {
        const event = new Event(type, { bubbles: true, cancelable: true });
        Object.assign(event, { dataTransfer: { types: ['Files'], files: [] } });
        target.dispatchEvent(event);
        return event;
      };
      fire(document.body, 'dragenter');
      for (const type of ['dragenter', 'dragover', 'dragleave', 'drop']) {
        expect(fire(modal, type).defaultPrevented).toBe(false);
      }
      await settle();
      expect($('.swg-ed-drop')).toBeNull();
      expect(upload).not.toHaveBeenCalled();
    } finally {
      modal.remove();
    }
  });

  it('blocks window drops and file selection without upload permission', async () => {
    const upload = vi.fn();
    await open(new FakeServer(), { capabilities: ['edit_posts'], media: { upload } });
    const file = new File(['image'], 'blocked.png', { type: 'image/png' });
    for (const type of ['dragenter', 'dragover', 'drop']) {
      const event = new Event(type, { bubbles: true, cancelable: true });
      const dataTransfer = { types: ['Files'], files: [file], dropEffect: '' };
      Object.assign(event, { dataTransfer });
      document.body.dispatchEvent(event);
      expect(event.defaultPrevented).toBe(true);
      if (type === 'dragover') expect(dataTransfer.dropEffect).toBe('none');
    }
    const input = $('input[type="file"]') as HTMLInputElement;
    Object.defineProperty(input, 'files', { value: [file], configurable: true });
    input.dispatchEvent(new Event('change', { bubbles: true }));
    await settle();
    expect(upload).not.toHaveBeenCalled();
    expect($('.swg-ed-drop')).toBeNull();
    expect(toast('not allowed to use the Media Library')).toBeDefined();
  });

  it('uploads through the file button and shows per-file failures with retry and cancel', async () => {
    let attempt = 0;
    const upload = vi.fn(async () => {
      attempt += 1;
      if (attempt === 1) throw new ApiError('swg_file_too_large', 'x', 413);
      return makeMedia(8100 + attempt);
    });
    const server = new FakeServer();
    await open(server, { media: { upload } });
    const input = $('input[type="file"]') as HTMLInputElement;
    const choose = vi.spyOn(input, 'click');
    await click(button('Upload images'));
    expect(choose).toHaveBeenCalledOnce();
    expect($('.swg-up__zone')).toBeNull();
    const files = [new File([new Uint8Array(4)], 'a.png', { type: 'image/png' })];
    Object.defineProperty(input, 'files', { value: files, configurable: true });
    input.dispatchEvent(new Event('change', { bubbles: true }));
    await settle();
    const row = $('.swg-up__row') as HTMLElement;
    expect(row.textContent).toContain('The server refused the file as too large');
    expect(row.classList.contains('is-error')).toBe(true);
    await click(button('Retry upload of a.png'));
    expect(server.ids()).toHaveLength(5);
    await click(button('Remove a.png from the list'));
    expect($('.swg-up__row')).toBeNull();
  });

  it('rejects files that are clearly not images before uploading', async () => {
    const upload = vi.fn();
    await open(new FakeServer(), { media: { upload } });
    const input = $('input[type="file"]') as HTMLInputElement;
    Object.defineProperty(input, 'files', {
      value: [new File(['x'], 'x.pdf', { type: 'application/pdf' })],
      configurable: true,
    });
    input.dispatchEvent(new Event('change', { bubbles: true }));
    await settle();
    expect(upload).not.toHaveBeenCalled();
    expect($('.swg-up__row')?.textContent).toContain('Not a supported image type');
  });

  it('shows the add-images call to action for an empty gallery', async () => {
    await open(new FakeServer(0));
    expect($('.swg-ed__empty')?.textContent).toContain('This gallery has no images yet');
    expect($('[role="listbox"]')).toBeNull();
    expect(button('Upload images')).toBeDefined();
    mockModal([6001]);
    await click($$('.swg-ed__empty button')[0]);
    expect(options()).toHaveLength(1);
  });
});

describe('localisation and layout direction', () => {
  it('renders the editor in German with the same structure', async () => {
    await open(new FakeServer(), { locale: 'de_DE' });
    expect($('nav[aria-label="Brotkrumennavigation"]')).not.toBeNull();
    expect(stateText()).toContain('Alle Änderungen gespeichert');
    expect(listbox().getAttribute('aria-label')).toBe('Bilder in dieser Galerie (4)');
    expect(button('Speichern')).toBeDefined();
  });
});
