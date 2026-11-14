import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const SLIDE = 200;
const GAP = 10;
const VIEW = 500;

class FakeResizeObserver {
  static instances: FakeResizeObserver[] = [];
  observed = new Set<Element>();
  disconnected = false;
  constructor(private readonly callback: () => void) {
    FakeResizeObserver.instances.push(this);
  }
  observe(element: Element): void {
    this.observed.add(element);
  }
  disconnect(): void {
    this.disconnected = true;
  }
  fire(): void {
    this.callback();
  }
}

interface Fixture {
  container: HTMLElement;
  viewport: HTMLElement;
  /** Logical scroll position in px from the start edge (the model of the browser's scrollLeft). */
  scroll: () => number;
  setScroll: (x: number) => void;
}

function gallery(count: number, options: { rtl?: boolean; center?: boolean } = {}): Fixture {
  const container = document.createElement('div');
  container.className = `swg-gallery swg-layout-carousel${options.center ? ' swg-snap-center' : ''}`;
  container.setAttribute('data-swg-gallery', '');
  container.dataset.swgLabels = JSON.stringify({
    previous: 'Zurueck',
    next: 'Weiter',
    counter: '{current} von {total}',
  });
  const items = Array.from(
    { length: count },
    (_, i) =>
      `<li class="swg-item"><a class="swg-link" href="#${i}"><img alt="${i}" width="10" height="10" /></a></li>`,
  ).join('');
  container.innerHTML = `<div class="swg-carousel__frame"><div class="swg-carousel__viewport" tabindex="0"><ul class="swg-items">${items}</ul></div></div>`;
  document.body.append(container);
  const viewport = container.querySelector<HTMLElement>('.swg-carousel__viewport')!;
  if (options.rtl) viewport.style.direction = 'rtl';
  const total = count * SLIDE + (count - 1) * GAP;
  let position = 0;
  const sign = options.rtl ? -1 : 1;
  Object.defineProperty(viewport, 'scrollLeft', {
    configurable: true,
    get: () => sign * position,
    set: (x: number) => {
      position = Math.abs(x);
    },
  });
  Object.defineProperty(viewport, 'clientWidth', { configurable: true, value: VIEW });
  Object.defineProperty(viewport, 'scrollWidth', { configurable: true, value: total });
  viewport.scrollBy = ((arg: ScrollToOptions) => {
    position = Math.max(0, Math.min(total - VIEW, position + (arg.left ?? 0) * sign));
    viewport.dispatchEvent(new Event('scroll'));
  }) as typeof viewport.scrollBy;
  const slides = Array.from(container.querySelectorAll<HTMLElement>('.swg-item'));
  vi.spyOn(viewport, 'getBoundingClientRect').mockImplementation(
    () => ({ left: 0, right: VIEW, width: VIEW }) as DOMRect,
  );
  slides.forEach((slide, i) => {
    vi.spyOn(slide, 'getBoundingClientRect').mockImplementation(() => {
      const start = i * (SLIDE + GAP) - position;
      return options.rtl
        ? ({ left: VIEW - start - SLIDE, right: VIEW - start, width: SLIDE } as DOMRect)
        : ({ left: start, right: start + SLIDE, width: SLIDE } as DOMRect);
    });
  });
  return { container, viewport, scroll: () => position, setScroll: (x) => (position = x) };
}

const button = (container: HTMLElement, which: 'prev' | 'next'): HTMLButtonElement =>
  container.querySelector<HTMLButtonElement>(`.swg-carousel__${which}`)!;
const status = (container: HTMLElement): string =>
  container.querySelector('.swg-carousel__status')?.textContent ?? '';
const order = (container: HTMLElement): string[] =>
  Array.from(container.querySelectorAll('a.swg-link')).map((a) => a.getAttribute('href') ?? '');

async function load() {
  vi.resetModules();
  return await import('../src/carousel.ts');
}

const flush = async (): Promise<void> => {
  await vi.advanceTimersByTimeAsync(300);
};

beforeEach(() => {
  vi.useFakeTimers();
  FakeResizeObserver.instances = [];
  document.body.innerHTML = '';
  vi.stubGlobal('ResizeObserver', FakeResizeObserver);
  vi.stubGlobal('requestAnimationFrame', (cb: FrameRequestCallback) =>
    window.setTimeout(() => cb(0), 0),
  );
  vi.stubGlobal('cancelAnimationFrame', (id: number) => window.clearTimeout(id));
});

