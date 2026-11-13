import { flushPromises } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError, ConflictError } from '../../src/api/client';
import { MEDIA_VIEW_KEY } from '../../src/stores/app';
import { mountApp, resetEnvironment } from '../support/mountApp';
import { lastMediaQuery, makeMedia, mediaPage, mockMediaApi } from './support';
import type { MockMediaFeature } from './support';

let mounted: VueWrapper | null = null;

afterEach(() => {
  vi.useRealTimers();
  mounted?.unmount();
  mounted = null;
  resetEnvironment();
});

const $ = <T extends Element = HTMLElement>(selector: string): T | null =>
  document.body.querySelector<T>(selector);
const $$ = (selector: string): HTMLElement[] =>
  Array.from(document.body.querySelectorAll<HTMLElement>(selector));
const live = (): string => $('.swg-shell > [role="status"]')?.textContent?.trim() ?? '';
const toast = (text: string): HTMLElement | undefined =>
  $$('.q-notification').find((n) => n.textContent?.includes(text));

async function settle(): Promise<void> {
  await flushPromises();
  await new Promise((resolve) => setTimeout(resolve, 0));
  await flushPromises();
}

async function open(
  api: MockMediaFeature,
  options: { path?: string; capabilities?: string[]; locale?: string } = {},
) {
  const result = await mountApp({ path: '/media', mediaApi: api, ...options });
  mounted = result.wrapper;
  await settle();
  return result;
}

const tiles = (): HTMLElement[] => $$('.swg-mtile');
const checkbox = (id: number): HTMLElement => {
  const el = $(`[data-media-id="${id}"] [role="checkbox"]`);
  if (el === null) throw new Error(`no checkbox for ${id}`);
  return el;
};
const images = [1, 2, 3, 4].map((id) =>
  makeMedia(id, { usedInGalleries: id === 2 ? 2 : id === 3 ? 1 : 0 }),
);

