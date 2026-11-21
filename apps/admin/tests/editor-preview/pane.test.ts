import { flushPromises } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../../src/api/client';
import { mountApp, resetEnvironment } from '../support/mountApp';
import { FakeServer, makePreview } from '../editor/support';

let mounted: VueWrapper | null = null;

afterEach(() => {
  mounted?.unmount();
  mounted = null;
  resetEnvironment();
  vi.unstubAllGlobals();
  delete (window as unknown as { __pwned?: unknown }).__pwned;
});

const CAPS = ['edit_posts', 'edit_others_posts', 'upload_files'];
const ORIGIN = window.location.origin;

async function settle(): Promise<void> {
  await flushPromises();
  await new Promise((resolve) => setTimeout(resolve, 0));
  await flushPromises();
}

async function open(server = new FakeServer(), options: { locale?: string; stage?: boolean } = {}) {
  const result = await mountApp({
    path: '/galleries/7',
    capabilities: CAPS,
    locale: options.locale,
    editorApi: server.api(),
  });
  mounted = result.wrapper;
  await settle();
  return { ...result, server };
}

const $ = <T extends Element = HTMLElement>(selector: string): T | null =>
  document.body.querySelector<T>(selector);
const $$ = (selector: string): HTMLElement[] =>
  Array.from(document.body.querySelectorAll<HTMLElement>(selector));
const live = (): string => $('.swg-shell > [role="status"]')?.textContent?.trim() ?? '';
const frame = (): HTMLIFrameElement | null => $<HTMLIFrameElement>('iframe.swg-pvf');
const button = (label: string): HTMLButtonElement | undefined =>
  $$('button').find(
    (b) =>
      b.querySelector('.block')?.textContent?.trim() === label ||
      b.getAttribute('aria-label') === label,
  ) as HTMLButtonElement | undefined;
async function click(el: Element | null | undefined): Promise<void> {
  if (el === null || el === undefined) throw new Error('element missing');
  el.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
  await settle();
}
function setInput(input: HTMLInputElement | HTMLTextAreaElement, value: string): void {
  input.value = value;
  input.dispatchEvent(new Event('input', { bubbles: true }));
}
const previewButton = (): HTMLButtonElement | undefined => button('Preview');
const dialog = (): HTMLElement | null => $('.swg-pvd');
const showPreview = (): Promise<void> => click(previewButton());
const closePreview = async (): Promise<void> => {
  await click(button('Close preview'));
  await waitFor(() => expect($('.swg-pvd')).toBeNull());
};
const showLayoutPanel = async (): Promise<void> => {
  const radio = $$('.swg-seg input[type="radio"]').find((r) =>
    r.closest('label')?.textContent?.includes('Layout'),
  ) as HTMLInputElement;
  radio.checked = true;
  radio.dispatchEvent(new Event('change', { bubbles: true }));
  await settle();
};
const columnsInput = (): HTMLInputElement =>
  $('.swg-lsp input[type="number"][min]') as HTMLInputElement;
const waitFor = (check: () => void): Promise<void> =>
  vi.waitFor(check, { timeout: 4000, interval: 25 });
const srcdoc = (): string => frame()?.getAttribute('srcdoc') ?? '';

