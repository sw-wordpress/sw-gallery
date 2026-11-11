import { flushPromises } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, ConflictError } from '../../src/api/client';
import type { GalleryListQuery } from '../../src/api/galleries';
import { GALLERY_VIEW_KEY } from '../../src/stores/app';
import { mountApp, resetEnvironment } from '../support/mountApp';
import { makeGallery, mockApi, pageOf } from './support';
import type { MockApi } from './support';

let mounted: VueWrapper | null = null;

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
  mounted?.unmount();
  mounted = null;
  resetEnvironment();
});

interface Opened {
  wrapper: VueWrapper;
  api: MockApi;
  router: Awaited<ReturnType<typeof mountApp>>['router'];
}

async function open(
  api: MockApi,
  options: { path?: string; capabilities?: string[]; locale?: string; timezone?: string } = {},
): Promise<Opened> {
  const { wrapper, router } = await mountApp({
    path: '/galleries',
    galleriesApi: api,
    ...options,
  });
  mounted = wrapper;
  return { wrapper, api, router };
}

const $ = <T extends Element = HTMLElement>(selector: string): T | null =>
  document.body.querySelector<T>(selector);
const $$ = (selector: string): HTMLElement[] =>
  Array.from(document.body.querySelectorAll<HTMLElement>(selector));
const toast = (text: string): HTMLElement | undefined =>
  $$('.q-notification').find((n) => n.textContent?.includes(text));
const live = (): string => $('.swg-shell > [role="status"]')?.textContent?.trim() ?? '';
const lastQuery = (api: MockApi): GalleryListQuery =>
  api.list.mock.calls[api.list.mock.calls.length - 1]?.[0] ?? {};

async function settle(): Promise<void> {
  await flushPromises();
  await new Promise((resolve) => setTimeout(resolve, 0));
  await flushPromises();
}

/** Opens the row menu of a gallery and clicks an action (the menu is teleported into the body). */
async function rowAction(wrapper: VueWrapper, id: number, action: string): Promise<void> {
  const row = wrapper.find(`[data-gallery-id="${id}"]`);
  await row.find('.swg-actions-btn').trigger('click');
  await settle();
  const item = $(`[role="menuitem"][data-action="${action}"]`);
  if (item === null) throw new Error(`action ${action} not offered`);
  item.click();
  await settle();
}

async function submitSheet(): Promise<void> {
  const form = $<HTMLFormElement>('.swg-sheet form');
  if (form === null) throw new Error('no sheet open');
  form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
  await settle();
}

async function typeInto(selector: string, value: string): Promise<void> {
  const input = $<HTMLInputElement | HTMLTextAreaElement>(selector);
  if (input === null) throw new Error(`${selector} not found`);
  input.value = value;
  input.dispatchEvent(new Event('input', { bubbles: true }));
  await flushPromises();
}

beforeEach(() => {
  resetEnvironment();
  // jsdom has no scrollTo on elements; Quasar's select/menu scroll helpers call it.
  Element.prototype.scrollTo = (): void => {};
});