describe('Media Overview: list', () => {
  it('renders the grid with usage counts and queries the server with the defaults', async () => {
    const api = mockMediaApi(images);
    await open(api);
    expect(tiles()).toHaveLength(4);
    expect(lastMediaQuery(api)).toMatchObject({
      orderby: 'date',
      order: 'desc',
      page: 1,
      perPage: 40,
    });
    const usage = (id: number): string =>
      $(`[data-media-id="${id}"] .swg-chip__text`)?.textContent?.trim() ?? '';
    expect(usage(1)).toBe('Not used yet');
    expect(usage(2)).toBe('Used in 2 galleries');
    expect(usage(3)).toBe('Used in one gallery');
    expect(document.body.textContent).toContain('4 images');
  });

  it('takes the query from the URL (deep link) and ignores the uploader filter without edit_others_posts', async () => {
    const api = mockMediaApi(images);
    await open(api, { path: '/media?search=beach&orderby=title&order=desc&page=2&uploadedBy=5' });
    expect(lastMediaQuery(api)).toMatchObject({
      search: 'beach',
      orderby: 'title',
      order: 'desc',
      page: 2,
      uploadedBy: 5,
    });
    expect($('.swg-mtoolbar__user')).not.toBeNull();
    mounted?.unmount();
    resetEnvironment();
    const api2 = mockMediaApi(images);
    await open(api2, {
      path: '/media?uploadedBy=5',
      capabilities: ['edit_posts', 'upload_files'],
    });
    expect(lastMediaQuery(api2).uploadedBy).toBeUndefined();
    expect($('.swg-mtoolbar__user')).toBeNull();
  });

  it('debounces the search into the URL and the request', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const api = mockMediaApi(images);
    const { router } = await open(api);
    const input = $<HTMLInputElement>('.swg-mtoolbar__search input');
    if (input === null) throw new Error('no search field');
    input.value = 'sun';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
    expect(router.currentRoute.value.query.search).toBeUndefined();
    await vi.advanceTimersByTimeAsync(350);
    await settle();
    expect(router.currentRoute.value.query.search).toBe('sun');
    expect(lastMediaQuery(api)).toMatchObject({ search: 'sun', page: 1 });
    // clear button
    $<HTMLButtonElement>('.swg-mtoolbar__search button')?.click();
    await settle();
    expect(router.currentRoute.value.query.search).toBeUndefined();
  });

  it('sorts through the select and keeps defaults out of the URL', async () => {
    const api = mockMediaApi(images);
    const { router } = await open(api);
    const select = $<HTMLSelectElement>('.swg-mtoolbar__sort select');
    if (select === null) throw new Error('no select');
    select.value = 'title:asc';
    select.dispatchEvent(new Event('change', { bubbles: true }));
    await settle();
    expect(router.currentRoute.value.query).toEqual({ orderby: 'title' });
    expect(lastMediaQuery(api)).toMatchObject({ orderby: 'title', order: 'asc' });
    select.value = 'date:asc';
    select.dispatchEvent(new Event('change', { bubbles: true }));
    await settle();
    expect(router.currentRoute.value.query).toEqual({ order: 'asc' });
    select.value = 'bogus';
    select.dispatchEvent(new Event('change', { bubbles: true }));
    await settle();
    expect(router.currentRoute.value.query).toEqual({ order: 'asc' });
  });

  it('pages on the server and shows the page position', async () => {
    const api = mockMediaApi(images);
    api.media.list.mockResolvedValue(mediaPage(images, 90, 3));
    const { router } = await open(api, { path: '/media?page=2' });
    expect(document.body.textContent).toContain('Page 2 of 3');
    $<HTMLButtonElement>('button[aria-label="Next page"]')?.click();
    await settle();
    expect(router.currentRoute.value.query.page).toBe('3');
  });

  it('steps back when the requested page is past the end', async () => {
    const api = mockMediaApi([]);
    api.media.list.mockImplementation(async (query) =>
      (query?.page ?? 1) > 1 ? mediaPage([], 4, 1) : mediaPage(images, 4, 1),
    );
    const { router } = await open(api, { path: '/media?page=5' });
    expect(router.currentRoute.value.query.page).toBeUndefined();
    expect(tiles()).toHaveLength(4);
  });

  it('toggles the table view and remembers it per viewer', async () => {
    const api = mockMediaApi(images);
    await open(api);
    const table = $$('.swg-seg__opt').find((el) => el.textContent?.trim() === 'Table');
    table?.querySelector('input')?.click();
    await settle();
    expect($('table.swg-mtable__table')).not.toBeNull();
    expect($$('tbody tr')).toHaveLength(4);
    expect(window.localStorage.getItem(MEDIA_VIEW_KEY)).toBe('table');
    expect($('tbody tr[data-media-id="2"]')?.textContent).toContain('Used in 2 galleries');
    expect($('tbody tr[data-media-id="2"]')?.textContent).toContain('640 × 480');
  });

  it('starts in the table view when it was chosen before', async () => {
    window.localStorage.setItem(MEDIA_VIEW_KEY, 'table');
    await open(mockMediaApi(images));
    expect($('table.swg-mtable__table')).not.toBeNull();
  });
});