describe('preview pane: opening and requests', () => {
  it('sends nothing while the dialog is closed and renders once the Preview button opens it', async () => {
    const { server } = await open();
    await new Promise((resolve) => setTimeout(resolve, 600));
    expect(server.previewRequests).toHaveLength(0);
    expect(dialog()).toBeNull();
    expect(previewButton()?.disabled).toBe(false);

    await showPreview();
    await waitFor(() => expect(frame()).not.toBeNull());
    expect(server.previewRequests).toEqual([
      {
        layout: 'grid',
        sortMode: 'manual',
        galleryPerPage: null,
        galleryHover: null,
        settings: {},
        page: 1,
      },
    ]);
    expect(live()).toContain('Preview updated. Page 1 of 1.');
    // The images grid keeps the whole main column: no tabs, no side-by-side pane.
    expect($$('[role="tab"]')).toHaveLength(0);
    expect($$('[role="listbox"]')).toHaveLength(1);
  });

  it('shows the markup only inside a sandboxed srcdoc iframe, never in the admin DOM', async () => {
    const hostile =
      '<div class="swg-gallery"><img src="x" onerror="window.__pwned=1"><script>window.__pwned=2</script><p>Caption &lt;b&gt;</p></div>';
    const server = new FakeServer();
    server.previewHandler = async () => makePreview({ html: hostile });
    await open(server);
    await showPreview();
    await waitFor(() => expect(frame()).not.toBeNull());
    const el = frame() as HTMLIFrameElement;
    expect(el.getAttribute('sandbox')).toBe('allow-scripts');
    expect(el.getAttribute('title')).toBe('Preview of the gallery as it appears on the website');
    expect(el.getAttribute('src')).toBeNull();
    expect(srcdoc()).toContain(hostile);
    expect(srcdoc()).toContain('Content-Security-Policy');
    // Nothing of the response became part of the admin document.
    expect($('.swg-gallery')).toBeNull();
    expect(document.body.querySelectorAll('script')).toHaveLength(0);
    expect(document.body.querySelector('img[src="x"]')).toBeNull();
    expect((window as unknown as { __pwned?: unknown }).__pwned).toBeUndefined();
  });

  it('loads exactly the validated same-origin assets and says when some were dropped', async () => {
    const server = new FakeServer();
    server.previewHandler = async () =>
      makePreview({
        assets: {
          css: [
            `${ORIGIN}/build/layouts.css?ver=1`,
            'javascript:alert(1)',
            'https://evil.test/x.css',
          ],
          js: ['data:text/javascript,alert(1)', `${ORIGIN}/build/lightbox.js?ver=1`],
        },
      });
    await open(server);
    await showPreview();
    await waitFor(() => expect(frame()).not.toBeNull());
    const doc = srcdoc();
    expect(doc).toContain(`<link rel="stylesheet" href="${ORIGIN}/build/layouts.css?ver=1">`);
    expect(doc).toContain(`<script defer src="${ORIGIN}/build/lightbox.js?ver=1"></script>`);
    expect(doc).not.toContain('javascript:alert');
    expect(doc).not.toContain('evil.test');
    expect(doc).not.toContain('data:text/javascript');
    expect($('.swg-pv__assets-dropped')?.textContent).toContain('were not loaded');
  });

  it('says so when the server has no frontend styles', async () => {
    await open();
    await showPreview();
    await waitFor(() => expect(frame()).not.toBeNull());
    expect($('.swg-pv')?.textContent).toContain('The frontend styles are not available');
  });

  it('disables the Preview button with a reason for a gallery the user cannot edit', async () => {
    const server = new FakeServer(2, { canEdit: false, canPublish: false });
    await open(server);
    const btn = previewButton() as HTMLButtonElement;
    expect(btn.disabled).toBe(true);
    const reason = 'You are not allowed to edit this gallery, so there is no preview.';
    expect(btn.closest('.swg-ed__previewwrap')?.getAttribute('title')).toBe(reason);
    const describedBy = btn.getAttribute('aria-describedby') as string;
    expect(document.getElementById(describedBy)?.textContent).toBe(reason);
    await click(btn);
    await new Promise((resolve) => setTimeout(resolve, 600));
    expect(dialog()).toBeNull();
    expect(server.previewRequests).toHaveLength(0);
  });
});

