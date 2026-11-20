import { flushPromises } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../../src/api/client';
import { buildSummary, contextText } from '../../src/features/status/summary';
import { mountApp, resetEnvironment } from '../support/mountApp';
import { fakeStatusApi, makeStatus } from './support';

let mounted: VueWrapper | null = null;

afterEach(() => {
  vi.unstubAllGlobals();
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
const live = (): string => $('.swg-shell > [role="status"]')?.textContent?.trim() ?? '';
const text = (): string => $('.swg-status')?.textContent ?? '';

async function settle(): Promise<void> {
  await flushPromises();
  await new Promise((resolve) => setTimeout(resolve, 0));
  await flushPromises();
}

async function open(
  api: ReturnType<typeof fakeStatusApi>,
  options: { capabilities?: string[]; locale?: string } = {},
) {
  const result = await mountApp({ path: '/status', statusApi: api, ...options });
  mounted = result.wrapper;
  await settle();
  return result;
}

describe('System Status page', () => {
  it('renders every section from the report', async () => {
    await open(fakeStatusApi());
    const content = text();
    // schema
    expect(content).toContain('Schema is up to date');
    expect(content).toContain('Stored version 3, current version 3');
    expect(content).toContain('Item table present');
    // counts per status as tiles (statuses without a count entry are not invented)
    const tiles = $$('.swg-stat').map((t) => t.textContent?.replace(/\s+/g, ' ').trim());
    expect(tiles).toContain('Published5');
    expect(tiles).toContain('Drafts2');
    expect(tiles).toContain('In trash3');
    expect(content).toContain('Gallery item rows');
    expect(content).toContain('120');
    expect(content).toContain('No relations point to missing images.');
    // errors, versions, runtime, hints
    expect(content).toContain('No errors recorded');
    expect(content).toContain('6.8.1');
    expect(content).toContain('8.3.4');
    expect(content).toContain('MariaDB 11.4.2');
    expect(content).toContain('No render cache active');
    expect(content).toContain('Admin app build is complete');
    expect(content).toContain('Galleries are not exported in 1.0.');
  });

  it('flags a pending migration, a missing table and a missing build', async () => {
    await open(
      fakeStatusApi(
        makeStatus({
          schema: {
            stored: 2,
            current: 3,
            migrationPending: true,
            newerThanCode: false,
            itemTablePresent: false,
          },
          adminBuild: false,
        }),
      ),
    );
    expect(text()).toContain('Migration pending');
    expect(text()).toContain('Stored version 2, current version 3');
    expect(text()).toContain('Item table missing');
    expect(text()).toContain('Admin app build is missing');
  });

  it('explains a capped item count', async () => {
    await open(
      fakeStatusApi(
        makeStatus({
          counts: {
            galleries: { publish: 1 },
            itemRows: { count: 50000, capped: true },
            orphanRelations: { count: null, complete: false },
          },
        }),
      ),
    );
    expect(text()).toContain('at least 50,000');
    expect(text()).toContain('Counting stops at the limit');
  });

  it('never presents a skipped orphan scan as zero and marks an incomplete scan as a lower bound', async () => {
    await open(
      fakeStatusApi(
        makeStatus({
          counts: {
            galleries: {},
            itemRows: { count: 10, capped: false },
            orphanRelations: { count: null, complete: false },
          },
        }),
      ),
    );
    const orphanTile = $$('.swg-stat').find((t) => t.textContent?.includes('Orphan relations'));
    expect(orphanTile?.textContent).toContain('Not checked');
    expect(orphanTile?.textContent).toContain('not the same as zero');
    expect(orphanTile?.textContent).not.toMatch(/\b0\b/);
    mounted?.unmount();
    resetEnvironment();
    await open(
      fakeStatusApi(
        makeStatus({
          counts: {
            galleries: {},
            itemRows: { count: 10, capped: false },
            orphanRelations: { count: 7, complete: false },
          },
        }),
      ),
    );
    const tile = $$('.swg-stat').find((t) => t.textContent?.includes('Orphan relations'));
    expect(tile?.textContent).toContain('at least 7');
    expect(tile?.textContent).toContain('lower bound');
    mounted?.unmount();
    resetEnvironment();
    await open(
      fakeStatusApi(
        makeStatus({
          counts: {
            galleries: {},
            itemRows: { count: 10, capped: false },
            orphanRelations: { count: 4, complete: true },
          },
        }),
      ),
    );
    const found = $$('.swg-stat').find((t) => t.textContent?.includes('Orphan relations'));
    expect(found?.textContent).toContain('4');
    expect(found?.textContent).toContain('WP-CLI');
  });

  it('lists recent errors with time, code and safe context', async () => {
    await open(
      fakeStatusApi(
        makeStatus({
          recentErrors: [
            {
              time: '2026-11-11T09:30:00Z',
              code: 'swg_upload_failed',
              context: { attachment: 12, retry: false, note: null },
            },
            { time: '2026-11-10T08:00:00Z', code: 'swg_hook_failed', context: {} },
          ],
        }),
      ),
    );
    const rows = $$('.swg-status__errors li');
    expect(rows).toHaveLength(2);
    expect(rows[0]?.textContent).toContain('swg_upload_failed');
    expect(rows[0]?.textContent).toContain('attachment=12, retry=false, note=null');
    expect(rows[0]?.querySelector('time')?.getAttribute('datetime')).toBe('2026-11-11T09:30:00Z');
    expect(rows[1]?.textContent).toContain('swg_hook_failed');
    expect(text()).not.toContain('No errors recorded');
  });

  it('shows a non-default cache state and multisite', async () => {
    await open(fakeStatusApi(makeStatus({ cache: { state: 'warm' }, multisite: true, hints: [] })));
    expect(text()).toContain('Cache: warm');
    expect($$('.swg-status__dl dd').map((d) => d.textContent?.trim())).toContain('Yes');
    expect(text()).not.toContain('Good to know');
  });

  it('refreshes on demand without polling and keeps the report when the refresh fails', async () => {
    const api = fakeStatusApi();
    await open(api);
    expect(api.get).toHaveBeenCalledTimes(1);
    api.get.mockRejectedValueOnce(new ApiError('swg_http_500', 'x', 500));
    button('Refresh')?.click();
    await settle();
    expect(api.get).toHaveBeenCalledTimes(2);
    expect($('.swg-inline-error')?.textContent).toContain('server');
    expect(text()).toContain('Schema is up to date');
    button('Retry')?.click();
    await settle();
    expect(api.get).toHaveBeenCalledTimes(3);
    expect($('.swg-inline-error')).toBeNull();
  });

  it('shows a load error with retry', async () => {
    const api = fakeStatusApi();
    api.get.mockRejectedValueOnce(new ApiError('swg_network', 'n', 0));
    await open(api);
    expect(document.body.textContent).toContain('The status could not be loaded');
    expect(button('Copy summary')?.hasAttribute('disabled')).toBe(true);
    button('Retry')?.click();
    await settle();
    expect(text()).toContain('Schema is up to date');
  });

  it('copies a plain-text summary without paths or secrets', async () => {
    const writeText = vi.fn(async () => undefined);
    vi.stubGlobal('navigator', { ...navigator, clipboard: { writeText } });
    await open(
      fakeStatusApi(
        makeStatus({
          recentErrors: [{ time: '2026-11-11T09:30:00Z', code: 'swg_x', context: { id: 5 } }],
        }),
      ),
    );
    button('Copy summary')?.click();
    await settle();
    expect(writeText).toHaveBeenCalledTimes(1);
    const copied = String((writeText.mock.calls[0] as unknown as [string])[0]);
    expect(copied).toContain('Plugin: 1.0.0');
    expect(copied).toContain('Database: MariaDB 11.4.2');
    expect(copied).toContain(
      'Galleries: draft=2, pending=0, publish=5, private=1, future=0, trash=3',
    );
    expect(copied).toContain('swg_x (id=5)');
    expect(copied).not.toMatch(
      /\/(var|home|usr|etc|srv|opt|tmp|www)\b|[A-Za-z]:\\|wp-content|ABSPATH|password|secret|token/i,
    );
    expect(live()).toBe('Diagnostic summary copied to the clipboard.');
    expect($('#swg-status-summary')).toBeNull();
  });

  it('falls back to a selectable text when the clipboard is unavailable', async () => {
    vi.stubGlobal('navigator', { ...navigator, clipboard: undefined });
    await open(fakeStatusApi());
    button('Copy summary')?.click();
    await settle();
    const area = $<HTMLTextAreaElement>('#swg-status-summary');
    expect(area?.value).toContain('sw-gallery diagnostic summary');
    expect(area?.readOnly).toBe(true);
    expect(live()).toContain('Copying is not available here');
  });

  it('is only available for managers', async () => {
    const api = fakeStatusApi();
    await open(api, { capabilities: ['edit_posts', 'upload_files'] });
    expect(api.get).not.toHaveBeenCalled();
    expect(document.body.textContent).toContain('No access');
    expect($$('.swg-nav-link').map((a) => a.textContent?.trim())).not.toContain('System Status');
  });

  it('flags a schema newer than the installed plugin in German and in diagnostics', async () => {
    const report = makeStatus({
      schema: {
        stored: 4,
        current: 3,
        migrationPending: false,
        newerThanCode: true,
        itemTablePresent: true,
      },
    });
    await open(fakeStatusApi(report), { locale: 'de_DE' });
    expect(text()).toContain('Datenbankschema ist neuer als das Plugin');
    expect(text()).not.toContain('Schema ist aktuell');
    expect(buildSummary(report)).toContain(
      'Stored schema is newer than the plugin; writes are blocked.',
    );
  });

  it('is available in German', async () => {
    await open(fakeStatusApi(), { locale: 'de_DE' });
    expect(text()).toContain('Schema ist aktuell');
    expect(text()).toContain('Keine Fehler protokolliert');
  });
});

describe('diagnostic summary', () => {
  it('describes skipped, incomplete and capped values', () => {
    const summary = buildSummary(
      makeStatus({
        multisite: true,
        schema: {
          stored: 1,
          current: 2,
          migrationPending: true,
          newerThanCode: false,
          itemTablePresent: false,
        },
        counts: {
          galleries: {},
          itemRows: { count: 50000, capped: true },
          orphanRelations: { count: 3, complete: false },
        },
        adminBuild: false,
      }),
      new Date('2026-11-13T10:00:00Z'),
    );
    expect(summary).toContain('Generated: 2026-11-13T10:00:00.000Z');
    expect(summary).toContain('(multisite)');
    expect(summary).toContain('migration PENDING, item table MISSING');
    expect(summary).toContain('Galleries: none');
    expect(summary).toContain('Item rows: 50000 (capped, at least)');
    expect(summary).toContain('Orphan relations: 3 (incomplete scan, at least)');
    expect(summary).toContain('Admin build: MISSING');
    expect(
      buildSummary(
        makeStatus({
          counts: {
            galleries: {},
            itemRows: { count: 1, capped: false },
            orphanRelations: { count: null, complete: false },
          },
        }),
      ),
    ).toContain('Orphan relations: not checked');
    expect(contextText({})).toBe('');
  });
});