describe('Media Overview: states', () => {
  it('shows the first-use and the filtered empty state', async () => {
    const api = mockMediaApi([]);
    await open(api);
    expect(document.body.textContent).toContain('No images available');
    mounted?.unmount();
    resetEnvironment();
    const api2 = mockMediaApi([]);
    const { router } = await open(api2, { path: '/media?search=zzz' });
    expect(document.body.textContent).toContain('No images match');
    $$('button')
      .find((b) => b.textContent?.includes('Reset filters'))
      ?.click();
    await settle();
    expect(router.currentRoute.value.query.search).toBeUndefined();
  });

  it('shows an error with a retry that reloads', async () => {
    const api = mockMediaApi(images);
    api.media.list.mockRejectedValueOnce(new ApiError('swg_http_500', 'x', 500));
    await open(api);
    expect(document.body.textContent).toContain('The images could not be loaded');
    $$('button')
      .find((b) => b.textContent?.includes('Retry'))
      ?.click();
    await settle();
    expect(tiles()).toHaveLength(4);
  });

  it('explains a 403 instead of showing a broken page', async () => {
    const api = mockMediaApi(images);
    api.media.list.mockRejectedValue(new ApiError('rest_forbidden', 'no', 403));
    await open(api);
    expect(document.body.textContent).toContain('not available for your account');
    expect($('.swg-mgrid')).toBeNull();
  });

  it('hides the section from users without upload_files (no request is made)', async () => {
    const api = mockMediaApi(images);
    await open(api, { capabilities: ['edit_posts'] });
    expect(api.media.list).not.toHaveBeenCalled();
    expect(document.body.textContent).toContain('No access');
    expect($$('.swg-nav-link').map((a) => a.textContent?.trim())).not.toContain('Media Overview');
  });

  it('is available in German', async () => {
    await open(mockMediaApi(images), { locale: 'de_DE' });
    expect(document.body.textContent).toContain('Noch nicht verwendet');
    expect(document.body.textContent).toContain('In 2 Galerien verwendet');
  });
});

describe('Media Overview: selection', () => {
  it('toggles single images, supports shift ranges, select all and clear', async () => {
    const api = mockMediaApi(images);
    await open(api);
    checkbox(1).click();
    await settle();
    expect(checkbox(1).getAttribute('aria-checked')).toBe('true');
    expect(live()).toBe('One image selected');
    checkbox(3).dispatchEvent(new MouseEvent('click', { bubbles: true, shiftKey: true }));
    await settle();
    expect([1, 2, 3, 4].map((id) => checkbox(id).getAttribute('aria-checked'))).toEqual([
      'true',
      'true',
      'true',
      'false',
    ]);
    expect(live()).toBe('3 images selected');
    const all = $<HTMLElement>('.swg-msel__all');
    expect(all?.getAttribute('aria-checked')).toBe('mixed');
    all?.click();
    await settle();
    expect(all?.getAttribute('aria-checked')).toBe('true');
    all?.click();
    await settle();
    expect(all?.getAttribute('aria-checked')).toBe('false');
    expect(live()).toBe('Selection cleared');
  });

  it('Ctrl+A selects the page and Escape clears it', async () => {
    await open(mockMediaApi(images));
    $('.swg-mgrid')?.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'a', ctrlKey: true, bubbles: true, cancelable: true }),
    );
    await settle();
    expect($('.swg-msel__all')?.getAttribute('aria-checked')).toBe('true');
    $('.swg-mgrid')?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    await settle();
    expect($('.swg-msel__all')?.getAttribute('aria-checked')).toBe('false');
  });

  it('works the same in the table', async () => {
    window.localStorage.setItem(MEDIA_VIEW_KEY, 'table');
    await open(mockMediaApi(images));
    const row = (id: number): HTMLElement => {
      const el = $(`tr[data-media-id="${id}"] [role="checkbox"]`);
      if (el === null) throw new Error('row checkbox');
      return el;
    };
    row(2).click();
    row(4).dispatchEvent(new MouseEvent('click', { bubbles: true, shiftKey: true }));
    await settle();
    expect(row(3).getAttribute('aria-checked')).toBe('true');
    $('thead [role="checkbox"]')?.click();
    await settle();
    expect(row(1).getAttribute('aria-checked')).toBe('true');
    $('thead [role="checkbox"]')?.click();
    await settle();
    expect(row(1).getAttribute('aria-checked')).toBe('false');
  });
});