describe('preview pane: staging interplay', () => {
  it('previews unsaved settings, says so, and does not touch the saved gallery', async () => {
    const { server } = await open();
    await showPreview();
    await waitFor(() => expect(server.previewRequests).toHaveLength(1));
    expect($('.swg-pv__unsaved-settings')).toBeNull();
    await showLayoutPanel();
    setInput(columnsInput(), '5');
    await settle();
    expect($('.swg-pv__unsaved-settings')?.textContent).toContain('unsaved layout');
    await waitFor(() => expect(server.previewRequests).toHaveLength(2));
    expect(server.previewRequests[1]?.settings).toEqual({ columns: 5 });
    expect(server.calls).not.toContain('details');
    expect(server.gallery.settings).toEqual({});
  });

  it('title and description edits do not re-render', async () => {
    const { server } = await open();
    await showPreview();
    await waitFor(() => expect(server.previewRequests).toHaveLength(1));
    setInput($$('.swg-gdp input')[0] as HTMLInputElement, 'Another title');
    await settle();
    await new Promise((resolve) => setTimeout(resolve, 1500));
    expect(server.previewRequests).toHaveLength(1);
    expect($('.swg-pv__unsaved-settings')).toBeNull();
  });

  it('says that staged removals are not part of the preview and does not re-render for them', async () => {
    const { server } = await open();
    await showPreview();
    await waitFor(() => expect(server.previewRequests).toHaveLength(1));
    await closePreview();
    await click($$('[role="option"]')[1]);
    await click($('.swg-ed__remove'));
    await click(button('Remove image'));
    await showPreview();
    await waitFor(() => expect(server.previewRequests).toHaveLength(2));
    expect($('.swg-pv__unsaved-items')?.textContent).toContain(
      'saved image order, selection and texts',
    );
    await new Promise((resolve) => setTimeout(resolve, 1200));
    expect(server.previewRequests).toHaveLength(2);
  });

  it('re-renders after a successful save and after images were added', async () => {
    const { server } = await open();
    await showPreview();
    await waitFor(() => expect(server.previewRequests).toHaveLength(1));
    await showLayoutPanel();
    setInput(columnsInput(), '4');
    await settle();
    await waitFor(() => expect(server.previewRequests).toHaveLength(2));
    await click(button('Save'));
    await waitFor(() => expect(server.previewRequests).toHaveLength(3));
    expect(server.previewRequests[2]?.settings).toEqual({ columns: 4 });
    expect($('.swg-pv__unsaved-settings')).toBeNull();
  });
});

describe('preview pane: controls', () => {
  it('switches the viewport width and labels it', async () => {
    await open();
    await showPreview();
    await waitFor(() => expect(frame()).not.toBeNull());
    const device = (): string => $('.swg-pv__device')?.getAttribute('style') ?? '';
    expect(device()).toContain('inline-size: 100%');
    const pick = async (label: string): Promise<void> => {
      const radio = $$('.swg-pv .swg-seg input[type="radio"]').find((r) =>
        r.closest('label')?.textContent?.includes(label),
      ) as HTMLInputElement;
      radio.checked = true;
      radio.dispatchEvent(new Event('change', { bubbles: true }));
      await settle();
    };
    await pick('Mobile');
    expect(device()).toContain('inline-size: 375px');
    expect($('.swg-pv__width')?.textContent).toContain('Preview width: 375 px');
    await pick('Tablet');
    expect(device()).toContain('inline-size: 768px');
    await pick('Desktop');
    expect(device()).toContain('inline-size: 1280px');
    await pick('Fit');
    expect(device()).toContain('inline-size: 100%');
    expect($('.swg-pv__width')?.textContent).toContain('fits the available space');
  });

  it('pages through the preview', async () => {
    const server = new FakeServer();
    server.previewHandler = async (request) =>
      makePreview({ page: request.page ?? 1, totalPages: 3, totalItems: 25 });
    await open(server);
    await showPreview();
    await waitFor(() => expect($('.swg-pv__pagetext')).not.toBeNull());
    expect($('.swg-pv__pagetext')?.textContent).toBe('Page 1 of 3 · 25 images');
    expect(button('Previous preview page')?.disabled).toBe(true);
    await click(button('Next preview page'));
    await waitFor(() => expect($('.swg-pv__pagetext')?.textContent).toContain('Page 2 of 3'));
    expect(server.previewRequests.at(-1)?.page).toBe(2);
    await click(button('Next preview page'));
    await waitFor(() => expect($('.swg-pv__pagetext')?.textContent).toContain('Page 3 of 3'));
    expect(button('Next preview page')?.disabled).toBe(true);
    await click(button('Previous preview page'));
    await waitFor(() => expect($('.swg-pv__pagetext')?.textContent).toContain('Page 2 of 3'));
  });

  it('changes the preview page size only for the request and starts again on page 1', async () => {
    const server = new FakeServer();
    server.previewHandler = async (request) =>
      makePreview({ page: request.page ?? 1, totalPages: request.perPage === 6 ? 5 : 1 });
    await open(server);
    await showPreview();
    await waitFor(() => expect(server.previewRequests).toHaveLength(1));
    expect(server.previewRequests[0]).not.toHaveProperty('perPage');
    const select = $('.swg-pv__perpage select') as HTMLSelectElement;
    select.value = '6';
    select.dispatchEvent(new Event('change', { bubbles: true }));
    await settle();
    await waitFor(() => expect(server.previewRequests).toHaveLength(2));
    expect(server.previewRequests[1]).toMatchObject({ perPage: 6, page: 1 });
    expect(server.gallery.settings).toEqual({});
    select.value = '';
    select.dispatchEvent(new Event('change', { bubbles: true }));
    await waitFor(() => expect(server.previewRequests).toHaveLength(3));
    expect(server.previewRequests[2]).not.toHaveProperty('perPage');
  });

  it('refreshes on demand', async () => {
    const { server } = await open();
    await showPreview();
    await waitFor(() => expect(server.previewRequests).toHaveLength(1));
    await click(button('Refresh preview'));
    await waitFor(() => expect(server.previewRequests).toHaveLength(2));
  });

  it('accepts only validated height reports from the frame', async () => {
    await open();
    await showPreview();
    await waitFor(() => expect(frame()).not.toBeNull());
    const el = frame() as HTMLIFrameElement;
    const token = /var T="([0-9a-f]+)"/.exec(srcdoc())?.[1] ?? '';
    expect(token).toHaveLength(32);
    const post = async (init: {
      origin: string;
      data: unknown;
      source?: Window | null;
    }): Promise<void> => {
      window.dispatchEvent(
        new MessageEvent('message', {
          origin: init.origin,
          data: init.data,
          source: init.source === undefined ? el.contentWindow : init.source,
        }),
      );
      await settle();
    };
    const height = (): string => el.style.blockSize || el.style.height;
    const good = { swgPreview: token, type: 'height', height: 777 };
    await post({ origin: 'https://evil.test', data: good });
    await post({ origin: 'null', data: { ...good, swgPreview: 'nope' } });
    await post({ origin: 'null', data: good, source: window });
    await post({ origin: 'null', data: { ...good, height: -5 } });
    expect(height()).toBe('480px');
    await post({ origin: 'null', data: good });
    expect(height()).toBe('777px');
    await post({ origin: 'null', data: { ...good, height: 90000 } });
    expect(height()).toBe('20000px');
    await post({ origin: 'null', data: { ...good, height: 10 } });
    expect(height()).toBe('480px');
  });

  it('follows the dark theme by rebuilding the document without a new request', async () => {
    const { server } = await open();
    await showPreview();
    await waitFor(() => expect(frame()).not.toBeNull());
    expect(srcdoc()).toContain('color-scheme:light');
    await click($('.swg-rail__theme'));
    await waitFor(() => expect(srcdoc()).toContain('color-scheme:dark'));
    expect(server.previewRequests).toHaveLength(1);
  });
});