describe('list states', () => {
  it('shows a skeleton with a status message while loading, then the galleries', async () => {
    let resolve!: (value: ReturnType<typeof pageOf>) => void;
    const api = mockApi({
      list: vi.fn(() => new Promise((res) => (resolve = res))) as MockApi['list'],
    });
    const { wrapper } = await open(api);
    expect(wrapper.find('[aria-busy="true"] .swg-skeleton').exists()).toBe(true);
    expect(wrapper.find('.swg-skeleton [role="status"]').text()).toBe('Loading galleries');
    resolve(
      pageOf([makeGallery({ id: 1, title: 'Alpha' }), makeGallery({ id: 2, title: 'Beta' })]),
    );
    await settle();
    expect(wrapper.find('.swg-skeleton').exists()).toBe(false);
    expect(wrapper.find('section[aria-label="Gallery list"]').attributes('aria-busy')).toBe(
      'false',
    );
    expect(wrapper.findAll('.swg-gcard h3').map((h) => h.text())).toEqual(['Alpha', 'Beta']);
    expect(wrapper.find('.swg-pager').text()).toContain('2 galleries');
  });

  it('renders cover, title, status, image count and the modified date in locale and timezone', async () => {
    window.swgAdmin = undefined;
    const api = mockApi({
      list: vi.fn(async () =>
        pageOf([
          makeGallery({
            id: 1,
            title: 'With cover',
            coverUrl: 'https://x.test/c.jpg',
            status: 'publish',
            itemCount: 1,
            passwordProtected: true,
          }),
          makeGallery({ id: 2, title: 'No cover', itemCount: 0, status: 'pending' }),
          makeGallery({ id: 3, title: '', itemCount: 12, status: 'private' }),
        ]),
      ),
    });
    const { wrapper } = await open(api);
    const first = wrapper.find('[data-gallery-id="1"]');
    expect(first.find('img').attributes('src')).toBe('https://x.test/c.jpg');
    expect(first.find('img').attributes('alt')).toBe('');
    expect(first.text()).toContain('Published');
    expect(first.text()).toContain('Password protected');
    expect(first.text()).toContain('one image');
    expect(first.find('time').attributes('datetime')).toBe('2026-11-11T09:30:00Z');
    expect(first.find('time').text()).toContain('Nov 11, 2026');
    const second = wrapper.find('[data-gallery-id="2"]');
    expect(second.find('[data-testid="cover-placeholder"]').exists()).toBe(true);
    expect(second.text()).toContain('Pending review');
    expect(second.text()).toContain('no images');
    const third = wrapper.find('[data-gallery-id="3"]');
    expect(third.text()).toContain('(no title)');
    expect(third.text()).toContain('12 images');
    expect(third.text()).toContain('Private');
    // A cover that fails to load falls back to the neutral tile.
    await first.find('img').trigger('error');
    expect(first.find('[data-testid="cover-placeholder"]').exists()).toBe(true);
  });

  it('formats dates in the German locale', async () => {
    const api = mockApi({ list: vi.fn(async () => pageOf([makeGallery({ id: 1 })])) });
    const { wrapper } = await open(api, { locale: 'de_DE', timezone: 'Europe/Berlin' });
    expect(wrapper.find('[data-gallery-id="1"] time').text()).toContain('11.11.2026, 10:30');
    expect(wrapper.find('.swg-page-header__title').text()).toBe('Galerien');
    expect(wrapper.find('.swg-gcard').text()).toContain('4 Bilder');
  });

  it('offers the first-run empty state with a call to action that opens the create sheet', async () => {
    const { wrapper } = await open(mockApi());
    expect(wrapper.find('.swg-empty__title').text()).toBe('No galleries yet');
    const cta = wrapper
      .findAll('.swg-empty button')
      .find((b) => b.text().includes('Create your first gallery'));
    expect(cta).toBeDefined();
    await cta?.trigger('click');
    await settle();
    expect($('.swg-sheet__title')?.textContent).toBe('New gallery');
  });

  it('hides the create actions for users without edit_posts', async () => {
    const { wrapper } = await open(mockApi(), { capabilities: ['edit_others_posts'] });
    // The shell already refuses these users; the view must not offer actions on its own either.
    expect(wrapper.find('.swg-page-header__actions').exists()).toBe(false);
  });

  it('distinguishes empty search results, an empty trash and an empty filter', async () => {
    const search = await open(mockApi(), { path: '/galleries?search=zebra' });
    expect(search.wrapper.find('.swg-empty__title').text()).toBe('No galleries match your search');
    expect(search.wrapper.find('.swg-empty__text').text()).toContain('zebra');
    mounted?.unmount();
    resetEnvironment();
    const trash = await open(mockApi(), { path: '/galleries?status=trash' });
    expect(trash.wrapper.find('.swg-empty__title').text()).toBe('The trash is empty');
    expect(trash.wrapper.find('.swg-empty button').exists()).toBe(false);
    mounted?.unmount();
    resetEnvironment();
    const filter = await open(mockApi(), { path: '/galleries?status=private' });
    expect(filter.wrapper.find('.swg-empty__title').text()).toBe('No galleries in "Private"');
    await filter.wrapper.find('.swg-empty button').trigger('click');
    await settle();
    expect(filter.router.currentRoute.value.query).toEqual({});
  });

  it('clears the search from the empty state', async () => {
    const { wrapper, router, api } = await open(mockApi(), { path: '/galleries?search=zebra' });
    await wrapper.find('.swg-empty button').trigger('click');
    await settle();
    expect(router.currentRoute.value.query.search).toBeUndefined();
    expect(lastQuery(api).search).toBeUndefined();
  });

  it('shows an error state with the technical detail and recovers on retry', async () => {
    const api = mockApi({
      list: vi
        .fn<MockApi['list']>()
        .mockRejectedValueOnce(new ApiError('swg_internal_error', 'secret stack', 500))
        .mockResolvedValueOnce(pageOf([makeGallery({ id: 1, title: 'Back again' })])),
    });
    const { wrapper } = await open(api);
    const alert = wrapper.find('[role="alert"]');
    expect(alert.text()).toContain('The galleries could not be loaded');
    expect(alert.text()).toContain('The server reported an error');
    expect(alert.text()).toContain('swg_internal_error (HTTP 500)');
    expect(alert.text()).not.toContain('secret stack');
    await alert.find('button').trigger('click');
    await settle();
    expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    expect(wrapper.find('.swg-gcard h3').text()).toBe('Back again');
  });

  it('reports a network failure without technical status', async () => {
    const api = mockApi({
      list: vi.fn<MockApi['list']>().mockRejectedValue(new ApiError('swg_network', 'x', 0)),
    });
    const { wrapper } = await open(api);
    expect(wrapper.find('[role="alert"]').text()).toContain('could not be reached');
    expect(wrapper.find('.swg-mono').text()).toBe('swg_network');
  });
});