describe('Media Overview: details sheet', () => {
  it('shows read-only details without file names or paths', async () => {
    await open(mockMediaApi(images));
    $<HTMLButtonElement>('[data-media-id="2"] .swg-mtile__thumb')?.click();
    await settle();
    const sheet = $('.swg-sheet');
    expect(sheet).not.toBeNull();
    const field = (name: string): string =>
      sheet?.querySelector(`[data-field="${name}"]`)?.textContent?.trim() ?? '';
    expect(field('title')).toBe('Image 2');
    expect(field('alt')).toBe('Alt 2');
    expect(field('caption')).toBe('not set');
    expect(field('dimensions')).toBe('640 × 480');
    expect(field('type')).toBe('image/png');
    expect(field('id')).toBe('2');
    expect(field('user')).toBe('User 2');
    expect(sheet?.textContent).toContain('Used in 2 galleries');
    expect(sheet?.textContent).not.toMatch(/thumb-2|full-2|\.png\b|uploads\//);
    expect(sheet?.querySelector('input, textarea')).toBeNull();
  });

  it('offers the one action "Add to gallery" for that image', async () => {
    const api = mockMediaApi(images);
    await open(api);
    $<HTMLButtonElement>('[data-media-id="2"] .swg-mtile__thumb')?.click();
    await settle();
    $$('.swg-sheet button')
      .find((b) => b.textContent?.includes('Add to gallery'))
      ?.click();
    await settle();
    expect($('.swg-atg')).not.toBeNull();
    expect($('.swg-atg')?.textContent).toContain('Choose the gallery for one image.');
  });

  it('opens from the table as well', async () => {
    window.localStorage.setItem(MEDIA_VIEW_KEY, 'table');
    await open(mockMediaApi(images));
    $<HTMLButtonElement>('tr[data-media-id="3"] .swg-mtable__open')?.click();
    await settle();
    expect($('.swg-sheet')?.textContent).toContain('Image 3');
  });
});

describe('Media Overview: add to gallery', () => {
  async function selectAndOpenDialog(api: MockMediaFeature, ids: number[]): Promise<void> {
    await open(api);
    for (const id of ids) {
      checkbox(id).click();
      await settle();
    }
    $$('.swg-msel button')
      .find((b) => b.textContent?.includes('Add to gallery'))
      ?.click();
    await settle();
  }

  it('lists only galleries the user can edit and posts with the current revision', async () => {
    const api = mockMediaApi(images);
    await selectAndOpenDialog(api, [1, 2]);
    const options = $$('.swg-atg__opt');
    expect(options.map((o) => o.textContent?.trim())).toEqual([expect.stringContaining('Summer')]);
    expect($('.swg-atg')?.textContent).toContain('Choose the gallery for 2 images.');
    const submit = $$('.swg-atg button').find((b) => b.textContent?.includes('Add 2 images'));
    expect(
      submit?.hasAttribute('disabled') || submit?.getAttribute('aria-disabled') === 'true',
    ).toBe(true);
    $<HTMLInputElement>('.swg-atg__opt input')?.click();
    await settle();
    $$('.swg-atg button')
      .find((b) => b.textContent?.includes('Add 2 images'))
      ?.click();
    await settle();
    expect(api.galleries.get).toHaveBeenCalledWith(7);
    expect(api.items.assign).toHaveBeenCalledWith(7, [1, 2], 5);
    expect($('.swg-atg')).toBeNull();
    expect(toast('2 images added')).toBeDefined();
    expect(live()).toContain('Gallery "Summer": 2 images added.');
    // selection cleared, list reloaded for new usage counts
    expect($('.swg-msel__all')?.getAttribute('aria-checked')).toBe('false');
    expect(api.media.list.mock.calls.length).toBeGreaterThan(1);
  });

  it('reports already assigned and unusable images honestly', async () => {
    const api = mockMediaApi(images);
    api.items.assign.mockResolvedValue({
      added: [1],
      alreadyAssigned: [2],
      missing: [3, 4],
      revision: 9,
    });
    await selectAndOpenDialog(api, [1, 2, 3, 4]);
    $<HTMLInputElement>('.swg-atg__opt input')?.click();
    await settle();
    $$('.swg-atg button')
      .find((b) => b.textContent?.includes('Add 4 images'))
      ?.click();
    await settle();
    expect(live()).toBe(
      'Gallery "Summer": One image added, already in the gallery: 1, not usable: 2.',
    );
  });

  it('warns when nothing could be added and keeps the selection', async () => {
    const api = mockMediaApi(images);
    api.items.assign.mockResolvedValue({ added: 0, alreadyAssigned: 1, missing: 0, revision: 5 });
    await selectAndOpenDialog(api, [1]);
    $<HTMLInputElement>('.swg-atg__opt input')?.click();
    await settle();
    $$('.swg-atg button')
      .find((b) => b.textContent?.includes('Add image'))
      ?.click();
    await settle();
    expect(live()).toContain('No images added, already in the gallery: 1');
    expect(toast('No images added')?.className).toContain('bg-warning');
    expect(checkbox(1).getAttribute('aria-checked')).toBe('true');
  });

  it('keeps the dialog open and shows the error when the server refuses', async () => {
    const api = mockMediaApi(images);
    api.items.assign.mockRejectedValue(new ConflictError('swg_revision_conflict', 'c'));
    await selectAndOpenDialog(api, [1]);
    $<HTMLInputElement>('.swg-atg__opt input')?.click();
    await settle();
    $$('.swg-atg button')
      .find((b) => b.textContent?.includes('Add image'))
      ?.click();
    await settle();
    expect($('.swg-atg')).not.toBeNull();
    expect($('.swg-atg__error')?.textContent).toContain('changed');
  });

  it('refuses when the fresh gallery is no longer editable', async () => {
    const api = mockMediaApi(images);
    api.galleries.get.mockResolvedValue({
      ...(await api.galleries.get(7)),
      canEdit: false,
    });
    await selectAndOpenDialog(api, [1]);
    $<HTMLInputElement>('.swg-atg__opt input')?.click();
    await settle();
    $$('.swg-atg button')
      .find((b) => b.textContent?.includes('Add image'))
      ?.click();
    await settle();
    expect(api.items.assign).not.toHaveBeenCalled();
    expect($('.swg-atg__error')?.textContent).toContain('not allowed');
  });

  it('searches galleries (debounced) and explains the empty and error states', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const api = mockMediaApi(images);
    await selectAndOpenDialog(api, [1]);
    const input = $<HTMLInputElement>('.swg-atg input[type="search"]');
    if (input === null) throw new Error('no search');
    api.galleries.list.mockResolvedValueOnce({ items: [], total: 0, totalPages: 0 });
    input.value = 'zzz';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await vi.advanceTimersByTimeAsync(350);
    await settle();
    expect(api.galleries.list).toHaveBeenLastCalledWith({ search: 'zzz', perPage: 50 });
    expect($('.swg-atg')?.textContent).toContain('No gallery you can edit');
    api.galleries.list.mockRejectedValueOnce(new ApiError('swg_network', 'n', 0));
    input.value = 'abc';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await vi.advanceTimersByTimeAsync(350);
    await settle();
    expect($('.swg-atg [role="alert"]')?.textContent).toContain('could not be reached');
    $$('.swg-atg button')
      .find((b) => b.textContent?.includes('Retry'))
      ?.click();
    await settle();
    expect($$('.swg-atg__opt')).toHaveLength(1);
  });

  it('can be cancelled', async () => {
    const api = mockMediaApi(images);
    await selectAndOpenDialog(api, [1]);
    $$('.swg-atg button')
      .find((b) => b.textContent?.includes('Cancel'))
      ?.click();
    await settle();
    expect($('.swg-atg')).toBeNull();
    expect(api.items.assign).not.toHaveBeenCalled();
  });

  it('hints at narrowing when more galleries exist than are listed', async () => {
    const api = mockMediaApi(images);
    api.galleries.list.mockResolvedValue({
      items: [await api.galleries.get(7)],
      total: 120,
      totalPages: 3,
    });
    await selectAndOpenDialog(api, [1]);
    expect($('.swg-atg__more')?.textContent).toContain('Showing 1 of 120 galleries');
  });
});

