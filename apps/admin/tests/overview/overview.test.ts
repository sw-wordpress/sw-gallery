import { flushPromises } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../../src/api/client';
import type { GalleryListQuery } from '../../src/api/galleries';
import { makeGallery, mockApi, pageOf } from '../galleries/support';
import type { MockApi } from '../galleries/support';
import { mountApp, resetEnvironment } from '../support/mountApp';
import { fakeStatusApi, makeStatus } from '../status/support';

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
/** `label value` pairs of the stat tiles (the icon ligature text is not part of them). */
const tiles = (): string[] =>
  $$('.swg-stat').map(
    (t) =>
      `${t.querySelector('.swg-stat__label')?.textContent?.trim()} ${t
        .querySelector('.swg-stat__value')
        ?.textContent?.trim()}`,
  );
const text = (): string => $('.swg-overview')?.textContent?.replace(/\s+/g, ' ') ?? '';

async function settle(): Promise<void> {
  await flushPromises();
  await new Promise((resolve) => setTimeout(resolve, 0));
  await flushPromises();
}

const TOTALS = { all: 14, publish: 6, draft: 5, private: 3 };

/** Totals per status group, newest-modified galleries for the "recent" request. */
function galleriesApi(recent = [makeGallery({ id: 7, title: 'Summer 2025' })]): MockApi {
  return mockApi({
    list: vi.fn(async (query: GalleryListQuery = {}) => {
      if (query.perPage === 1) {
        const key = query.status?.join(',');
        const total =
          key === undefined
            ? TOTALS.all
            : key === 'publish'
              ? TOTALS.publish
              : key === 'draft,pending'
                ? TOTALS.draft
                : TOTALS.private;
        return pageOf([], total);
      }
      return pageOf(recent, TOTALS.all);
    }),
  });
}

async function open(
  api: MockApi,
  options: {
    capabilities?: string[];
    locale?: string;
    statusApi?: ReturnType<typeof fakeStatusApi>;
    path?: string;
  } = {},
) {
  const result = await mountApp({ galleriesApi: api, ...options });
  mounted = result.wrapper;
  await settle();
  return result;
}