describe('toolbar, URL state and paging', () => {
  it('debounces the search, resets the page and keeps it in the URL', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    const api = mockApi({ list: vi.fn(async () => pageOf([makeGallery()], 100, 5)) });
    const { wrapper, router } = await open(api, { path: '/galleries?page=3' });
    expect(api.list).toHaveBeenCalledTimes(1);
    const input = wrapper.find('input[type="search"]');
    await input.setValue('su');
    await input.setValue('sum');
    await vi.advanceTimersByTimeAsync(299);
    expect(api.list).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(2);
    await flushPromises();
    expect(api.list).toHaveBeenCalledTimes(2);
    expect(lastQuery(api)).toMatchObject({ search: 'sum', page: 1, perPage: 20 });
    expect(router.currentRoute.value.query).toEqual({ search: 'sum' });
  });

  it('applies the search immediately on Enter and clears it with the clear button', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    const api = mockApi();
    const { wrapper, router } = await open(api);
    const input = wrapper.find('input[type="search"]');
    await input.setValue('beach');
    await input.trigger('keyup.enter');
    await flushPromises();
    expect(lastQuery(api).search).toBe('beach');
    const clear = wrapper.find('button[aria-label="Clear search"]');
    expect(clear.exists()).toBe(true);
    await clear.trigger('click');
    await flushPromises();
    expect(router.currentRoute.value.query.search).toBeUndefined();
    expect(lastQuery(api).search).toBeUndefined();
    expect(wrapper.find('button[aria-label="Clear search"]').exists()).toBe(false);
  });

  it('maps the status filter to the API and the URL; "All" is the API default', async () => {
    const api = mockApi();
    const { wrapper, router } = await open(api);
    expect(lastQuery(api).status).toBeUndefined();
    const radio = (label: string) =>
      wrapper
        .findAll('fieldset.swg-seg label')
        .find((l) => l.text() === label)
        ?.find('input');
    expect(wrapper.find('fieldset.swg-seg legend').text()).toBe('Filter by status');
    await radio('Published')?.setValue(true);
    await settle();
    expect(lastQuery(api).status).toEqual(['publish']);
    expect(router.currentRoute.value.query).toEqual({ status: 'publish' });
    await radio('Drafts')?.setValue(true);
    await settle();
    expect(lastQuery(api).status).toEqual(['draft', 'pending']);
    await radio('Private')?.setValue(true);
    await settle();
    expect(lastQuery(api).status).toEqual(['private']);
    await radio('Trash')?.setValue(true);
    await settle();
    expect(lastQuery(api).status).toEqual(['trash']);
    await radio('All')?.setValue(true);
    await settle();
    expect(lastQuery(api).status).toBeUndefined();
    expect(router.currentRoute.value.query).toEqual({});
  });

  it('pages on the server and keeps the page in the URL', async () => {
    const rows = [makeGallery({ id: 1 })];
    const api = mockApi({ list: vi.fn(async () => pageOf(rows, 45, 3)) });
    const { wrapper, router } = await open(api);
    const pager = wrapper.find('nav.swg-pager');
    expect(pager.attributes('aria-label')).toBe('Gallery pages');
    expect(pager.text()).toContain('45 galleries');
    expect(pager.text()).toContain('Page 1 of 3');
    await pager.find('button[aria-label="Next page"]').trigger('click');
    await settle();
    expect(lastQuery(api).page).toBe(2);
    expect(router.currentRoute.value.query).toEqual({ page: '2' });
    expect(wrapper.find('nav.swg-pager').text()).toContain('Page 2 of 3');
    await wrapper.find('button[aria-label="Previous page"]').trigger('click');
    await settle();
    expect(lastQuery(api).page).toBe(1);
    expect(router.currentRoute.value.query).toEqual({});
  });

  it('restores page, search and status from a deep link and follows back/forward', async () => {
    const api = mockApi({ list: vi.fn(async () => pageOf([makeGallery()], 45, 3)) });
    const { wrapper, router } = await open(api, {
      path: '/galleries?page=2&search=sun&status=trash',
    });
    expect(lastQuery(api)).toEqual({
      search: 'sun',
      status: ['trash'],
      page: 2,
      perPage: 20,
    });
    expect((wrapper.find('input[type="search"]').element as HTMLInputElement).value).toBe('sun');
    expect(
      wrapper.find('fieldset.swg-seg input:checked').element.closest('label')?.textContent,
    ).toContain('Trash');
    await router.push({ path: '/galleries', query: { search: 'moon' } });
    await settle();
    expect((wrapper.find('input[type="search"]').element as HTMLInputElement).value).toBe('moon');
    expect(lastQuery(api).search).toBe('moon');
  });

  it('ignores invalid URL parameters', async () => {
    const api = mockApi();
    await open(api, { path: '/galleries?page=-4&status=bogus&search=%20%20' });
    expect(lastQuery(api)).toEqual({
      search: undefined,
      status: undefined,
      page: 1,
      perPage: 20,
    });
  });

  it('steps back to the last page when the requested page is past the end', async () => {
    const api = mockApi({
      list: vi.fn(async (query?: GalleryListQuery) =>
        (query?.page ?? 1) > 2 ? pageOf([], 30, 2) : pageOf([makeGallery()], 30, 2),
      ),
    });
    const { router } = await open(api, { path: '/galleries?page=9' });
    await settle();
    expect(router.currentRoute.value.query).toEqual({ page: '2' });
    expect(lastQuery(api).page).toBe(2);
  });

  it('toggles between grid and an accessible table and remembers the choice per viewer', async () => {
    const api = mockApi({
      list: vi.fn(async () =>
        pageOf([
          makeGallery({ id: 1, title: 'Alpha', status: 'publish' }),
          makeGallery({ id: 2, title: 'Beta' }),
        ]),
      ),
    });
    const { wrapper } = await open(api);
    expect(wrapper.find('ul.swg-ggrid').exists()).toBe(true);
    const viewRadio = wrapper
      .findAll('fieldset.swg-seg')[1]
      ?.findAll('label')
      .find((l) => l.text() === 'Table')
      ?.find('input');
    await viewRadio?.setValue(true);
    await settle();
    expect(window.localStorage.getItem(GALLERY_VIEW_KEY)).toBe('table');
    const table = wrapper.find('table');
    expect(table.find('caption').text()).toBe('Galleries');
    expect(table.findAll('thead th[scope="col"]')).toHaveLength(6);
    expect(table.findAll('tbody th[scope="row"]').map((th) => th.text())).toEqual([
      'Alpha',
      'Beta',
    ]);
    expect(table.find('tbody tr').text()).toContain('Published');
    expect(table.find('tbody tr time').attributes('datetime')).toBe('2026-11-11T09:30:00Z');
    expect(wrapper.find('ul.swg-ggrid').exists()).toBe(false);
    // Reload: the table view is restored.
    mounted?.unmount();
    const again = await open(api);
    expect(again.wrapper.find('table').exists()).toBe(true);
  });

  it('works when localStorage throws', async () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    const { wrapper } = await open(mockApi({ list: vi.fn(async () => pageOf([makeGallery()])) }));
    const radio = wrapper
      .findAll('fieldset.swg-seg')[1]
      ?.findAll('label')
      .find((l) => l.text() === 'Table')
      ?.find('input');
    await radio?.setValue(true);
    await settle();
    expect(wrapper.find('table').exists()).toBe(true);
    vi.restoreAllMocks();
  });
});