describe('preview pane: diagnostics, empty and errors', () => {
  it('shows editor diagnostics outside the frame and explains an empty gallery', async () => {
    const server = new FakeServer(0);
    server.previewHandler = async () =>
      makePreview({
        html: '',
        totalItems: 0,
        diagnostics: [{ code: 'empty_gallery', message: 'This gallery has no images yet.' }],
      });
    await open(server);
    await showPreview();
    await waitFor(() => expect($('.swg-pv__empty')).not.toBeNull());
    expect(frame()).toBeNull();
    expect($('.swg-pv__empty')?.textContent).toContain('Nothing to preview yet');
    const details = $('.swg-pv__diag') as HTMLDetailsElement;
    expect(details.hasAttribute('open')).toBe(true);
    expect(details.textContent).toContain('Notes for editors (1)');
    expect(details.textContent).toContain('This gallery has no images yet.');
  });

  it('keeps diagnostics of a rendered gallery collapsed and renders them as text', async () => {
    const server = new FakeServer();
    server.previewHandler = async () =>
      makePreview({
        diagnostics: [{ code: 'x_ext_note', message: '2 images <b>skipped</b>' }],
      });
    await open(server);
    await showPreview();
    await waitFor(() => expect(frame()).not.toBeNull());
    const details = $('.swg-pv__diag') as HTMLDetailsElement;
    expect(details.hasAttribute('open')).toBe(false);
    expect(details.querySelector('b')).toBeNull();
    expect(details.textContent).toContain('2 images <b>skipped</b>');
  });

  it('keeps the last good preview greyed out on a server error and recovers with retry', async () => {
    const server = new FakeServer();
    await open(server);
    await showPreview();
    await waitFor(() => expect(frame()).not.toBeNull());
    server.previewHandler = async () => {
      throw new ApiError('swg_internal_error', 'secret internals', 500);
    };
    await click(button('Refresh preview'));
    await waitFor(() => expect($('.swg-pv__error')).not.toBeNull());
    expect($('.swg-pv__error')?.textContent).toContain('The server could not render the preview.');
    expect($('.swg-pv__error')?.textContent).toContain('Showing the last successful preview.');
    expect($('.swg-pv__error')?.textContent).not.toContain('secret internals');
    expect($('.swg-pv__stage')?.classList.contains('is-stale')).toBe(true);
    expect(frame()).not.toBeNull();
    // Announcements are rate-limited (one per 3 s, latest wins): the error follows as soon as the window allows.
    await waitFor(() => expect(live()).toContain('The server could not render the preview.'));

    server.previewHandler = null;
    await click(button('Try again'));
    await waitFor(() => expect($('.swg-pv__error')).toBeNull());
    expect($('.swg-pv__stage')?.classList.contains('is-stale')).toBe(false);
  });

  it.each([
    [new ApiError('swg_network', 'x', 0), 'The server could not be reached.'],
    [new ApiError('rest_forbidden', 'x', 403), 'You are not allowed to preview this gallery.'],
    [new ApiError('swg_gallery_not_found', 'x', 404), 'This gallery no longer exists.'],
    [new ApiError('swg_unknown_layout', 'x', 422), 'This layout is not available on the server.'],
    [new ApiError('swg_invalid_response', 'x', 200), 'The server sent an unexpected answer.'],
  ])('maps %# to an honest message and offers a retry', async (failure, text) => {
    const server = new FakeServer();
    server.previewHandler = async () => {
      throw failure;
    };
    await open(server);
    await showPreview();
    await waitFor(() => expect($('.swg-pv__error')).not.toBeNull());
    expect($('.swg-pv__error')?.textContent).toContain(text);
    expect($('.swg-pv__error')?.textContent).not.toContain('Showing the last successful preview.');
    expect(button('Try again')).toBeDefined();
    expect(frame()).toBeNull();
  });

  it('maps a 422 to the settings fields and keeps the last good preview', async () => {
    const server = new FakeServer();
    await open(server);
    await showPreview();
    await waitFor(() => expect(frame()).not.toBeNull());
    server.previewHandler = async () => {
      throw new ApiError(
        'swg_validation_failed',
        'x',
        422,
        {},
        { status: 422, errors: [{ path: '/columns', code: 'swg_invalid_type', message: 'x' }] },
      );
    };
    await showLayoutPanel();
    setInput(columnsInput(), '7');
    await settle();
    await waitFor(() => expect($('.swg-pv__error')).not.toBeNull());
    expect($('.swg-pv__error')?.textContent).toContain('Fix the marked settings.');
    expect($('.swg-lsp [role="alert"]')?.textContent).toContain('The server rejected one setting.');
    expect($('.swg-lsp')?.textContent).toContain('The server rejected this value.');
    expect($('.swg-pv__stage')?.classList.contains('is-stale')).toBe(true);
    // A valid value renders again and clears the server-reported field errors.
    server.previewHandler = null;
    setInput(columnsInput(), '3');
    await settle();
    await waitFor(() => expect($('.swg-pv__error')).toBeNull());
    expect($('.swg-lsp [role="alert"]')).toBeNull();
  });
});

