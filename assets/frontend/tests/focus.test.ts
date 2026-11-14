import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

function gallery(count: number, id = 'g'): HTMLElement {
  const container = document.createElement('div');
  container.className = 'swg-gallery swg-layout-focus';
  container.setAttribute('data-swg-gallery', '');
  container.dataset.swgLabels = JSON.stringify({ counter: '{current} von {total}' });
  const panels = Array.from(
    { length: count },
    (_, i) =>
      `<li class="swg-item swg-panel"><span class="swg-anchor" id="${id}-s${i + 1}"></span><a class="swg-link" href="/full-${i}.jpg"><img alt="${i}" loading="lazy" /></a></li>`,
  ).join('');
  const thumbs = Array.from(
    { length: count },
    (_, i) =>
      `<li class="swg-thumb-item"><a class="swg-thumb" href="#${id}-s${i + 1}"><img alt="${i}" /></a></li>`,
  ).join('');
  container.innerHTML = `<div class="swg-focus__body"><div class="swg-stage"><ul class="swg-items">${panels}</ul></div><ul class="swg-thumbs">${thumbs}</ul></div>`;
  document.body.append(container);
  return container;
}

const thumbs = (c: HTMLElement): HTMLAnchorElement[] =>
  Array.from(c.querySelectorAll<HTMLAnchorElement>('.swg-thumb'));
const panels = (c: HTMLElement): HTMLElement[] =>
  Array.from(c.querySelectorAll<HTMLElement>('.swg-panel'));
const currentIndex = (c: HTMLElement): number =>
  panels(c).findIndex((p) => p.classList.contains('swg-current'));
const links = (c: HTMLElement): string[] =>
  Array.from(c.querySelectorAll('a.swg-link')).map((a) => a.getAttribute('href') ?? '');

function click(el: Element, init: MouseEventInit = {}): MouseEvent {
  const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init });
  el.dispatchEvent(event);
  return event;
}
function press(key: string, init: KeyboardEventInit = {}): KeyboardEvent {
  const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...init });
  (document.activeElement ?? document.body).dispatchEvent(event);
  return event;
}

async function load() {
  vi.resetModules();
  return await import('../src/focus.ts');
}