describe('create', () => {
  it('validates the title, creates a draft, announces it and refetches', async () => {
    const created = makeGallery({ id: 11, title: 'Brand new' });
    const api = mockApi({ create: vi.fn(async () => created) });
    const { wrapper } = await open(api);
    api.list.mockResolvedValue(pageOf([created]));
    await wrapper.find('.swg-page-header__actions button').trigger('click');
    await settle();
    expect($('.swg-sheet__title')?.textContent).toBe('New gallery');
    expect($('.q-select')).toBeNull(); // status is not offered on create
    await submitSheet();
    expect(api.create).not.toHaveBeenCalled();
    expect($('.swg-sheet')?.textContent).toContain('Enter a title.');
    await typeInto('.swg-sheet input[type="text"]', '  Brand new  ');
    await typeInto('.swg-sheet textarea', 'Line 1\nLine 2');
    await submitSheet();
    expect(api.create).toHaveBeenCalledWith({
      title: 'Brand new',
      description: 'Line 1\nLine 2',
      status: 'draft',
    });
    expect(api.list).toHaveBeenCalledTimes(2);
    expect(live()).toBe('Gallery "Brand new" was created as a draft.');
    expect(wrapper.find('.swg-gcard h3').text()).toBe('Brand new');
    await vi.waitFor(() => expect($('.q-notification')?.getAttribute('aria-hidden')).toBe('true'));
  });

  it('keeps the sheet and shows the translated server error on failure', async () => {
    const api = mockApi({
      create: vi.fn(async () => {
        throw new ApiError('rest_invalid_param', 'Invalid parameter(s): title', 400, {
          title: 'too long',
        });
      }),
    });
    const { wrapper } = await open(api);
    await wrapper.find('.swg-page-header__actions button').trigger('click');
    await settle();
    await typeInto('.swg-sheet input[type="text"]', 'x');
    await submitSheet();
    const alert = $('.swg-sheet [role="alert"]');
    expect(alert?.textContent).toContain('Some values are invalid');
    expect(alert?.textContent).toContain('Title');
    expect(alert?.textContent).not.toContain('Invalid parameter');
    expect(
      (document.querySelector('.swg-sheet input[type="text"]') as HTMLInputElement).value,
    ).toBe('x');
  });
});

