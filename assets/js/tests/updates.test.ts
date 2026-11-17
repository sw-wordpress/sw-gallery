import { afterEach, describe, expect, it, vi } from 'vitest';

afterEach(() => {
  document.body.innerHTML = '';
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  vi.useRealTimers();
  vi.resetModules();
  delete (window as unknown as Record<string, unknown>).swgVerifiedUpdates;
});
const flush = async () => {
  for (let i = 0; i < 8; i++) await Promise.resolve();
};
async function boot(due = false, status = 200) {
  document.body.innerHTML =
    '<button id="swg-verified-updates-check">Check</button><span id="swg-verified-updates-result" hidden></span>';
  Object.assign(window, {
    swgVerifiedUpdates: {
      url: '/wp-admin/admin-ajax.php',
      action: 'swg_check_github_updates',
      nonce: 'nonce',
      due,
      messages: { checking: 'Checking', failed: 'Failed', done: 'Done' },
    },
  });
  const fetcher = vi.fn(async () => ({
    ok: status === 200,
    json: async () => ({ data: { message: '<b>Verified</b>' } }),
  }));
  vi.stubGlobal('fetch', fetcher);
  const path = '../sw-gallery-updates.js';
  await import(/* @vite-ignore */ path);
  return fetcher;
}
describe('native plugin management update checks', () => {
  it('sends only a manual check with nonce; renders untrusted response as text and prevents double clicks', async () => {
    const fetcher = await boot();
    const button = document.querySelector('button')!;
    button.click();
    button.click();
    expect(fetcher).toHaveBeenCalledTimes(1);
    const init = fetcher.mock.calls[0] as unknown as [string, RequestInit];
    expect(String(init[1].body)).toBe('action=swg_check_github_updates&nonce=nonce&manual=1');
    expect(init[1].credentials).toBe('same-origin');
    await flush();
    expect(document.querySelector('span')?.textContent).toBe('<b>Verified</b>');
    expect(document.querySelector('span b')).toBeNull();
    expect(button.disabled).toBe(false);
  });
  it('does not automatically check until the page has loaded', async () => {
    vi.useFakeTimers();
    vi.spyOn(document, 'readyState', 'get').mockReturnValue('loading');
    const fetcher = await boot(true);
    expect(fetcher).not.toHaveBeenCalled();
    window.dispatchEvent(new Event('load'));
    await vi.advanceTimersByTimeAsync(1600);
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(String((fetcher.mock.calls[0] as unknown as [string, RequestInit])[1].body)).toContain(
      'manual=0',
    );
    expect(document.querySelector('span')?.hidden).toBe(true);
  });
  it('uses idle scheduling when the page is already complete', async () => {
    vi.spyOn(document, 'readyState', 'get').mockReturnValue('complete');
    const idle = vi.fn((callback: () => void) => {
      callback();
      return 1;
    });
    vi.stubGlobal('requestIdleCallback', idle);
    const fetcher = await boot(true);
    expect(idle).toHaveBeenCalledOnce();
    await flush();
    expect(fetcher).toHaveBeenCalledOnce();
  });
  it('handles failed checks and restores the button', async () => {
    await boot(false, 500);
    document.querySelector('button')!.click();
    await flush();
    expect(document.querySelector('span')?.textContent).toBe('Failed');
    expect(document.querySelector('button')?.disabled).toBe(false);
  });
  it('does nothing without this plugin configuration', async () => {
    const fetcher = vi.fn();
    vi.stubGlobal('fetch', fetcher);
    const path = '../sw-gallery-updates.js';
    await import(/* @vite-ignore */ path);
    window.dispatchEvent(new Event('load'));
    await flush();
    expect(fetcher).not.toHaveBeenCalled();
  });
});
