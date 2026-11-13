import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

type Callback = () => void;

/** Controllable ResizeObserver: tests trigger the callback by hand. */
class FakeResizeObserver {
  static instances: FakeResizeObserver[] = [];
  observed = new Set<Element>();
  disconnected = false;
  constructor(private readonly callback: Callback) {
    FakeResizeObserver.instances.push(this);
  }
  observe(element: Element): void {
    this.observed.add(element);
  }
  disconnect(): void {
    this.disconnected = true;
    this.observed.clear();
  }
  fire(): void {
    this.callback();
  }
}

const heights = new Map<Element, number>();

function gallery(count: number, extraClass = 'swg-layout-masonry'): HTMLElement {
  const container = document.createElement('div');
  container.className = `swg-gallery ${extraClass}`;
  container.setAttribute('data-swg-gallery', '');
  const list = document.createElement('ul');
  list.className = 'swg-items';
  list.style.columnGap = '10px';
  for (let i = 0; i < count; i++) {
    const item = document.createElement('li');
    item.className = 'swg-item';
    item.innerHTML = `<a class="swg-link" href="#${i}"><img alt="${i}" width="100" height="100" /></a>`;
    heights.set(item, 100 + i * 10.4);
    list.append(item);
  }
  container.append(list);
  document.body.append(container);
  return container;
}

const items = (container: HTMLElement): HTMLElement[] =>
  Array.from(container.querySelectorAll<HTMLElement>('.swg-item'));
const order = (container: HTMLElement): string[] =>
  Array.from(container.querySelectorAll('a.swg-link')).map((a) => a.getAttribute('href') ?? '');

async function load() {
  vi.resetModules();
  return await import('../src/masonry.ts');
}

beforeEach(() => {
  FakeResizeObserver.instances = [];
  heights.clear();
  document.body.innerHTML = '';
  vi.stubGlobal('ResizeObserver', FakeResizeObserver);
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (
    this: HTMLElement,
  ) {
    return { height: heights.get(this) ?? 0 } as DOMRect;
  });
});

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('masonry', () => {
  it('spans every item by its height plus the column gap and marks the gallery active', async () => {
    const container = gallery(3);
    const { init } = await load();
    init();

    expect(items(container).map((i) => i.style.gridRowEnd)).toEqual([
      'span 110',
      'span 121',
      'span 131',
    ]);
    expect(container.classList.contains('swg-masonry-active')).toBe(true);
  });

  it('never changes the DOM order', async () => {
    const container = gallery(6);
    const before = order(container);
    const nodes = items(container);
    const { init } = await load();
    init();
    heights.set(nodes[0] as HTMLElement, 400);
    FakeResizeObserver.instances[0]?.fire();

    expect(order(container)).toEqual(before);
    expect(items(container)).toEqual(nodes);
  });

  it('is idempotent and picks up new items on a repeated init', async () => {
    const container = gallery(2);
    const { init } = await load();
    init();
    init(container);
    expect(FakeResizeObserver.instances).toHaveLength(1);

    const extra = document.createElement('li');
    extra.className = 'swg-item';
    heights.set(extra, 50);
    container.querySelector('.swg-items')?.append(extra);
    init();

    expect(extra.style.gridRowEnd).toBe('span 60');
    expect(FakeResizeObserver.instances[0]?.observed.has(extra)).toBe(true);
    expect(FakeResizeObserver.instances).toHaveLength(1);
  });

  it('re-packs when the observer reports a size change', async () => {
    const container = gallery(2);
    const { init } = await load();
    init();
    const first = items(container)[0] as HTMLElement;
    heights.set(first, 300);
    FakeResizeObserver.instances[0]?.fire();

    expect(first.style.gridRowEnd).toBe('span 310');
  });

  it('works for several galleries independently and ignores other layouts', async () => {
    const a = gallery(2);
    const b = gallery(3);
    const grid = gallery(2, 'swg-layout-grid');
    const { init } = await load();
    init();

    expect(FakeResizeObserver.instances).toHaveLength(2);
    expect(a.classList.contains('swg-masonry-active')).toBe(true);
    expect(b.classList.contains('swg-masonry-active')).toBe(true);
    expect(grid.classList.contains('swg-masonry-active')).toBe(false);
    expect(items(grid)[0]?.style.gridRowEnd).toBe('');
  });

  it('enhances a root that is itself a gallery and scopes init to the given root', async () => {
    const { init } = await load(); // the empty document is initialized on load
    const a = gallery(2);
    const b = gallery(2);
    init(b);

    expect(a.classList.contains('swg-masonry-active')).toBe(false);
    expect(b.classList.contains('swg-masonry-active')).toBe(true);
  });

  it('destroy disconnects the observer and restores the plain grid', async () => {
    const container = gallery(3);
    const { init, destroy } = await load();
    init();
    destroy(container);

    expect(FakeResizeObserver.instances[0]?.disconnected).toBe(true);
    expect(container.classList.contains('swg-masonry-active')).toBe(false);
    expect(items(container).every((i) => i.style.gridRowEnd === '')).toBe(true);
    destroy(container); // a second call is harmless
    init();
    expect(FakeResizeObserver.instances).toHaveLength(2);
  });

  it('cleans up by itself when the gallery left the document', async () => {
    const container = gallery(2);
    const { init } = await load();
    init();
    container.remove();
    FakeResizeObserver.instances[0]?.fire();

    expect(FakeResizeObserver.instances[0]?.disconnected).toBe(true);
    expect(container.classList.contains('swg-masonry-active')).toBe(false);
  });

  it('copes with an empty gallery, a missing list and unusable gaps', async () => {
    const empty = gallery(0);
    const bare = document.createElement('div');
    bare.className = 'swg-gallery swg-layout-masonry';
    bare.setAttribute('data-swg-gallery', '');
    document.body.append(bare);
    const noGap = gallery(1);
    (noGap.querySelector('.swg-items') as HTMLElement).style.columnGap = '';
    const { init } = await load();
    init();

    expect(empty.classList.contains('swg-masonry-active')).toBe(true);
    expect(bare.classList.contains('swg-masonry-active')).toBe(false);
    expect(items(noGap)[0]?.style.gridRowEnd).toBe('span 100');
  });

  it('keeps a minimum span for hidden (zero height) items', async () => {
    const container = gallery(1);
    heights.set(items(container)[0] as HTMLElement, 0);
    const { init } = await load();
    init();

    expect(items(container)[0]?.style.gridRowEnd).toBe('span 10');
  });

  it('stays a plain grid without ResizeObserver', async () => {
    vi.stubGlobal('ResizeObserver', undefined);
    const container = gallery(2);
    const { init } = await load();
    init();

    expect(container.classList.contains('swg-masonry-active')).toBe(false);
    expect(items(container)[0]?.style.gridRowEnd).toBe('');
  });

  it('registers window.swgMasonry and initializes on DOMContentLoaded while the DOM is loading', async () => {
    const container = gallery(2);
    vi.spyOn(document, 'readyState', 'get').mockReturnValue('loading');
    await load();

    expect(window.swgMasonry).toBeDefined();
    expect(container.classList.contains('swg-masonry-active')).toBe(false);
    document.dispatchEvent(new Event('DOMContentLoaded'));
    expect(container.classList.contains('swg-masonry-active')).toBe(true);
  });

  it('initializes immediately when the DOM is ready', async () => {
    const container = gallery(2);
    await load();

    expect(container.classList.contains('swg-masonry-active')).toBe(true);
  });
});