describe('edit details', () => {
  const gallery = makeGallery({ id: 5, title: 'Old', description: 'D', revision: 3 });

  async function openEdit(g = gallery, api = mockApi({ list: vi.fn(async () => pageOf([g])) })) {
    const opened = await open(api);
    await rowAction(opened.wrapper, g.id, 'edit');
    return opened;
  }

  it('prefills the form and sends only changed fields with the row revision', async () => {
    const { api } = await openEdit();
    api.update.mockResolvedValue({ ...gallery, title: 'New', revision: 4 });
    expect($('.swg-sheet__title')?.textContent).toBe('Edit gallery details');
    expect($('.swg-sheet__subtitle')?.textContent).toBe('Gallery Old');
    expect($<HTMLInputElement>('.swg-sheet input[type="text"]')?.value).toBe('Old');
    expect($<HTMLTextAreaElement>('.swg-sheet textarea')?.value).toBe('D');
    await typeInto('.swg-sheet input[type="text"]', 'New');
    await submitSheet();
    expect(api.update).toHaveBeenCalledWith(5, 3, { title: 'New' });
    expect(live()).toBe('Gallery "New" was saved.');
    expect(api.list).toHaveBeenCalledTimes(2);
  });

  it('reports "nothing changed" without calling the API', async () => {
    const { api } = await openEdit();
    await submitSheet();
    expect(api.update).not.toHaveBeenCalled();
    expect($('.swg-sheet [role="alert"]')?.textContent).toContain('nothing to save');
  });

  it('offers publish and private only with the publish right', async () => {
    const withRight = await openEdit(makeGallery({ id: 5, canPublish: true }));
    await $<HTMLElement>('.swg-sheet .q-select')?.click();
    await settle();
    let options = $$('.q-menu [role="option"]');
    expect(options.map((o) => o.textContent?.trim())).toEqual([
      'Draft',
      'Pending review',
      'Published',
      'Private',
    ]);
    expect(options.every((o) => o.getAttribute('aria-disabled') !== 'true')).toBe(true);
    withRight.wrapper.unmount();
    mounted = null;
    resetEnvironment();

    await openEdit(makeGallery({ id: 5, canPublish: false }));
    await $<HTMLElement>('.swg-sheet .q-select')?.click();
    await settle();
    options = $$('.q-menu [role="option"]');
    const disabled = options.filter((o) => o.getAttribute('aria-disabled') === 'true');
    expect(disabled.map((o) => o.textContent)).toEqual([
      expect.stringContaining('Published'),
      expect.stringContaining('Private'),
    ]);
    expect(disabled[0]?.textContent).toContain('needs the publish right');
    expect($('.swg-sheet')?.textContent).toContain('needs the publish right');
  });

  it('changes the status through the select', async () => {
    const { api } = await openEdit();
    api.update.mockResolvedValue({ ...gallery, status: 'publish' });
    await $<HTMLElement>('.swg-sheet .q-select')?.click();
    await settle();
    $$('.q-menu [role="option"]')
      .find((o) => o.textContent?.includes('Published'))
      ?.click();
    await settle();
    await submitSheet();
    expect(api.update).toHaveBeenCalledWith(5, 3, { status: 'publish' });
  });

  it('on 409 warns, keeps the input, fetches the fresh revision and retries with it', async () => {
    const { api } = await openEdit();
    api.update.mockRejectedValueOnce(
      new ConflictError('swg_revision_conflict', 'stale', {}, { currentRevision: 9 }),
    );
    api.get.mockResolvedValue({ ...gallery, title: 'Changed elsewhere', revision: 9 });
    api.list.mockResolvedValue(pageOf([{ ...gallery, title: 'Changed elsewhere', revision: 9 }]));
    await typeInto('.swg-sheet input[type="text"]', 'My edit');
    await submitSheet();
    expect(api.get).toHaveBeenCalledWith(5);
    const alert = $('.swg-sheet [role="alert"]');
    expect(alert?.textContent).toContain('changed elsewhere');
    expect(alert?.textContent).toContain('Your input is kept');
    expect($<HTMLInputElement>('.swg-sheet input[type="text"]')?.value).toBe('My edit');
    expect(api.list).toHaveBeenCalledTimes(2); // list reloaded
    api.update.mockResolvedValueOnce({ ...gallery, title: 'My edit', revision: 10 });
    await submitSheet();
    expect(api.update).toHaveBeenLastCalledWith(5, 9, { title: 'My edit' });
  });

  it('on 404 closes the sheet, informs and refreshes the list', async () => {
    const { api } = await openEdit();
    api.update.mockRejectedValueOnce(new ApiError('swg_gallery_not_found', 'gone', 404));
    await typeInto('.swg-sheet input[type="text"]', 'x');
    await submitSheet();
    expect(live()).toBe('This gallery no longer exists. The list was reloaded.');
    expect(api.list).toHaveBeenCalledTimes(2);
    expect($('.swg-sheet input[type="text"]')).toBeNull();
  });

  it('shows 403 swg_cannot_publish and 422 invalid cover as specific messages', async () => {
    const { api } = await openEdit();
    await typeInto('.swg-sheet input[type="text"]', 'x');
    api.update.mockRejectedValueOnce(new ApiError('swg_cannot_publish', 'no', 403));
    await submitSheet();
    expect($('.swg-sheet [role="alert"]')?.textContent).toContain('not allowed to publish');
    api.update.mockRejectedValueOnce(new ApiError('swg_invalid_cover', 'no', 422));
    await submitSheet();
    expect($('.swg-sheet [role="alert"]')?.textContent).toContain('cannot be used as the cover');
    api.update.mockRejectedValueOnce(new ApiError('swg_internal_error', 'boom', 500));
    await submitSheet();
    expect($('.swg-sheet [role="alert"]')?.textContent).toContain('The server reported an error');
  });

  it('on 409 invalid state closes the sheet and reloads', async () => {
    const { api } = await openEdit();
    api.update.mockRejectedValueOnce(new ConflictError('swg_invalid_state', 'trashed'));
    await typeInto('.swg-sheet input[type="text"]', 'x');
    await submitSheet();
    expect(live()).toContain('different state');
    expect(api.list).toHaveBeenCalledTimes(2);
  });

  it('on 409 with a vanished gallery during the refresh informs about the deletion', async () => {
    const { api } = await openEdit();
    api.update.mockRejectedValueOnce(new ConflictError('swg_revision_conflict', 'stale'));
    api.get.mockRejectedValueOnce(new ApiError('swg_gallery_not_found', 'gone', 404));
    await typeInto('.swg-sheet input[type="text"]', 'x');
    await submitSheet();
    expect(live()).toContain('no longer exists');
  });
});

