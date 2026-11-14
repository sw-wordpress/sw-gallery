/**
 * Focus (spotlight) progressive enhancement. Without this script the stage follows the in-page thumbnail links with
 * CSS `:target`. Here the thumbnails switch the stage instantly: no page scroll, no URL change (an initial or later
 * `#<panel id>` of the same gallery is still honoured). The thumbnails stay plain links; the current one carries
 * `aria-current="true"` (the simplest correct pattern for a set of links, a tablist would need panels, roving tabindex
 * and more), arrow keys move between them and a visually hidden live region announces "3 of 12". The neighbours of the
 * current image are switched to eager loading, so the next click shows an image that is already there. The stage panels
 * are never moved: the lightbox reads them in DOM order, exactly once each.
 */
import { formatCounter, parseLabels } from '../../lightbox/src/labels.ts';

const SELECTOR = '[data-swg-gallery].swg-layout-focus';

interface Instance {
  refresh: () => void;
  destroy: () => void;
}

const instances = new WeakMap<HTMLElement, Instance>();

/** Scrolls only the thumbnail list (never the page) so that `thumb` is fully visible. */
function reveal(list: HTMLElement, thumb: HTMLElement): void {
  const l = list.getBoundingClientRect();
  const t = thumb.getBoundingClientRect();
  const shift = (a: number, b: number, c: number, d: number): number =>
    a < c ? a - c : b > d ? b - d : 0;
  const left = shift(t.left, t.right, l.left, l.right);
  const top = shift(t.top, t.bottom, l.top, l.bottom);
  if (left || top) list.scrollBy({ left, top });
}

function enhance(container: HTMLElement): void {
  const existing = instances.get(container);
  if (existing) return existing.refresh(); // idempotent
  const panels = Array.from(container.querySelectorAll<HTMLElement>('.swg-panel'));
  const thumbs = Array.from(container.querySelectorAll<HTMLElement>('.swg-thumb'));
  const list = thumbs[0]?.closest<HTMLElement>('.swg-thumbs');
  if (!list || panels.length < 2 || panels.length !== thumbs.length) return;

  const labels = parseLabels(container.dataset.swgLabels);
  const status = document.createElement('p');
  status.className = 'swg-sr';
  status.setAttribute('aria-live', 'polite');
  container.append(status);

  const anchorIndex = (hash: string): number =>
    panels.findIndex((panel) => panel.querySelector('.swg-anchor')?.id === hash.slice(1));
  let current = -1;
  const select = (index: number, announce = true): void => {
    if (index < 0 || index >= panels.length || index === current) return;
    if (current >= 0) container.classList.add('swg-focus-moved');
    current = index;
    panels.forEach((panel, i) => panel.classList.toggle('swg-current', i === index));
    thumbs.forEach((thumb, i) => {
      if (i === index) thumb.setAttribute('aria-current', 'true');
      else thumb.removeAttribute('aria-current');
    });
    for (const near of [index - 1, index + 1]) {
      const image = panels[near]?.querySelector('img');
      image?.setAttribute('loading', 'eager');
    }
    reveal(list, thumbs[index]!);
    if (announce) status.textContent = formatCounter(labels.counter, index + 1, panels.length);
  };
  const move = (index: number): void => {
    const target = Math.max(0, Math.min(panels.length - 1, index));
    select(target);
    thumbs[target]!.focus({ preventScroll: true });
  };

  const onClick = (event: MouseEvent): void => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey)
      return;
    const index = thumbs.indexOf((event.target as Element).closest<HTMLElement>('.swg-thumb')!);
    if (index < 0) return;
    event.preventDefault(); // no hash change, no scrolling
    select(index);
  };
  const onKey = (event: KeyboardEvent): void => {
    if (event.altKey || event.ctrlKey || event.metaKey) return;
    const index = thumbs.indexOf(document.activeElement as HTMLElement);
    if (index < 0) return;
    const rtl = getComputedStyle(container).direction === 'rtl';
    const next = rtl ? 'ArrowLeft' : 'ArrowRight';
    const prev = rtl ? 'ArrowRight' : 'ArrowLeft';
    if (event.key === next || event.key === 'ArrowDown') move(index + 1);
    else if (event.key === prev || event.key === 'ArrowUp') move(index - 1);
    else if (event.key === 'Home') move(0);
    else if (event.key === 'End') move(thumbs.length - 1);
    else return;
    event.preventDefault();
  };
  const onHash = (): void => {
    const index = anchorIndex(location.hash);
    if (index >= 0) select(index);
  };
  // Hovering or focusing a thumbnail warms up its image before the click.
  const warm = (event: Event): void => {
    const index = thumbs.indexOf((event.target as Element).closest<HTMLElement>('.swg-thumb')!);
    const image = panels[index]?.querySelector('img');
    image?.setAttribute('loading', 'eager');
  };

  list.addEventListener('click', onClick);
  list.addEventListener('keydown', onKey);
  list.addEventListener('pointerover', warm);
  list.addEventListener('focusin', warm);
  window.addEventListener('hashchange', onHash);
  container.classList.add('swg-focus-active');
  select(Math.max(0, anchorIndex(location.hash)), false);

  instances.set(container, {
    refresh: () => select(current >= 0 ? current : 0),
    destroy: () => {
      list.removeEventListener('click', onClick);
      list.removeEventListener('keydown', onKey);
      list.removeEventListener('pointerover', warm);
      list.removeEventListener('focusin', warm);
      window.removeEventListener('hashchange', onHash);
      container.classList.remove('swg-focus-active', 'swg-focus-moved');
      panels.forEach((panel) => panel.classList.remove('swg-current'));
      thumbs.forEach((thumb) => thumb.removeAttribute('aria-current'));
      status.remove();
      instances.delete(container);
    },
  });
}

/** Returns the gallery to the pure CSS (`:target`) behaviour. */
export function destroy(container: HTMLElement): void {
  instances.get(container)?.destroy();
}

/** Enhances every focus gallery in `root` (and `root` itself). Safe to call repeatedly. */
export function init(root: ParentNode = document): void {
  const containers = Array.from(root.querySelectorAll<HTMLElement>(SELECTOR));
  if (root instanceof HTMLElement && root.matches(SELECTOR)) containers.push(root);
  for (const container of containers) enhance(container);
}

declare global {
  interface Window {
    swgFocus?: { init: typeof init; destroy: typeof destroy };
  }
}

window.swgFocus = { init, destroy };

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => init(), { once: true });
} else {
  init();
}
