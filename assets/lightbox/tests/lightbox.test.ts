import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  LABELS,
  click,
  counterText,
  currentImage,
  dialog,
  galleryHtml,
  key,
  links,
  mount,
} from './helpers.ts';

afterEach(() => {
  // Close any dialog left open so tests stay independent.
  key('Escape');
  document.body.innerHTML = '';
  document.body.removeAttribute('style');
  document.documentElement.removeAttribute('dir');
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

function open(index = 0): HTMLAnchorElement {
  const link = links()[index];
  if (!link) throw new Error('missing link');
  link.focus();
  click(link);
  return link;
}

describe('open and close', () => {
  beforeEach(() => mount(galleryHtml({ captions: { 1: 'First caption' } })));

  it('opens an accessible dialog and prevents the default navigation', () => {
    const link = links()[0]!;
    const e = click(link);
    expect(e.defaultPrevented).toBe(true);
    const d = dialog()!;
    expect(d.getAttribute('role')).toBe('dialog');
    expect(d.getAttribute('aria-modal')).toBe('true');
    expect(d.getAttribute('aria-label')).toBe('Image viewer');
    expect(d.dataset.swgInstance).toBe('swg-1-1');
    expect(currentImage().alt).toBe('Alt 1');
    expect(currentImage().getAttribute('src')).toBe('https://example.test/swg-1-1/full-1.jpg');
    expect(currentImage().getAttribute('srcset')).toContain('800w');
    expect(currentImage().getAttribute('sizes')).toBe('100vw');
    expect(currentImage().getAttribute('width')).toBe('2000');
    expect(currentImage().getAttribute('height')).toBe('1333');
  });

  it('moves focus into the dialog and returns it to the opening link on close', () => {
    const link = open(1);
    expect(dialog()!.contains(document.activeElement)).toBe(true);
    expect(document.activeElement?.getAttribute('aria-label')).toBe('Close');
    key('Escape');
    expect(dialog()).toBeNull();
    expect(document.activeElement).toBe(link);
  });

  it('closes via the close button and backdrop, but not when clicking the image', () => {
    open();
    click(currentImage());
    expect(dialog()).not.toBeNull();
    click(document.querySelector('.swg-lightbox__close')!);
    expect(dialog()).toBeNull();
    open();
    click(dialog()!);
    expect(dialog()).toBeNull();
    open();
    click(document.querySelector('.swg-lightbox__stage')!);
    expect(dialog()).toBeNull();
  });

  it('falls back to the first link if the opener was removed', () => {
    open(1);
    links()[1]!.remove();
    key('Escape');
    expect(document.activeElement).toBe(links()[0]);
  });

  it('does not open a second dialog while one is open', () => {
    open();
    const e = click(links()[1]!);
    expect(e.defaultPrevented).toBe(false);
    expect(document.querySelectorAll('.swg-lightbox')).toHaveLength(1);
  });
});

describe('modifier and non-primary clicks keep the browser default', () => {
  beforeEach(() => mount(galleryHtml()));

  it.each([
    ['ctrl', { ctrlKey: true }],
    ['meta', { metaKey: true }],
    ['shift', { shiftKey: true }],
    ['alt', { altKey: true }],
    ['middle button', { button: 1 }],
  ])('%s click', (_name, init) => {
    const e = click(links()[0]!, init);
    expect(e.defaultPrevented).toBe(false);
    expect(dialog()).toBeNull();
  });

  it('ignores clicks outside links and already prevented clicks', () => {
    click(document.querySelector('.swg-items')!);
    const link = links()[0]!;
    link.addEventListener('click', (e) => e.preventDefault(), { once: true });
    click(link);
    expect(dialog()).toBeNull();
  });

  it('ignores clicks whose target is not an element', () => {
    const e = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
    document.querySelector('[data-swg-gallery]')!.dispatchEvent(e);
    expect(dialog()).toBeNull();
    const t = document.createTextNode('x');
    document.querySelector('.swg-items')!.append(t);
    t.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
    expect(dialog()).toBeNull();
  });

  it('skips links with empty or unsafe hrefs and lets the browser follow them', () => {
    mount(
      galleryHtml()
        .replace('https://example.test/swg-1-1/full-2.jpg', '')
        .replace('https://example.test/swg-1-1/full-3.jpg', 'javascript:alert(1)'),
    );
    const seen = [click(links()[1]!).defaultPrevented, click(links()[2]!).defaultPrevented];
    expect(seen).toEqual([false, false]);
    expect(dialog()).toBeNull();
    click(links()[0]!);
    // Only one usable item remains: no navigation, no counter.
    expect(counterText()).toBe('');
    expect(document.querySelector('.swg-lightbox__nav')).toBeNull();
  });
});

describe('keyboard navigation', () => {
  beforeEach(() => mount(galleryHtml({ count: 4 })));

  it('navigates with arrows, Home and End and updates the counter', () => {
    open();
    expect(counterText()).toBe('1 of 4');
    key('ArrowRight');
    expect(counterText()).toBe('2 of 4');
    expect(currentImage().alt).toBe('Alt 2');
    key('ArrowLeft');
    expect(counterText()).toBe('1 of 4');
    key('ArrowLeft'); // no wrap-around
    expect(counterText()).toBe('1 of 4');
    key('End');
    expect(counterText()).toBe('4 of 4');
    key('ArrowRight');
    expect(counterText()).toBe('4 of 4');
    key('Home');
    expect(counterText()).toBe('1 of 4');
  });

  it('exposes the counter as a polite live region', () => {
    open();
    const counter = document.querySelector('.swg-lightbox__counter')!;
    expect(counter.getAttribute('aria-live')).toBe('polite');
    expect(counter.getAttribute('aria-atomic')).toBe('true');
  });

  it('ignores arrows with modifier keys (browser history shortcuts)', () => {
    open();
    const e = key('ArrowRight', { altKey: true });
    expect(e.defaultPrevented).toBe(false);
    expect(counterText()).toBe('1 of 4');
  });

  it('mirrors the arrow keys in an RTL document', () => {
    document.documentElement.dir = 'rtl';
    open();
    key('ArrowLeft');
    expect(counterText()).toBe('2 of 4');
    key('ArrowRight');
    expect(counterText()).toBe('1 of 4');
    expect(dialog()!.dir).toBe('rtl');
  });

  it('mirrors the arrow keys for an RTL gallery container', () => {
    mount(galleryHtml({ count: 3, attrs: 'dir="rtl"' }));
    open();
    key('ArrowLeft');
    expect(counterText()).toBe('2 of 3');
  });

  it('uses the CSS direction when no dir attribute exists', () => {
    const real = window.getComputedStyle.bind(window);
    vi.spyOn(window, 'getComputedStyle').mockImplementation((el, pseudo) => {
      const style = real(el, pseudo);
      return new Proxy(style, {
        get: (t, p) => (p === 'direction' ? 'rtl' : (Reflect.get(t, p) as unknown)),
      });
    });
    open();
    expect(dialog()!.dir).toBe('rtl');
  });

  it('ignores other keys', () => {
    open();
    expect(key('a').defaultPrevented).toBe(false);
  });
});

describe('buttons and focus trap', () => {
  beforeEach(() => mount(galleryHtml({ count: 3 })));

  it('navigates with the real buttons and disables them at the ends', () => {
    open();
    const prev = document.querySelector<HTMLButtonElement>('.swg-lightbox__prev')!;
    const next = document.querySelector<HTMLButtonElement>('.swg-lightbox__next')!;
    expect(prev.tagName).toBe('BUTTON');
    expect(prev.getAttribute('aria-label')).toBe('Previous image');
    expect(next.getAttribute('aria-label')).toBe('Next image');
    expect(prev.disabled).toBe(true);
    click(next);
    click(next);
    expect(counterText()).toBe('3 of 3');
    expect(next.disabled).toBe(true);
    click(prev);
    expect(counterText()).toBe('2 of 3');
  });

  it('hands focus to the close button when the focused nav button becomes disabled', () => {
    open();
    const next = document.querySelector<HTMLButtonElement>('.swg-lightbox__next')!;
    next.focus();
    click(next);
    next.focus();
    click(next);
    expect(next.disabled).toBe(true);
    expect(document.activeElement?.getAttribute('aria-label')).toBe('Close');
  });

  it('traps Tab and Shift+Tab over the enabled controls', () => {
    open();
    const close = document.querySelector<HTMLElement>('.swg-lightbox__close')!;
    const next = document.querySelector<HTMLElement>('.swg-lightbox__next')!;
    // Enabled controls in DOM order: next, close (prev is disabled on the first image).
    close.focus();
    let e = key('Tab');
    expect(e.defaultPrevented).toBe(true);
    expect(document.activeElement).toBe(next);
    e = key('Tab', { shiftKey: true });
    expect(e.defaultPrevented).toBe(true);
    expect(document.activeElement).toBe(close);
    key('ArrowRight');
    const prev = document.querySelector<HTMLElement>('.swg-lightbox__prev')!;
    next.focus();
    e = key('Tab', { shiftKey: true }); // middle of the cycle: browser default
    expect(e.defaultPrevented).toBe(false);
    prev.focus();
    e = key('Tab', { shiftKey: true });
    expect(e.defaultPrevented).toBe(true);
    expect(document.activeElement).toBe(close);
  });

  it('pulls focus back into the dialog if it escaped', () => {
    open();
    const outside = document.createElement('button');
    document.body.append(outside);
    outside.focus();
    expect(key('Tab').defaultPrevented).toBe(true);
    expect(dialog()!.contains(document.activeElement)).toBe(true);
  });

  it('handles a dialog without enabled controls', () => {
    open();
    const d = dialog()!;
    d.querySelectorAll('button').forEach((b) => (b.disabled = true));
    expect(key('Tab').defaultPrevented).toBe(true);
    expect(document.activeElement).toBe(d);
  });

  it('wraps Shift+Tab from the dialog container to the last control', () => {
    open();
    dialog()!.focus();
    key('Tab', { shiftKey: true });
    expect(document.activeElement?.getAttribute('aria-label')).toBe('Close');
  });

  it('has no navigation or counter for a single image', () => {
    mount(galleryHtml({ count: 1 }));
    open();
    expect(document.querySelector('.swg-lightbox__nav')).toBeNull();
    expect(document.querySelector('.swg-lightbox__counter')).toBeNull();
    key('ArrowRight');
    key('End');
    expect(currentImage().alt).toBe('Alt 1');
  });
});

describe('background, scroll lock and cleanup', () => {
  it('makes siblings inert and restores only what it changed', () => {
    mount(`<main id="a">x</main><aside id="b" inert>y</aside>${galleryHtml()}`);
    open();
    expect(document.getElementById('a')!.hasAttribute('inert')).toBe(true);
    expect(dialog()!.hasAttribute('inert')).toBe(false);
    key('Escape');
    expect(document.getElementById('a')!.hasAttribute('inert')).toBe(false);
    expect(document.getElementById('b')!.hasAttribute('inert')).toBe(true);
  });

  it('locks body scroll without layout shift and restores prior inline styles', () => {
    mount(galleryHtml());
    document.body.style.overflow = 'auto';
    document.body.style.paddingRight = '4px';
    vi.stubGlobal('innerWidth', 1015);
    vi.spyOn(document.documentElement, 'clientWidth', 'get').mockReturnValue(1000);
    open();
    expect(document.body.style.overflow).toBe('hidden');
    expect(document.body.style.paddingRight).toBe('19px');
    key('Escape');
    expect(document.body.style.overflow).toBe('auto');
    expect(document.body.style.paddingRight).toBe('4px');
  });

  it('leaves padding alone when there is no scrollbar', () => {
    mount(galleryHtml());
    vi.stubGlobal('innerWidth', 1000);
    vi.spyOn(document.documentElement, 'clientWidth', 'get').mockReturnValue(1000);
    open();
    expect(document.body.style.paddingRight).toBe('');
    expect(document.body.style.overflow).toBe('hidden');
    key('Escape');
    expect(document.body.style.overflow).toBe('');
  });

  it('removes its document listeners on close', () => {
    mount(galleryHtml());
    open();
    key('Escape');
    expect(key('ArrowRight').defaultPrevented).toBe(false);
  });
});

describe('captions, labels and robustness', () => {
  it('flattens caption markup from the page to text', () => {
    mount(galleryHtml({ captions: { 1: 'plain <em>em</em>' } }));
    open();
    const cap = document.querySelector('.swg-lightbox__caption')!;
    expect(cap.textContent).toBe('plain em');
    expect(cap.children).toHaveLength(0);
    expect(cap.hasAttribute('hidden')).toBe(false);
  });

  it('never parses caption text as markup', () => {
    const evil = '<img src=x onerror="window.__pwned=1"><b>bold</b>';
    mount(galleryHtml());
    links()[0]!
      .closest('.swg-item')!
      .append(
        Object.assign(document.createElement('p'), { className: 'swg-caption', textContent: evil }),
      );
    open();
    const cap = document.querySelector('.swg-lightbox__caption')!;
    expect(cap.textContent).toBe(evil);
    expect(cap.querySelector('img')).toBeNull();
    expect(cap.children).toHaveLength(0);
    expect((window as unknown as { __pwned?: number }).__pwned).toBeUndefined();
  });

  it('hides the caption element when an item has none', () => {
    mount(galleryHtml({ captions: { 2: 'Second' } }));
    open();
    const cap = document.querySelector<HTMLElement>('.swg-lightbox__caption')!;
    expect(cap.hidden).toBe(true);
    key('ArrowRight');
    expect(cap.hidden).toBe(false);
    expect(cap.textContent).toBe('Second');
  });

  it('uses custom labels and a custom counter template', () => {
    const labels = JSON.stringify({
      ...LABELS,
      dialog: 'Bildbetrachter',
      counter: '{current}/{total}',
    });
    mount(galleryHtml({ labels }));
    open();
    expect(dialog()!.getAttribute('aria-label')).toBe('Bildbetrachter');
    expect(counterText()).toBe('1/3');
  });

  it.each([['not json'], ['[1,2]'], ['null'], ['"str"'], ['{"close":5,"dialog":"  "}']])(
    'falls back to English defaults for invalid labels %s',
    (raw) => {
      mount(galleryHtml({ labels: raw }));
      expect(() => open()).not.toThrow();
      expect(dialog()!.getAttribute('aria-label')).toBe('Image viewer');
      expect(document.querySelector('.swg-lightbox__close')!.getAttribute('aria-label')).toBe(
        'Close',
      );
    },
  );

  it('works without a labels attribute', () => {
    mount(galleryHtml().replace(/data-swg-labels='[^']*'/, ''));
    open();
    expect(counterText()).toBe('1 of 3');
  });

  it('shows loading, loaded and error states honestly', () => {
    mount(galleryHtml());
    open();
    const figure = document.querySelector('.swg-lightbox__figure')!;
    const status = document.querySelector('.swg-lightbox__status')!;
    expect(figure.classList.contains('swg-lightbox__figure--loading')).toBe(true);
    expect(figure.getAttribute('aria-busy')).toBe('true');
    currentImage().dispatchEvent(new Event('load'));
    expect(figure.classList.contains('swg-lightbox__figure--loading')).toBe(false);
    expect(figure.getAttribute('aria-busy')).toBe('false');
    currentImage().dispatchEvent(new Event('error'));
    expect(figure.classList.contains('swg-lightbox__figure--error')).toBe(true);
    expect(status.textContent).toBe('The image could not be loaded.');
    expect(status.getAttribute('role')).toBe('status');
    expect(currentImage().hidden).toBe(true);
    key('ArrowRight');
    expect(status.textContent).toBe('');
    expect(currentImage().hidden).toBe(false);
  });

  it('uses the custom error label', () => {
    mount(galleryHtml({ labels: JSON.stringify({ ...LABELS, error: 'Fehler' }) }));
    open();
    currentImage().dispatchEvent(new Event('error'));
    expect(document.querySelector('.swg-lightbox__status')!.textContent).toBe('Fehler');
  });

  it('preloads only the neighbours with Image objects', () => {
    const created: HTMLImageElement[] = [];
    const Real = window.Image;
    vi.stubGlobal(
      'Image',
      class extends Real {
        constructor() {
          super();
          created.push(this);
        }
      },
    );
    mount(galleryHtml({ count: 5 }));
    open(2);
    expect(created.map((i) => i.getAttribute('src'))).toEqual([
      'https://example.test/swg-1-1/full-2.jpg',
      'https://example.test/swg-1-1/full-4.jpg',
    ]);
    expect(created[0]!.getAttribute('srcset')).toContain('2000w');
    created.length = 0;
    key('Home');
    expect(created.map((i) => i.getAttribute('src'))).toEqual([
      'https://example.test/swg-1-1/full-2.jpg',
    ]);
  });

  it('omits srcset, sizes and dimensions when the data attributes are absent or invalid', () => {
    mount(`<div data-swg-gallery><ul><li class="swg-item"><a class="swg-link" href="https://e.test/a.jpg"
      data-swg-full-width="abc" data-swg-full-height="0" data-swg-full-srcset=" " ><img src="t.jpg"></a></li></ul></div>`);
    open();
    const img = currentImage();
    for (const a of ['srcset', 'sizes', 'width', 'height']) expect(img.hasAttribute(a)).toBe(false);
    expect(img.alt).toBe('');
  });

  it('removes stale attributes when moving between items', () => {
    mount(`<div data-swg-gallery><ul>
      <li class="swg-item"><a class="swg-link" href="https://e.test/a.jpg" data-swg-full-width="10" data-swg-full-height="5" data-swg-full-srcset="a 1w" data-swg-full-sizes="50vw"><img alt="a"></a></li>
      <li class="swg-item"><a class="swg-link" href="https://e.test/b.jpg"><img alt="b"></a></li></ul></div>`);
    open();
    expect(currentImage().getAttribute('sizes')).toBe('50vw');
    key('ArrowRight');
    const img = currentImage();
    for (const a of ['srcset', 'sizes', 'width', 'height']) expect(img.hasAttribute(a)).toBe(false);
  });

  it('adds the reduced-motion class when the user prefers reduced motion', () => {
    vi.stubGlobal('matchMedia', (q: string) => ({ matches: q.includes('reduce') }));
    mount(galleryHtml());
    open();
    expect(dialog()!.classList.contains('swg-lightbox--reduced')).toBe(true);
  });

  it('does not add the class otherwise or without matchMedia', () => {
    vi.stubGlobal('matchMedia', () => ({ matches: false }));
    mount(galleryHtml());
    open();
    expect(dialog()!.classList.contains('swg-lightbox--reduced')).toBe(false);
    key('Escape');
    vi.stubGlobal('matchMedia', undefined);
    open();
    expect(dialog()!.classList.contains('swg-lightbox--reduced')).toBe(false);
  });

  it('close is idempotent', () => {
    mount(galleryHtml());
    open();
    key('Escape');
    expect(() => key('Escape')).not.toThrow();
  });
});

describe('init', () => {
  it('keeps multiple galleries independent', () => {
    mount(
      galleryHtml({ instance: 'swg-1-1', count: 2 }) +
        galleryHtml({ instance: 'swg-2-1', count: 4 }),
    );
    const second = document.querySelectorAll('[data-swg-gallery]')[1]!;
    const link = links(second)[2]!;
    link.focus();
    click(link);
    expect(counterText()).toBe('3 of 4');
    expect(dialog()!.dataset.swgInstance).toBe('swg-2-1');
    key('End');
    expect(currentImage().getAttribute('src')).toContain('swg-2-1/full-4');
    key('Escape');
    expect(document.activeElement).toBe(link);
    click(links()[0]!);
    expect(counterText()).toBe('1 of 2');
    expect(currentImage().getAttribute('src')).toContain('swg-1-1/full-1');
  });

  it('is idempotent: repeated init opens exactly one dialog', async () => {
    mount(galleryHtml());
    const { init } = await import('../src/init.ts');
    init();
    init(document.body);
    open();
    expect(document.querySelectorAll('.swg-lightbox')).toHaveLength(1);
    key('Escape');
    expect(dialog()).toBeNull();
  });

  it('enhances a container passed as root and galleries added later', async () => {
    const { init } = await import('../src/init.ts');
    document.body.innerHTML = '<div id="late"></div>';
    const late = document.getElementById('late')!;
    late.innerHTML = galleryHtml({ instance: 'late' });
    init(late.firstElementChild as HTMLElement);
    open();
    expect(dialog()!.dataset.swgInstance).toBe('late');
  });

  it('reads items at open time, so appended items are included', () => {
    mount(galleryHtml({ count: 2 }));
    const list = document.querySelector('.swg-items')!;
    list.append(links()[0]!.closest('li')!.cloneNode(true));
    open();
    expect(counterText()).toBe('1 of 3');
  });
});