afterEach(() => {
  vi.useRealTimers();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('carousel enhancement', () => {
  it('exposes the API and adds labelled buttons and a live position text', async () => {
    const { container } = gallery(6);
    const mod = await load();
    expect(window.swgCarousel).toEqual({ init: mod.init, destroy: mod.destroy });

    const prev = button(container, 'prev');
    const next = button(container, 'next');
    expect(prev.type).toBe('button');
    expect(prev.getAttribute('aria-label')).toBe('Zurueck');
    expect(next.getAttribute('aria-label')).toBe('Weiter');
    expect(container.querySelector('.swg-carousel__frame')?.contains(prev)).toBe(true);
    const text = container.querySelector('.swg-carousel__status')!;
    expect(text.getAttribute('aria-live')).toBe('polite');
    await flush();
    expect(text.textContent).toBe('1 von 6');
  });

  it('is idempotent and never duplicates buttons', async () => {
    const { container } = gallery(6);
    const mod = await load();
    mod.init();
    mod.init(container);
    mod.init(document.body);
    await flush();
    expect(container.querySelectorAll('.swg-carousel__btn')).toHaveLength(2);
    expect(container.querySelectorAll('.swg-carousel__status')).toHaveLength(1);
  });

  it('marks the ends with aria-disabled and keeps the buttons focusable', async () => {
    const { container, viewport, setScroll } = gallery(6);
    await load();
    await flush();
    expect(button(container, 'prev').getAttribute('aria-disabled')).toBe('true');
    expect(button(container, 'next').getAttribute('aria-disabled')).toBe('false');
    expect(button(container, 'prev').disabled).toBe(false);

    setScroll(100);
    viewport.dispatchEvent(new Event('scroll'));
    await flush();
    expect(button(container, 'prev').getAttribute('aria-disabled')).toBe('false');

    setScroll(6 * SLIDE + 5 * GAP - VIEW);
    viewport.dispatchEvent(new Event('scroll'));
    await flush();
    expect(button(container, 'next').getAttribute('aria-disabled')).toBe('true');
    expect(status(container)).toBe('6 von 6');
  });

  it('scrolls one slide per click and updates the position text', async () => {
    const { container, scroll } = gallery(6);
    await load();
    button(container, 'next').click();
    expect(scroll()).toBe(SLIDE + GAP);
    await flush();
    expect(status(container)).toBe('2 von 6');
    button(container, 'next').click();
    await flush();
    expect(scroll()).toBe(2 * (SLIDE + GAP));
    expect(status(container)).toBe('3 von 6');
    button(container, 'prev').click();
    expect(scroll()).toBe(SLIDE + GAP);
  });

  it('rate-limits the live region while the strip is moving', async () => {
    const { container, viewport, setScroll } = gallery(6);
    await load();
    await flush();
    const text = container.querySelector('.swg-carousel__status')!;
    const writes: string[] = [];
    new MutationObserver(() => writes.push(text.textContent ?? '')).observe(text, {
      childList: true,
    });
    for (const x of [50, 150, 230, 400]) {
      setScroll(x);
      viewport.dispatchEvent(new Event('scroll'));
      await vi.advanceTimersByTimeAsync(40);
    }
    expect(text.textContent).toBe('1 von 6');
    await flush();
    await Promise.resolve();
    expect(text.textContent).toBe('3 von 6');
  });

  it('handles ArrowRight/ArrowLeft/Home/End on the viewport and leaves modified keys alone', async () => {
    const { container, viewport, scroll } = gallery(6);
    await load();
    const press = (key: string, init: KeyboardEventInit = {}): KeyboardEvent => {
      const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...init });
      viewport.dispatchEvent(event);
      return event;
    };
    expect(press('ArrowRight').defaultPrevented).toBe(true);
    expect(scroll()).toBe(SLIDE + GAP);
    expect(press('ArrowLeft').defaultPrevented).toBe(true);
    expect(scroll()).toBe(0);
    press('End');
    expect(scroll()).toBe(6 * SLIDE + 5 * GAP - VIEW);
    press('Home');
    expect(scroll()).toBe(0);
    expect(press('ArrowRight', { ctrlKey: true }).defaultPrevented).toBe(false);
    expect(press('a').defaultPrevented).toBe(false);
    expect(container.querySelectorAll('.swg-carousel__btn')).toHaveLength(2);
  });

  it('uses logical directions in RTL', async () => {
    const { container, viewport, scroll } = gallery(6, { rtl: true });
    await load();
    await flush();
    expect(button(container, 'prev').getAttribute('aria-disabled')).toBe('true');
    // "Forward" is ArrowLeft in RTL.
    viewport.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', cancelable: true }));
    expect(scroll()).toBe(SLIDE + GAP);
    viewport.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', cancelable: true }));
    expect(scroll()).toBe(0);
    button(container, 'next').click();
    expect(scroll()).toBe(SLIDE + GAP);
    await flush();
    expect(status(container)).toBe('2 von 6');
  });

  it('aligns the centre of a slide in centre snap mode', async () => {
    const { container, scroll } = gallery(6, { center: true });
    await load();
    button(container, 'next').click();
    // Slide 2 starts at 210 and is 200 wide: its centre (310) goes to the viewport centre (250).
    expect(scroll()).toBe(60);
    await flush();
    expect(status(container)).toBe('2 von 6');
  });

  it('re-evaluates on resize and uses default labels when none are given', async () => {
    const { container } = gallery(6);
    container.removeAttribute('data-swg-labels');
    await load();
    expect(button(container, 'next').getAttribute('aria-label')).toBe('Next image');
    const observer = FakeResizeObserver.instances[0]!;
    expect(observer.observed.size).toBe(2);
    observer.fire();
    await flush();
    expect(status(container)).toBe('1 of 6');
  });

  it('does nothing without a viewport or in browsers without ResizeObserver', async () => {
    const broken = document.createElement('div');
    broken.className = 'swg-gallery swg-layout-carousel';
    broken.setAttribute('data-swg-gallery', '');
    document.body.append(broken);
    vi.stubGlobal('ResizeObserver', undefined);
    const { container } = gallery(3);
    await load();
    expect(broken.querySelector('.swg-carousel__btn')).toBeNull();
    expect(container.querySelectorAll('.swg-carousel__btn')).toHaveLength(2);
  });

  it('respects reduced motion for the scroll behaviour', async () => {
    const { container, viewport } = gallery(6);
    const spy = vi.spyOn(viewport, 'scrollBy');
    vi.stubGlobal('matchMedia', (query: string) => ({ matches: query.includes('reduce') }));
    await load();
    button(container, 'next').click();
    expect(spy).toHaveBeenCalledWith({ left: SLIDE + GAP, behavior: 'auto' });
  });

  it('never moves, adds or removes the slides and cleans up completely on destroy', async () => {
    const { container, viewport } = gallery(6);
    const before = order(container);
    const mod = await load();
    button(container, 'next').click();
    expect(order(container)).toEqual(before);
    const observer = FakeResizeObserver.instances[0]!;

    mod.destroy(container);
    expect(observer.disconnected).toBe(true);
    expect(container.querySelector('.swg-carousel__btn')).toBeNull();
    expect(container.querySelector('.swg-carousel__status')).toBeNull();
    expect(order(container)).toEqual(before);
    // No listener is left behind.
    const event = new KeyboardEvent('keydown', { key: 'End', cancelable: true });
    viewport.dispatchEvent(event);
    expect(event.defaultPrevented).toBe(false);
    mod.destroy(container); // second call is harmless
    mod.init(container);
    expect(container.querySelectorAll('.swg-carousel__btn')).toHaveLength(2);
  });

  it('keeps several galleries independent', async () => {
    const a = gallery(6);
    const b = gallery(4);
    await load();
    button(a.container, 'next').click();
    expect(a.scroll()).toBe(SLIDE + GAP);
    expect(b.scroll()).toBe(0);
    expect(button(b.container, 'prev').getAttribute('aria-disabled')).toBe('true');
  });

  it('initialises on DOMContentLoaded while the document is still loading', async () => {
    const { container } = gallery(3);
    Object.defineProperty(document, 'readyState', { configurable: true, value: 'loading' });
    await load();
    expect(container.querySelector('.swg-carousel__btn')).toBeNull();
    document.dispatchEvent(new Event('DOMContentLoaded'));
    expect(container.querySelectorAll('.swg-carousel__btn')).toHaveLength(2);
    Object.defineProperty(document, 'readyState', { configurable: true, value: 'complete' });
  });

  it('is a no-op when the button reaches an end', async () => {
    const { container, scroll } = gallery(6);
    await load();
    button(container, 'prev').click();
    expect(scroll()).toBe(0);
  });
});