beforeEach(() => {
  document.body.innerHTML = '';
  history.replaceState(null, '', '/');
});
afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('focus enhancement', () => {
  it('activates, marks the first thumbnail as current and adds a live region', async () => {
    const c = gallery(5);
    const mod = await load();
    expect(window.swgFocus).toEqual({ init: mod.init, destroy: mod.destroy });
    expect(c.classList.contains('swg-focus-active')).toBe(true);
    expect(currentIndex(c)).toBe(0);
    expect(thumbs(c)[0]!.getAttribute('aria-current')).toBe('true');
    expect(thumbs(c)[1]!.hasAttribute('aria-current')).toBe(false);
    const live = c.querySelector('.swg-sr')!;
    expect(live.getAttribute('aria-live')).toBe('polite');
    expect(live.textContent).toBe('');
  });

  it('switches instantly on click without default action, hash change or lightbox clicks', async () => {
    const c = gallery(5);
    await load();
    const event = click(thumbs(c)[2]!.querySelector('img')!);
    expect(event.defaultPrevented).toBe(true);
    expect(currentIndex(c)).toBe(2);
    expect(thumbs(c)[2]!.getAttribute('aria-current')).toBe('true');
    expect(thumbs(c)[0]!.hasAttribute('aria-current')).toBe(false);
    expect(location.hash).toBe('');
    expect(c.querySelector('.swg-sr')!.textContent).toBe('3 von 5');
    expect(c.classList.contains('swg-focus-moved')).toBe(true);
  });

  it('leaves modified clicks, other buttons and clicks elsewhere to the browser', async () => {
    const c = gallery(5);
    await load();
    expect(click(thumbs(c)[1]!, { ctrlKey: true }).defaultPrevented).toBe(false);
    expect(click(thumbs(c)[1]!, { button: 1 }).defaultPrevented).toBe(false);
    expect(click(c.querySelector('.swg-thumbs')!).defaultPrevented).toBe(false);
    expect(currentIndex(c)).toBe(0);
  });

  it('moves between thumbnails with the arrow keys, Home and End, and focuses without scrolling', async () => {
    const c = gallery(5);
    await load();
    const focusSpy = vi.spyOn(HTMLElement.prototype, 'focus');
    thumbs(c)[0]!.focus();
    expect(press('ArrowRight').defaultPrevented).toBe(true);
    expect(currentIndex(c)).toBe(1);
    expect(document.activeElement).toBe(thumbs(c)[1]);
    expect(focusSpy).toHaveBeenLastCalledWith({ preventScroll: true });
    press('ArrowDown');
    expect(currentIndex(c)).toBe(2);
    press('ArrowLeft');
    press('ArrowUp');
    expect(currentIndex(c)).toBe(0);
    press('ArrowLeft');
    expect(currentIndex(c)).toBe(0);
    press('End');
    expect(currentIndex(c)).toBe(4);
    press('ArrowRight');
    expect(currentIndex(c)).toBe(4);
    press('Home');
    expect(currentIndex(c)).toBe(0);
    expect(press('x').defaultPrevented).toBe(false);
    expect(press('ArrowRight', { ctrlKey: true }).defaultPrevented).toBe(false);
  });

  it('mirrors the horizontal arrows in RTL', async () => {
    const c = gallery(5);
    c.style.direction = 'rtl';
    await load();
    thumbs(c)[0]!.focus();
    press('ArrowLeft');
    expect(currentIndex(c)).toBe(1);
    press('ArrowRight');
    expect(currentIndex(c)).toBe(0);
  });

  it('ignores keys while the focus is outside the thumbnails', async () => {
    const c = gallery(5);
    await load();
    (c.querySelector('a.swg-link') as HTMLElement).focus();
    expect(press('ArrowRight').defaultPrevented).toBe(false);
    expect(currentIndex(c)).toBe(0);
  });

  it('starts at the panel named by the URL hash and follows later hash changes of its own anchors', async () => {
    history.replaceState(null, '', '#g-s4');
    const c = gallery(5);
    await load();
    expect(currentIndex(c)).toBe(3);
    location.hash = '#g-s2';
    window.dispatchEvent(new HashChangeEvent('hashchange'));
    expect(currentIndex(c)).toBe(1);
    location.hash = '#elsewhere';
    window.dispatchEvent(new HashChangeEvent('hashchange'));
    expect(currentIndex(c)).toBe(1);
  });

  it('loads the neighbours of the current image eagerly and warms up hovered thumbnails', async () => {
    const c = gallery(6);
    await load();
    const img = (i: number): HTMLImageElement => panels(c)[i]!.querySelector('img')!;
    expect(img(1).getAttribute('loading')).toBe('eager');
    expect(img(3).getAttribute('loading')).toBe('lazy');
    click(thumbs(c)[3]!);
    expect(img(2).getAttribute('loading')).toBe('eager');
    expect(img(4).getAttribute('loading')).toBe('eager');
    expect(img(5).getAttribute('loading')).toBe('lazy');
    thumbs(c)[5]!.dispatchEvent(new Event('pointerover', { bubbles: true }));
    expect(img(5).getAttribute('loading')).toBe('eager');
    thumbs(c)[0]!.dispatchEvent(new Event('focusin', { bubbles: true }));
    expect(img(0).getAttribute('loading')).toBe('eager');
  });

  it('scrolls only the thumbnail list to reveal the current thumbnail', async () => {
    const c = gallery(6);
    const list = c.querySelector<HTMLElement>('.swg-thumbs')!;
    const scrollBy = vi.fn();
    list.scrollBy = scrollBy as typeof list.scrollBy;
    const pageScroll = vi.fn();
    window.scrollTo = pageScroll as typeof window.scrollTo;
    vi.spyOn(list, 'getBoundingClientRect').mockReturnValue({
      left: 0,
      right: 120,
      top: 0,
      bottom: 80,
    } as DOMRect);
    thumbs(c).forEach((thumb, i) =>
      vi.spyOn(thumb, 'getBoundingClientRect').mockReturnValue({
        left: i * 90 - 60,
        right: i * 90 + 20,
        top: i === 5 ? 100 : 0,
        bottom: i === 5 ? 180 : 80,
      } as DOMRect),
    );
    await load();
    scrollBy.mockClear();
    click(thumbs(c)[1]!); // fully inside: nothing to do
    expect(scrollBy).not.toHaveBeenCalled();
    click(thumbs(c)[0]!); // starts before the list: scroll back by 60
    expect(scrollBy).toHaveBeenLastCalledWith({ left: -60, top: 0 });
    click(thumbs(c)[2]!); // ends after the list: scroll forward
    expect(scrollBy).toHaveBeenLastCalledWith({ left: 80, top: 0 });
    click(thumbs(c)[5]!);
    expect(scrollBy).toHaveBeenLastCalledWith({ left: 350, top: 100 });
    expect(pageScroll).not.toHaveBeenCalled();
  });

  it('is idempotent, keeps several galleries independent and never touches the DOM order', async () => {
    const a = gallery(4, 'a');
    const b = gallery(3, 'b');
    const before = [links(a), links(b)];
    const mod = await load();
    mod.init();
    mod.init(a);
    expect(a.querySelectorAll('.swg-sr')).toHaveLength(1);
    click(thumbs(a)[3]!);
    expect(currentIndex(a)).toBe(3);
    expect(currentIndex(b)).toBe(0);
    mod.init(a);
    expect(currentIndex(a)).toBe(3);
    expect([links(a), links(b)]).toEqual(before);
  });

  it('cleans up on destroy and returns to the CSS-only behaviour', async () => {
    const c = gallery(4);
    const mod = await load();
    click(thumbs(c)[2]!);
    mod.destroy(c);
    expect(c.classList.contains('swg-focus-active')).toBe(false);
    expect(c.classList.contains('swg-focus-moved')).toBe(false);
    expect(c.querySelector('.swg-current')).toBeNull();
    expect(c.querySelector('[aria-current]')).toBeNull();
    expect(c.querySelector('.swg-sr')).toBeNull();
    expect(click(thumbs(c)[1]!).defaultPrevented).toBe(false);
    mod.destroy(c);
    mod.init(c);
    expect(currentIndex(c)).toBe(0);
  });

  it('skips galleries without a thumbnail strip or with mismatching panels', async () => {
    const single = gallery(1);
    single.querySelector('.swg-thumbs')?.remove();
    const odd = gallery(3);
    odd.querySelector('.swg-thumb-item')?.remove();
    await load();
    expect(single.classList.contains('swg-focus-active')).toBe(false);
    expect(odd.classList.contains('swg-focus-active')).toBe(false);
  });

  it('initialises on DOMContentLoaded while the document is loading', async () => {
    const c = gallery(3);
    Object.defineProperty(document, 'readyState', { configurable: true, value: 'loading' });
    await load();
    expect(c.classList.contains('swg-focus-active')).toBe(false);
    document.dispatchEvent(new Event('DOMContentLoaded'));
    expect(c.classList.contains('swg-focus-active')).toBe(true);
    Object.defineProperty(document, 'readyState', { configurable: true, value: 'complete' });
  });
});