describe('duplicate', () => {
  it('suggests "<title> (copy)" and duplicates with the chosen title', async () => {
    const source = makeGallery({ id: 4, title: 'Summer' });
    const api = mockApi({
      list: vi.fn(async () => pageOf([source])),
      duplicate: vi.fn(async () => makeGallery({ id: 5, title: 'Summer (copy)' })),
    });
    const { wrapper } = await open(api);
    await rowAction(wrapper, 4, 'duplicate');
    expect($('.swg-sheet__title')?.textContent).toBe('Duplicate gallery');
    expect($<HTMLInputElement>('.swg-sheet input[type="text"]')?.value).toBe('Summer (copy)');
    await submitSheet();
    expect(api.duplicate).toHaveBeenCalledWith(4, 'Summer (copy)');
    expect(live()).toBe('Gallery "Summer (copy)" was created as a copy.');
    expect(api.list).toHaveBeenCalledTimes(2);
  });

  it('requires a title and reports failures inside the sheet', async () => {
    const api = mockApi({
      list: vi.fn(async () => pageOf([makeGallery({ id: 4 })])),
      duplicate: vi.fn(async () => {
        throw new ApiError('swg_forbidden', 'no', 403);
      }),
    });
    const { wrapper } = await open(api);
    await rowAction(wrapper, 4, 'duplicate');
    await typeInto('.swg-sheet input[type="text"]', '');
    await submitSheet();
    expect(api.duplicate).not.toHaveBeenCalled();
    await typeInto('.swg-sheet input[type="text"]', 'Copy');
    await submitSheet();
    expect($('.swg-sheet [role="alert"]')?.textContent).toContain('not allowed');
  });
});

describe('trash, restore and permanent delete', () => {
  it('trash confirmation names the gallery, states that images stay, and sends the revision', async () => {
    const api = mockApi({
      list: vi.fn(async () => pageOf([makeGallery({ id: 8, title: 'Wedding', revision: 5 })])),
      trash: vi.fn(async () => ({ id: 8, trashed: true, status: 'trash' as const, revision: 6 })),
    });
    const { wrapper } = await open(api);
    await rowAction(wrapper, 8, 'trash');
    const dialog = $('[role="alertdialog"]');
    expect(dialog?.querySelector('.swg-cd__title')?.textContent).toBe(
      'Move this gallery to the trash?',
    );
    expect(dialog?.querySelector('.swg-cd__target-list')?.textContent).toBe('Wedding');
    expect(dialog?.textContent).toContain('images stay in the Media Library');
    expect(api.trash).not.toHaveBeenCalled();
    $$('.q-card__actions button')[1]?.click();
    await settle();
    expect(api.trash).toHaveBeenCalledWith(8, 5);
    expect(live()).toBe('Gallery "Wedding" was moved to the trash.');
    expect(api.list).toHaveBeenCalledTimes(2);
  });

  it('cancel leaves the gallery alone', async () => {
    const api = mockApi({ list: vi.fn(async () => pageOf([makeGallery({ id: 8 })])) });
    const { wrapper } = await open(api);
    await rowAction(wrapper, 8, 'trash');
    $$('.q-card__actions button')[0]?.click();
    await settle();
    expect(api.trash).not.toHaveBeenCalled();
  });

  it('trash on 409 shows "changed elsewhere", refreshes the revision and allows a retry', async () => {
    const g = makeGallery({ id: 8, title: 'Wedding', revision: 5 });
    const api = mockApi({
      list: vi.fn(async () => pageOf([g])),
      get: vi.fn(async () => ({ ...g, revision: 8 })),
    });
    api.trash.mockRejectedValueOnce(new ConflictError('swg_revision_conflict', 'stale'));
    const { wrapper } = await open(api);
    await rowAction(wrapper, 8, 'trash');
    $$('.q-card__actions button')[1]?.click();
    await settle();
    expect($('[role="alertdialog"] .swg-cd__error')?.textContent).toContain('changed elsewhere');
    api.trash.mockResolvedValueOnce({ id: 8, trashed: true, status: 'trash', revision: 9 });
    $$('.q-card__actions button')[1]?.click();
    await settle();
    expect(api.trash).toHaveBeenLastCalledWith(8, 8);
  });

  it('trash on 404 informs and refreshes', async () => {
    const api = mockApi({ list: vi.fn(async () => pageOf([makeGallery({ id: 8 })])) });
    api.trash.mockRejectedValueOnce(new ApiError('swg_gallery_not_found', 'gone', 404));
    const { wrapper } = await open(api);
    await rowAction(wrapper, 8, 'trash');
    $$('.q-card__actions button')[1]?.click();
    await settle();
    expect(live()).toContain('no longer exists');
    expect(api.list).toHaveBeenCalledTimes(2);
  });

  it('trash on 403 keeps the dialog with a translated message', async () => {
    const api = mockApi({ list: vi.fn(async () => pageOf([makeGallery({ id: 8 })])) });
    api.trash.mockRejectedValueOnce(new ApiError('swg_forbidden', 'no', 403));
    const { wrapper } = await open(api);
    await rowAction(wrapper, 8, 'trash');
    $$('.q-card__actions button')[1]?.click();
    await settle();
    expect($('[role="alertdialog"] .swg-cd__error')?.textContent).toContain('not allowed');
  });

  it('restores from the trash view without a dialog and announces the draft', async () => {
    const trashed = makeGallery({ id: 3, title: 'Old', status: 'trash', revision: 7 });
    const api = mockApi({
      list: vi.fn(async () => pageOf([trashed])),
      restore: vi.fn(async () => ({ ...trashed, status: 'draft' as const, revision: 8 })),
    });
    const { wrapper } = await open(api, { path: '/galleries?status=trash' });
    expect(wrapper.find('[data-gallery-id="3"]').text()).toContain('In trash');
    await rowAction(wrapper, 3, 'restore');
    expect(api.restore).toHaveBeenCalledWith(3, 7);
    expect(live()).toBe('Gallery "Old" was restored as a draft.');
    expect(api.list).toHaveBeenCalledTimes(2);
  });

  it('a failed restore informs and reloads (404, invalid state, other)', async () => {
    const trashed = makeGallery({ id: 3, status: 'trash' });
    const api = mockApi({ list: vi.fn(async () => pageOf([trashed])) });
    const { wrapper } = await open(api, { path: '/galleries?status=trash' });
    api.restore.mockRejectedValueOnce(new ApiError('swg_gallery_not_found', 'x', 404));
    await rowAction(wrapper, 3, 'restore');
    expect(live()).toContain('no longer exists');
    api.restore.mockRejectedValueOnce(new ConflictError('swg_invalid_state', 'x'));
    await rowAction(wrapper, 3, 'restore');
    expect(live()).toContain('different state');
    api.restore.mockRejectedValueOnce(new ConflictError('swg_revision_conflict', 'x'));
    await rowAction(wrapper, 3, 'restore');
    expect(live()).toContain('changed elsewhere');
    expect(api.list).toHaveBeenCalledTimes(4);
  });

  it('delete permanently needs the typed title and never claims to delete images', async () => {
    const trashed = makeGallery({ id: 3, title: 'Old Album', status: 'trash', revision: 7 });
    const api = mockApi({
      list: vi.fn(async () => pageOf([trashed])),
      deletePermanently: vi.fn(async () => ({ id: 3, deleted: true as const })),
    });
    const { wrapper } = await open(api, { path: '/galleries?status=trash' });
    await rowAction(wrapper, 3, 'delete');
    const dialog = $('[role="alertdialog"]');
    expect(dialog?.querySelector('.swg-cd__target-list')?.textContent).toBe('Old Album');
    expect(dialog?.textContent).toContain('never deleted and stay in the Media Library');
    expect(dialog?.textContent).toContain('This cannot be undone.');
    const confirm = $$('.q-card__actions button')[1];
    expect(confirm?.hasAttribute('disabled')).toBe(true);
    const label = dialog?.querySelector('label')?.textContent ?? '';
    expect(label).toContain('Old Album');
    await typeInto('[role="alertdialog"] input', 'Old Albu');
    expect($$('.q-card__actions button')[1]?.hasAttribute('disabled')).toBe(true);
    await typeInto('[role="alertdialog"] input', 'Old Album');
    expect($$('.q-card__actions button')[1]?.hasAttribute('disabled')).toBe(false);
    $$('.q-card__actions button')[1]?.click();
    await settle();
    expect(api.deletePermanently).toHaveBeenCalledWith(3, 7);
    expect(live()).toBe('Gallery "Old Album" was deleted permanently.');
    expect(api.list).toHaveBeenCalledTimes(2);
  });

  it('delete reports a conflict inside the dialog', async () => {
    const trashed = makeGallery({ id: 3, title: 'A', status: 'trash' });
    const api = mockApi({
      list: vi.fn(async () => pageOf([trashed])),
      get: vi.fn(async () => ({ ...trashed, revision: 12 })),
    });
    api.deletePermanently.mockRejectedValueOnce(new ConflictError('swg_revision_conflict', 'x'));
    const { wrapper } = await open(api, { path: '/galleries?status=trash' });
    await rowAction(wrapper, 3, 'delete');
    await typeInto('[role="alertdialog"] input', 'A');
    $$('.q-card__actions button')[1]?.click();
    await settle();
    expect($('[role="alertdialog"] .swg-cd__error')?.textContent).toContain('changed elsewhere');
  });
});