describe('Media Overview: upload', () => {
  it('uploads picked files, reloads the list and reports the result', async () => {
    const api = mockMediaApi(images);
    await open(api);
    const input = $<HTMLInputElement>('[data-testid="media-file-input"]');
    if (input === null) throw new Error('no file input');
    const file = new File([new Uint8Array([1, 2, 3])], 'one.png', { type: 'image/png' });
    Object.defineProperty(input, 'files', { value: [file], configurable: true });
    input.dispatchEvent(new Event('change', { bubbles: true }));
    await settle();
    expect(api.media.upload).toHaveBeenCalledTimes(1);
    expect(api.media.list.mock.calls.length).toBeGreaterThan(1);
    expect(live()).toContain('1 uploaded, 0 failed.');
    expect($('[data-upload-status]')?.textContent).toContain('one.png');
  });

  it('lists a refused upload with a retry and rejects unsupported types without a request', async () => {
    const api = mockMediaApi(images);
    api.media.upload.mockRejectedValueOnce(new ApiError('swg_unsupported_media_type', 'x', 422));
    await open(api);
    const input = $<HTMLInputElement>('[data-testid="media-file-input"]');
    if (input === null) throw new Error('no file input');
    const bad = new File(['x'], 'a.svg', { type: 'image/svg+xml' });
    const png = new File([new Uint8Array([1])], 'b.png', { type: 'image/png' });
    Object.defineProperty(input, 'files', { value: [bad, png], configurable: true });
    input.dispatchEvent(new Event('change', { bubbles: true }));
    await settle();
    expect(api.media.upload).toHaveBeenCalledTimes(1);
    const rows = $$('[data-upload-status="error"]');
    expect(rows).toHaveLength(2);
    expect(rows[0]?.textContent).toContain('Not a supported image type');
    expect(rows[1]?.textContent).toContain('The server does not accept this file type');
    $$('[data-upload-status="error"] button')
      .find((b) => b.textContent?.includes('Retry'))
      ?.click();
    await settle();
    expect(api.media.upload).toHaveBeenCalledTimes(2);
    expect($$('[data-upload-status="added"]').length).toBeGreaterThan(0);
  });

  it('accepts dropped files on the list section and shows the drop state', async () => {
    const api = mockMediaApi(images);
    await open(api);
    const section = $('section[aria-label="Media"]');
    if (section === null) throw new Error('no section');
    const file = new File([new Uint8Array([1])], 'drop.png', { type: 'image/png' });
    const dt = { types: ['Files'], files: [file] };
    const fire = (type: string): void => {
      const event = new Event(type, { bubbles: true, cancelable: true });
      Object.defineProperty(event, 'dataTransfer', { value: dt });
      section.dispatchEvent(event);
    };
    fire('dragenter');
    await settle();
    expect($('.swg-mup__zone.is-over')).not.toBeNull();
    fire('dragleave');
    await settle();
    expect($('.swg-mup__zone.is-over')).toBeNull();
    fire('dragenter');
    fire('drop');
    await settle();
    expect(api.media.upload).toHaveBeenCalledTimes(1);
    // a drag without files is ignored
    const text = new Event('dragenter', { bubbles: true, cancelable: true });
    Object.defineProperty(text, 'dataTransfer', { value: { types: ['text/plain'], files: [] } });
    section.dispatchEvent(text);
    await settle();
    expect($('.swg-mup__zone.is-over')).toBeNull();
  });
});
