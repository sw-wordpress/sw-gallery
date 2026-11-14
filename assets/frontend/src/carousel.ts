/**
 * Carousel progressive enhancement (layout `carousel`, proposal L-15). Without this script the strip is a native
 * scroll-snap scroller (swipe, wheel, arrow keys on the focusable viewport). Here we only add comfort: previous/next
 * buttons, Home/End, and a position text ("3 of 12", the translated lightbox counter). Nothing is hijacked: wheel and
 * touch drag stay native, there is no autoplay, DOM nodes are never moved, and directions are logical (RTL safe).
 * The buttons use `aria-disabled` instead of `disabled` so the keyboard focus is not lost when an end is reached.
 */
import { formatCounter, parseLabels } from '../../lightbox/src/labels.ts';

const SELECTOR = '[data-swg-gallery].swg-layout-carousel';
const STATUS_DELAY = 200;

interface Instance {
  refresh: () => void;
  destroy: () => void;
}

const instances = new WeakMap<HTMLElement, Instance>();

function button(className: string, label: string): HTMLButtonElement {
  const el = document.createElement('button');
  el.type = 'button';
  el.className = `swg-carousel__btn ${className}`;
  el.setAttribute('aria-label', label);
  return el;
}

function enhance(container: HTMLElement): void {
  const existing = instances.get(container);
  if (existing) return existing.refresh(); // idempotent
  const frame = container.querySelector<HTMLElement>('.swg-carousel__frame');
  const viewport = container.querySelector<HTMLElement>('.swg-carousel__viewport');
  const list = viewport?.querySelector('.swg-items');
  if (!frame || !viewport || !list) return;

  const labels = parseLabels(container.dataset.swgLabels);
  const center = container.classList.contains('swg-snap-center');
  const prev = button('swg-carousel__prev', labels.previous);
  const next = button('swg-carousel__next', labels.next);
  const status = document.createElement('p');
  status.className = 'swg-carousel__status';
  status.setAttribute('aria-live', 'polite');
  frame.append(prev, next);
  container.append(status);

  const slides = (): HTMLElement[] => Array.from(list.children) as HTMLElement[];
  const rtl = (): boolean => getComputedStyle(viewport).direction === 'rtl';
  /** Distance of a slide's snap point from the viewport's, along the reading direction (logical x). */
  const offset = (slide: HTMLElement): number => {
    const v = viewport.getBoundingClientRect();
    const s = slide.getBoundingClientRect();
    const start = rtl() ? v.right - s.right : s.left - v.left;
    return center ? start + s.width / 2 - v.width / 2 : start;
  };
  const edges = (): { atStart: boolean; atEnd: boolean } => {
    const x = Math.abs(viewport.scrollLeft);
    return { atStart: x < 2, atEnd: x + viewport.clientWidth >= viewport.scrollWidth - 2 };
  };
  const currentIndex = (): number => {
    const all = slides();
    const { atStart, atEnd } = edges();
    if (atStart || all.length === 0) return 0;
    if (atEnd) return all.length - 1;
    let best = 0;
    all.forEach((slide, index) => {
      if (Math.abs(offset(slide)) < Math.abs(offset(all[best]!))) best = index;
    });
    return best;
  };
  const go = (index: number): void => {
    const all = slides();
    const target = all[Math.max(0, Math.min(all.length - 1, index))];
    if (!target) return;
    const smooth = !window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    const delta = offset(target);
    viewport.scrollBy({ left: rtl() ? -delta : delta, behavior: smooth ? 'smooth' : 'auto' });
  };

  let frameId = 0;
  let timer = 0;
  const update = (): void => {
    frameId = 0;
    const { atStart, atEnd } = edges();
    prev.setAttribute('aria-disabled', String(atStart));
    next.setAttribute('aria-disabled', String(atEnd));
    window.clearTimeout(timer);
    // Rate-limited: the live region is only written once the strip has stopped moving.
    timer = window.setTimeout(() => {
      status.textContent = formatCounter(labels.counter, currentIndex() + 1, slides().length);
    }, STATUS_DELAY);
  };
  const schedule = (): void => {
    if (!frameId) frameId = window.requestAnimationFrame(update);
  };
  const step = (direction: number): void => go(currentIndex() + direction);
  const onKey = (event: KeyboardEvent): void => {
    if (event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey) return;
    const forward = rtl() ? 'ArrowLeft' : 'ArrowRight';
    const backward = rtl() ? 'ArrowRight' : 'ArrowLeft';
    if (event.key === forward) step(1);
    else if (event.key === backward) step(-1);
    else if (event.key === 'Home') go(0);
    else if (event.key === 'End') go(slides().length - 1);
    else return;
    event.preventDefault();
  };
  const onPrev = (): void => step(-1);
  const onNext = (): void => step(1);

  viewport.addEventListener('scroll', schedule, { passive: true });
  viewport.addEventListener('keydown', onKey);
  prev.addEventListener('click', onPrev);
  next.addEventListener('click', onNext);
  const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(schedule);
  observer?.observe(viewport);
  observer?.observe(list);
  update();

  instances.set(container, {
    refresh: schedule,
    destroy: () => {
      observer?.disconnect();
      viewport.removeEventListener('scroll', schedule);
      viewport.removeEventListener('keydown', onKey);
      window.cancelAnimationFrame(frameId);
      window.clearTimeout(timer);
      prev.remove();
      next.remove();
      status.remove();
      instances.delete(container);
    },
  });
}

/** Removes the buttons and listeners; the strip stays a native scroller. */
export function destroy(container: HTMLElement): void {
  instances.get(container)?.destroy();
}

/** Enhances every carousel in `root` (and `root` itself). Safe to call repeatedly. */
export function init(root: ParentNode = document): void {
  const containers = Array.from(root.querySelectorAll<HTMLElement>(SELECTOR));
  if (root instanceof HTMLElement && root.matches(SELECTOR)) containers.push(root);
  for (const container of containers) enhance(container);
}

declare global {
  interface Window {
    swgCarousel?: { init: typeof init; destroy: typeof destroy };
  }
}

window.swgCarousel = { init, destroy };

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => init(), { once: true });
} else {
  init();
}