describe('copy shortcode', () => {
  const rows = () => mockApi({ list: vi.fn(async () => pageOf([makeGallery({ id: 12 })])) });

  it('uses the Clipboard API and confirms through the live region and a toast', async () => {
    const writeText = vi.fn(async () => {});
    vi.stubGlobal('navigator', { clipboard: { writeText } });
    const { wrapper } = await open(rows());
    await rowAction(wrapper, 12, 'copy');
    expect(writeText).toHaveBeenCalledWith('[sw_gallery id="12"]');
    expect(live()).toBe('Shortcode [sw_gallery id="12"] copied to the clipboard.');
    await vi.waitFor(() => expect(toast('[sw_gallery id="12"]')).toBeDefined());
    expect($('.swg-scd')).toBeNull();
  });

  it('shows a visible, selected fallback when the Clipboard API is unavailable', async () => {
    vi.stubGlobal('navigator', {});
    const select = vi.spyOn(HTMLInputElement.prototype, 'select');
    const { wrapper } = await open(rows());
    await rowAction(wrapper, 12, 'copy');
    const dialog = $('[role="dialog"].swg-scd, .swg-scd');
    expect(dialog).not.toBeNull();
    const input = $<HTMLInputElement>('.swg-scd input');
    expect(input?.value).toBe('[sw_gallery id="12"]');
    expect(input?.readOnly).toBe(true);
    expect($('.swg-scd')?.textContent).toContain('Copying automatically is not available');
    await vi.waitFor(() => expect(document.activeElement).toBe(input));
    expect(select).toHaveBeenCalled();
    expect(live()).toBe('');
  });

  it('falls back when the browser refuses the write', async () => {
    vi.stubGlobal('navigator', {
      clipboard: {
        writeText: vi.fn(async () => {
          throw new DOMException('denied', 'NotAllowedError');
        }),
      },
    });
    const { wrapper } = await open(rows());
    await rowAction(wrapper, 12, 'copy');
    expect($<HTMLInputElement>('.swg-scd input')?.value).toBe('[sw_gallery id="12"]');
  });
});