describe('preview dialog', () => {
  it('is a modal dialog with an accessible name, header, hint and close button', async () => {
    await open();
    await showPreview();
    await waitFor(() => expect(frame()).not.toBeNull());
    const el = dialog() as HTMLElement;
    const root = el.closest('[role="dialog"]') as HTMLElement;
    expect(root.getAttribute('aria-modal')).toBe('true');
    const name = document.getElementById(root.getAttribute('aria-labelledby') as string);
    expect(name?.textContent).toBe('Live preview');
    expect(el.textContent).toContain('Shows your unsaved settings. Close the preview to change');
    expect(button('Close preview')).toBeDefined();
    expect($$('.swg-pv__bar')).toHaveLength(1);
  });

  it('closes with the close button, cancels its request and returns focus to the Preview button', async () => {
    const server = new FakeServer();
    let aborted = false;
    server.previewHandler = (_request, signal) =>
      new Promise((_resolve, reject) => {
        signal?.addEventListener('abort', () => {
          aborted = true;
          reject(new ApiError('swg_aborted', 'aborted', 0));
        });
      });
    await open(server);
    await showPreview();
    await waitFor(() => expect(server.previewRequests).toHaveLength(1));
    await closePreview();
    expect(aborted).toBe(true);
    await waitFor(() => expect(document.activeElement).toBe(previewButton()));
    await new Promise((resolve) => setTimeout(resolve, 1500));
    expect(server.previewRequests).toHaveLength(1);
  });

  it('closes with Escape', async () => {
    const { server } = await open();
    await showPreview();
    await waitFor(() => expect(frame()).not.toBeNull());
    const esc = { key: 'Escape', keyCode: 27, bubbles: true };
    window.dispatchEvent(new KeyboardEvent('keydown', esc));
    window.dispatchEvent(new KeyboardEvent('keyup', esc));
    await waitFor(() => expect($('.swg-pvd')).toBeNull());
    await waitFor(() => expect(document.activeElement).toBe(previewButton()));
    expect(server.previewRequests).toHaveLength(1);
  });

  it('builds a fresh frame and request per opening and keeps width and page size', async () => {
    const { server } = await open();
    await showPreview();
    await waitFor(() => expect(frame()).not.toBeNull());
    const first = frame();
    const radio = $$('.swg-pv .swg-seg input[type="radio"]').find((r) =>
      r.closest('label')?.textContent?.includes('Tablet'),
    ) as HTMLInputElement;
    radio.checked = true;
    radio.dispatchEvent(new Event('change', { bubbles: true }));
    const select = $('.swg-pv__perpage select') as HTMLSelectElement;
    select.value = '12';
    select.dispatchEvent(new Event('change', { bubbles: true }));
    await settle();
    await waitFor(() => expect(server.previewRequests).toHaveLength(2));
    await closePreview();
    await showPreview();
    await waitFor(() => expect(server.previewRequests).toHaveLength(3));
    await waitFor(() => expect(frame()).not.toBeNull());
    expect(frame()).not.toBe(first);
    expect(server.previewRequests[2]).toMatchObject({ perPage: 12, page: 1 });
    expect($('.swg-pv__device')?.getAttribute('style')).toContain('inline-size: 768px');
  });

  it('shows staged settings that were changed before opening, without saving them', async () => {
    const { server } = await open();
    await showLayoutPanel();
    setInput(columnsInput(), '5');
    await settle();
    await new Promise((resolve) => setTimeout(resolve, 600));
    expect(server.previewRequests).toHaveLength(0);
    await showPreview();
    await waitFor(() => expect(server.previewRequests).toHaveLength(1));
    expect(server.previewRequests[0]?.settings).toEqual({ columns: 5 });
    expect($('.swg-pv__unsaved-settings')).not.toBeNull();
    expect(server.calls).not.toContain('details');
  });

  it('leaves the staged edits alone when Ctrl+Z is pressed behind the open dialog', async () => {
    await open();
    await showLayoutPanel();
    setInput(columnsInput(), '5');
    await settle();
    await showPreview();
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'z', ctrlKey: true, bubbles: true }));
    await settle();
    expect($('.swg-pv__unsaved-settings')).not.toBeNull();
  });

  it('opens without animation when reduced motion is requested', async () => {
    vi.stubGlobal('matchMedia', (query: string) => ({
      matches: query.includes('prefers-reduced-motion'),
      media: query,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    }));
    await open();
    await showPreview();
    await waitFor(() => expect(frame()).not.toBeNull());
    expect(dialog()).not.toBeNull();
  });

  it('is fully translated (German)', async () => {
    await open(new FakeServer(), { locale: 'de_DE' });
    expect(previewButton()).toBeUndefined();
    await click(button('Vorschau'));
    await waitFor(() => expect(frame()).not.toBeNull());
    expect($('.swg-pvd')?.textContent).toContain('Live-Vorschau');
    expect($('.swg-pvd')?.textContent).toContain('Schließen Sie die Vorschau');
    expect(button('Vorschau schließen')).toBeDefined();
    expect($('.swg-pv__width')?.textContent).toContain('Vorschaubreite');
    expect($('.swg-pv__pagetext')?.textContent).toBe('Seite 1 von 1 · 4 Bilder');
    expect(frame()?.getAttribute('title')).toBe(
      'Vorschau der Galerie, wie sie auf der Website erscheint',
    );
  });
});
