import { init } from '../src/init.ts';

export const LABELS = {
  dialog: 'Image viewer',
  close: 'Close',
  previous: 'Previous image',
  next: 'Next image',
  counter: '{current} of {total}',
};

export interface Opts {
  count?: number;
  instance?: string;
  attrs?: string;
  labels?: string;
  captions?: Record<number, string>;
}

/** Markup contract fixture (assets/README.md). */
export function galleryHtml(o: Opts = {}): string {
  const count = o.count ?? 3;
  const inst = o.instance ?? 'swg-1-1';
  const labels = o.labels ?? JSON.stringify(LABELS);
  const items = Array.from({ length: count }, (_, i) => {
    const n = i + 1;
    const caption = o.captions?.[n];
    return `<li class="swg-item">
      <a class="swg-link" href="https://example.test/${inst}/full-${n}.jpg" data-swg-full-width="2000"
         data-swg-full-height="1333" data-swg-full-srcset="https://example.test/${inst}/m-${n}.jpg 800w, https://example.test/${inst}/full-${n}.jpg 2000w"
         data-swg-full-sizes="100vw"><img src="https://example.test/${inst}/t-${n}.jpg" alt="Alt ${n}" width="300" height="200"></a>
      ${caption === undefined ? '' : `<p class="swg-caption">${caption}</p>`}
    </li>`;
  }).join('');
  return `<div class="swg-gallery" data-swg-gallery data-swg-instance="${inst}" data-swg-labels='${labels}' ${o.attrs ?? ''}>
    <ul class="swg-items" role="list">${items}</ul></div>`;
}

export function mount(html: string): void {
  document.body.innerHTML = html;
  init();
}

export function links(container: ParentNode = document): HTMLAnchorElement[] {
  return Array.from(container.querySelectorAll<HTMLAnchorElement>('a.swg-link'));
}

export function dialog(): HTMLElement | null {
  return document.querySelector<HTMLElement>('.swg-lightbox');
}

export function key(k: string, init: KeyboardEventInit = {}): KeyboardEvent {
  const e = new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true, ...init });
  (document.activeElement ?? document.body).dispatchEvent(e);
  return e;
}

/**
 * Dispatches a click and reports whether the page prevented the default. jsdom cannot navigate,
 * so a final document listener blocks the navigation after the state has been recorded.
 */
export function click(node: Element, init: MouseEventInit = {}): { defaultPrevented: boolean } {
  const e = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init });
  let prevented = false;
  const record = (ev: Event): void => {
    prevented = ev.defaultPrevented;
    ev.preventDefault();
  };
  document.addEventListener('click', record);
  node.dispatchEvent(e);
  document.removeEventListener('click', record);
  return { defaultPrevented: prevented };
}

export function pointer(
  node: Element,
  type: string,
  x: number,
  y: number,
  extra: { pointerType?: string; pointerId?: number } = {},
): void {
  const e = new Event(type, { bubbles: true, cancelable: true });
  Object.assign(e, { clientX: x, clientY: y, pointerType: 'touch', pointerId: 1, ...extra });
  node.dispatchEvent(e);
}

export function counterText(): string {
  return document.querySelector('.swg-lightbox__counter')?.textContent ?? '';
}

export function currentImage(): HTMLImageElement {
  const img = document.querySelector<HTMLImageElement>('.swg-lightbox__image');
  if (!img) throw new Error('no lightbox image');
  return img;
}