describe('Overview', () => {
  it('shows gallery counts from the list totals and the recent galleries', async () => {
    const api = galleriesApi([
      makeGallery({ id: 7, title: 'Summer 2025', itemCount: 3 }),
      makeGallery({ id: 8, title: 'Locked', canEdit: false }),
    ]);
    await open(api);
    expect(tiles()).toEqual([
      'All galleries 14',
      'Published 6',
      'Drafts 5',
      'Private 3',
      'In trash 3',
    ]);
    const queries = api.list.mock.calls.map((c) => c[0]);
    expect(queries).toContainEqual({ perPage: 1 });
    expect(queries).toContainEqual({ status: ['publish'], perPage: 1 });
    expect(queries).toContainEqual({ status: ['draft', 'pending'], perPage: 1 });
    expect(queries).toContainEqual({ status: ['private'], perPage: 1 });
    expect(queries).toContainEqual({ perPage: 5 });
    const rows = $$('.swg-overview__recent li');
    expect(rows).toHaveLength(2);
    expect(rows[0]?.querySelector('a')?.getAttribute('href')).toBe('/galleries/7');
    expect(rows[0]?.textContent).toContain('3 images');
    expect(rows[1]?.textContent).toContain('Locked');
    expect(rows[1]?.querySelector('a')).toBeNull(); // not editable: no link
  });

  it('shows the trash count and the health line for managers', async () => {
    await open(galleriesApi());
    expect(tiles()).toContain('In trash 3');
    const health = $('.swg-overview__health')?.textContent ?? '';
    expect(health).toContain('Schema is up to date');
    expect(health).toContain('No recent errors');
    expect($('.swg-overview a[href="/status"]')).not.toBeNull();
  });

  it('flags a pending migration, recent errors and a missing build in the health line', async () => {
    const statusApi = fakeStatusApi(
      makeStatus({
        schema: { stored: 2, current: 3, migrationPending: true, itemTablePresent: true },
        recentErrors: [
          { time: '2026-11-11T09:30:00Z', code: 'a', context: {} },
          { time: '2026-11-11T09:31:00Z', code: 'b', context: {} },
        ],
        adminBuild: false,
      }),
    );
    await open(galleriesApi(), { statusApi });
    const health = $('.swg-overview__health')?.textContent ?? '';
    expect(health).toContain('Migration pending');
    expect(health).toContain('2 recent errors');
    expect(health).toContain('Needs attention');
  });

  it('keeps the page usable when only the health summary fails, and retries', async () => {
    const statusApi = fakeStatusApi();
    statusApi.get.mockRejectedValueOnce(new ApiError('swg_http_500', 'x', 500));
    await open(galleriesApi(), { statusApi });
    expect($$('.swg-stat').length).toBeGreaterThan(0);
    expect($('.swg-overview__health-error')?.textContent).toContain('could not be loaded');
    $$('.swg-overview__health-error button')[0]?.click();
    await settle();
    expect($('.swg-overview__health')?.textContent).toContain('Schema is up to date');
  });

  it('hides trash, health and status for non-managers and counts only their own galleries', async () => {
    const statusApi = fakeStatusApi();
    await open(galleriesApi(), {
      capabilities: ['edit_posts', 'upload_files'],
      statusApi,
    });
    expect(statusApi.get).not.toHaveBeenCalled();
    expect(text()).not.toContain('In trash');
    expect(text()).not.toContain('Plugin health');
    expect(text()).toContain('Your galleries');
    expect(tiles()).toContain('Your galleries 14');
  });

  it('offers quick actions according to the capabilities', async () => {
    await open(galleriesApi());
    const actions = $('.swg-overview__actions')?.textContent ?? '';
    expect(actions).toContain('New gallery');
    expect(actions).toContain('Open Media Overview');
    mounted?.unmount();
    resetEnvironment();
    await open(galleriesApi(), { capabilities: ['edit_posts'] });
    const limited = $('.swg-overview__actions')?.textContent ?? '';
    expect(limited).toContain('New gallery');
    expect(limited).not.toContain('Media Overview');
    mounted?.unmount();
    resetEnvironment();
    await open(galleriesApi(), { capabilities: [] });
    expect(document.body.textContent).toContain('No access');
  });

  it('"New gallery" opens the create sheet of the gallery list', async () => {
    const api = galleriesApi();
    const { router } = await open(api);
    $$('.swg-overview__actions button')
      .find((b) => b.textContent?.includes('New gallery'))
      ?.click();
    await vi.waitFor(() => expect(router.currentRoute.value.path).toBe('/galleries'));
    await settle();
    expect($('.swg-sheet')?.textContent).toContain('New gallery');
    // the flag is dropped again: a reload does not reopen the sheet
    expect(router.currentRoute.value.query.new).toBeUndefined();
  });

  it('opens the Media Overview from the quick actions', async () => {
    const { router } = await open(galleriesApi());
    $$('.swg-overview__actions a')
      .find((a) => a.textContent?.includes('Media Overview'))
      ?.click();
    await vi.waitFor(() => expect(router.currentRoute.value.path).toBe('/media'));
  });

  it('shows an empty state with a create action when there are no galleries', async () => {
    const api = mockApi();
    await open(api);
    expect(text()).toContain('No galleries yet');
    expect($$('.swg-empty button').some((b) => b.textContent?.includes('New gallery'))).toBe(true);
  });

  it('shows an error with a retry', async () => {
    const api = galleriesApi();
    api.list.mockRejectedValueOnce(new ApiError('swg_http_500', 'x', 500));
    await open(api);
    expect(document.body.textContent).toContain('The overview could not be loaded');
    $$('button')
      .find((b) => b.textContent?.includes('Retry'))
      ?.click();
    await settle();
    expect($$('.swg-stat').length).toBeGreaterThan(0);
  });

  it('is available in German', async () => {
    await open(galleriesApi(), { locale: 'de_DE' });
    expect(text()).toContain('Zuletzt geändert');
    expect(text()).toContain('Zustand des Plugins');
  });
});