describe('server hints and the row menu', () => {
  it('shows only the actions the hints allow, never editor/preview/open features', async () => {
    const api = mockApi({
      list: vi.fn(async () =>
        pageOf([
          makeGallery({ id: 1, canEdit: false, canDelete: false }),
          makeGallery({ id: 2 }),
          makeGallery({ id: 3, status: 'trash', canDelete: false }),
        ]),
      ),
    });
    const { wrapper } = await open(api);
    const actionsOf = async (id: number): Promise<string[]> => {
      const button = wrapper.find(`[data-gallery-id="${id}"] .swg-actions-btn`);
      if (!button.exists()) return [];
      await button.trigger('click');
      await settle();
      const labels = $$('[role="menuitem"]').map((i) => i.dataset.action ?? '');
      document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
      return labels;
    };
    expect(await actionsOf(1)).toEqual(['duplicate', 'copy']);
    mounted?.unmount();
    resetEnvironment();
    const second = await open(api);
    await second.wrapper.find('[data-gallery-id="2"] .swg-actions-btn').trigger('click');
    await settle();
    expect(
      $$('[role="menuitem"] .q-item__section--main').map((i) => i.textContent?.trim()),
    ).toEqual(['Edit details', 'Duplicate', 'Copy shortcode', 'Move to trash']);
    expect(wrapper.find('[data-gallery-id="3"] .swg-actions-btn').exists()).toBe(false);
  });

  it('exposes the menu with roles, labels and arrow-key navigation', async () => {
    const api = mockApi({
      list: vi.fn(async () => pageOf([makeGallery({ id: 2, title: 'Pics' })])),
    });
    const { wrapper } = await open(api);
    const button = wrapper.find('[data-gallery-id="2"] .swg-actions-btn');
    expect(button.attributes('aria-label')).toBe('Actions for gallery Pics');
    expect(button.attributes('aria-haspopup')).toBe('menu');
    await button.trigger('click');
    await settle();
    const menu = $('[role="menu"]');
    expect(menu?.getAttribute('aria-label')).toBe('Actions for gallery Pics');
    const items = $$('[role="menuitem"]');
    items[0]?.focus();
    items[0]?.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
    expect(document.activeElement).toBe(items[1]);
    items[1]?.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true }));
    items[0]?.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true }));
    expect(document.activeElement).toBe(items[items.length - 1]);
    items[0]?.dispatchEvent(new KeyboardEvent('keydown', { key: 'a', bubbles: true }));
    expect(document.activeElement).toBe(items[items.length - 1]);
  });

  it('table rows offer the same actions', async () => {
    window.localStorage.setItem(GALLERY_VIEW_KEY, 'table');
    const api = mockApi({
      list: vi.fn(async () => pageOf([makeGallery({ id: 2 })])),
      duplicate: vi.fn(async () => makeGallery({ id: 3 })),
    });
    const { wrapper } = await open(api);
    await rowAction(wrapper, 2, 'duplicate');
    expect($('.swg-sheet__title')?.textContent).toBe('Duplicate gallery');
  });
});

describe('accessibility and styling guards', () => {
  it('labels the controls and keeps one h2 and h3 per card', async () => {
    const api = mockApi({ list: vi.fn(async () => pageOf([makeGallery({ id: 1 })])) });
    const { wrapper } = await open(api);
    expect(
      wrapper.find('input[type="search"]').attributes('aria-label') ??
        wrapper.find('.swg-gtoolbar__search').text(),
    ).toContain('Search galleries');
    expect(wrapper.findAll('fieldset.swg-seg legend').map((l) => l.text())).toEqual([
      'Filter by status',
      'View',
    ]);
    expect(wrapper.findAll('h1')).toHaveLength(0);
    expect(wrapper.findAll('h2')).toHaveLength(1);
    expect(wrapper.findAll('.swg-gcard h3')).toHaveLength(1);
    expect(wrapper.find('section').attributes('aria-label')).toBe('Gallery list');
  });

  it('component styles use design tokens and logical properties only', async () => {
    const { readFileSync, readdirSync } = await import('node:fs');
    const { join } = await import('node:path');
    const dir = join(import.meta.dirname, '../../src/features/galleries');
    const files: string[] = [];
    const walk = (d: string): void => {
      for (const entry of readdirSync(d, { withFileTypes: true })) {
        const path = join(d, entry.name);
        if (entry.isDirectory()) walk(path);
        else if (entry.name.endsWith('.vue')) files.push(path);
      }
    };
    walk(dir);
    expect(files.length).toBeGreaterThan(5);
    for (const file of files) {
      const style = /<style[^>]*>([\s\S]*?)<\/style>/.exec(readFileSync(file, 'utf8'))?.[1] ?? '';
      expect(style, `${file}: hard-coded colour`).not.toMatch(/#[0-9a-fA-F]{3,8}\b|rgba?\(/);
      expect(style, `${file}: physical property`).not.toMatch(
        /\b(margin|padding)-(left|right)\b|\b(left|right)\s*:|text-align:\s*(left|right)\b|\b(width|height)\s*:\s*100%/,
      );
    }
  });
});
