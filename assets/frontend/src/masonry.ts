/**
 * Masonry progressive enhancement (decision D10). Without this script `.swg-layout-masonry` is a plain responsive grid
 * in editor order. Here the list becomes a grid of 1px rows and every item spans as many rows as its height plus the
 * gap, so the grid's own auto placement fills the shortest column first. DOM nodes are never moved: tab, screen
 * reader and lightbox order stay the editor order. Item heights are known before images load (width/height
 * attributes give the aspect ratio), so loading images does not shift anything; a ResizeObserver re-packs when item
 * widths (resize, column count) or heights (caption wrapping, fonts) change. Spans are written, no animation runs, so
 * `prefers-reduced-motion` needs no special case; only logical CSS is involved (RTL safe).
 */

const SELECTOR = '[data-swg-gallery].swg-layout-masonry';
const ACTIVE = 'swg-masonry-active';

interface Instance {
  observer: ResizeObserver;
  pack: () => void;
}

const instances = new WeakMap<HTMLElement, Instance>();

function itemsOf(list: Element): HTMLElement[] {
  return Array.from(list.children).filter(
    (child): child is HTMLElement => child instanceof HTMLElement,
  );
}

/** Writes `grid-row-end: span N` for every item (reads first, writes after, no layout thrashing). */
function pack(list: HTMLElement): void {
  const parsed = Number.parseFloat(getComputedStyle(list).columnGap);
  const gap = Number.isFinite(parsed) ? parsed : 0;
  const items = itemsOf(list);
  const spans = items.map((item) =>
    Math.max(1, Math.ceil(item.getBoundingClientRect().height + gap)),
  );
  items.forEach((item, index) => {
    const value = `span ${spans[index]}`;
    if (item.style.gridRowEnd !== value) item.style.gridRowEnd = value;
  });
}

/** Stops observing and returns the gallery to the plain grid. */
export function destroy(container: HTMLElement): void {
  const instance = instances.get(container);
  if (!instance) return;
  instance.observer.disconnect();
  instances.delete(container);
  container.classList.remove(ACTIVE);
  const list = container.querySelector('.swg-items');
  if (list) for (const item of itemsOf(list)) item.style.removeProperty('grid-row-end');
}

function enhance(container: HTMLElement): void {
  const list = container.querySelector<HTMLElement>('.swg-items');
  if (!list) return;
  const existing = instances.get(container);
  if (existing) {
    // Idempotent: a repeated init only picks up items added since (e.g. by AJAX).
    existing.observer.disconnect();
    for (const item of itemsOf(list)) existing.observer.observe(item);
    existing.pack();
    return;
  }
  const run = (): void => {
    if (!container.isConnected) {
      destroy(container);
      return;
    }
    pack(list);
  };
  const observer = new ResizeObserver(run);
  instances.set(container, { observer, pack: run });
  for (const item of itemsOf(list)) observer.observe(item);
  pack(list);
  container.classList.add(ACTIVE);
}

/** Enhances every masonry gallery in `root` (and `root` itself). Safe to call repeatedly. */
export function init(root: ParentNode = document): void {
  if (typeof ResizeObserver === 'undefined') return; // stays a plain grid
  const containers = Array.from(root.querySelectorAll<HTMLElement>(SELECTOR));
  if (root instanceof HTMLElement && root.matches(SELECTOR)) containers.push(root);
  for (const container of containers) enhance(container);
}

declare global {
  interface Window {
    swgMasonry?: { init: typeof init; destroy: typeof destroy };
  }
}

window.swgMasonry = { init, destroy };

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => init(), { once: true });
} else {
  init();
}
