import { isRtl, type GalleryItem } from './items.ts';
import { formatCounter, type Labels } from './labels.ts';
import { attachSwipe } from './swipe.ts';

export interface ViewerOptions {
  container: HTMLElement;
  items: GalleryItem[];
  index: number;
  labels: Labels;
  opener: HTMLElement;
  onClose?: () => void;
}

function el<K extends keyof HTMLElementTagNameMap>(
  tag: K,
  className: string,
): HTMLElementTagNameMap[K] {
  const node = document.createElement(tag);
  node.className = className;
  return node;
}

function button(className: string, label: string): HTMLButtonElement {
  const b = el('button', className);
  b.type = 'button';
  b.setAttribute('aria-label', label);
  return b;
}

/** One open lightbox dialog. All state is per instance; `close()` undoes every side effect. */
export class Viewer {
  private readonly root: HTMLElement;
  private readonly stage: HTMLElement;
  private readonly figure: HTMLElement;
  private readonly image: HTMLImageElement;
  private readonly status: HTMLElement;
  private readonly caption: HTMLElement;
  private readonly counter: HTMLElement | null;
  private readonly closeButton: HTMLButtonElement;
  private readonly prevButton: HTMLButtonElement | null;
  private readonly nextButton: HTMLButtonElement | null;
  private readonly abort = new AbortController();
  private readonly rtl: boolean;
  private readonly inerted: Element[] = [];
  private readonly saved = { overflow: '', paddingRight: '' };
  private index: number;
  private open = true;
  private shown = false;

  constructor(private readonly opts: ViewerOptions) {
    const { items, labels } = opts;
    this.index = Math.min(Math.max(opts.index, 0), items.length - 1);
    this.rtl = isRtl(opts.container);
    const multiple = items.length > 1;

    this.root = el('div', 'swg-lightbox');
    this.root.setAttribute('role', 'dialog');
    this.root.setAttribute('aria-modal', 'true');
    this.root.setAttribute('aria-label', labels.dialog);
    this.root.tabIndex = -1;
    this.root.dir = this.rtl ? 'rtl' : 'ltr';
    const instance = opts.container.dataset.swgInstance;
    if (instance) this.root.dataset.swgInstance = instance;
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
      this.root.classList.add('swg-lightbox--reduced');
    }

    this.stage = el('div', 'swg-lightbox__stage');
    this.figure = el('figure', 'swg-lightbox__figure');
    this.image = el('img', 'swg-lightbox__image');
    this.image.decoding = 'async';
    this.status = el('p', 'swg-lightbox__status');
    this.status.setAttribute('role', 'status');
    this.caption = el('figcaption', 'swg-lightbox__caption');
    this.figure.append(this.image, this.status, this.caption);
    this.stage.append(this.figure);

    this.closeButton = button('swg-lightbox__button swg-lightbox__close', labels.close);
    this.prevButton = multiple
      ? button('swg-lightbox__button swg-lightbox__nav swg-lightbox__prev', labels.previous)
      : null;
    this.nextButton = multiple
      ? button('swg-lightbox__button swg-lightbox__nav swg-lightbox__next', labels.next)
      : null;
    this.counter = multiple ? el('div', 'swg-lightbox__counter') : null;
    if (this.counter) {
      this.counter.setAttribute('aria-live', 'polite');
      this.counter.setAttribute('aria-atomic', 'true');
    }

    for (const node of [
      this.stage,
      this.prevButton,
      this.nextButton,
      this.counter,
      this.closeButton,
    ]) {
      if (node) this.root.append(node);
    }

    this.bind();
    this.lockBackground();
    document.body.append(this.root);
    this.show(this.index);
    this.closeButton.focus({ preventScroll: true });
  }

  isOpen(): boolean {
    return this.open;
  }

  close(): void {
    if (!this.open) return;
    this.open = false;
    this.abort.abort();
    this.root.remove();
    for (const node of this.inerted) node.removeAttribute('inert');
    document.body.style.overflow = this.saved.overflow;
    document.body.style.paddingRight = this.saved.paddingRight;
    const { opener, container } = this.opts;
    const target = opener.isConnected ? opener : container.querySelector<HTMLElement>('a.swg-link');
    target?.focus({ preventScroll: true });
    this.opts.onClose?.();
  }

  private bind(): void {
    const { signal } = this.abort;
    document.addEventListener('keydown', (e) => this.onKey(e), { signal });
    this.closeButton.addEventListener('click', () => this.close(), { signal });
    this.prevButton?.addEventListener('click', () => this.go(-1), { signal });
    this.nextButton?.addEventListener('click', () => this.go(1), { signal });
    this.root.addEventListener(
      'click',
      (e) => {
        // Backdrop click: only the empty overlay/stage area, never the image or controls.
        if (e.target === this.root || e.target === this.stage || e.target === this.figure) {
          this.close();
        }
      },
      { signal },
    );
    this.image.addEventListener('load', () => this.setState('loaded'), { signal });
    this.image.addEventListener('error', () => this.setState('error'), { signal });
    // Finger left (-1) shows the next image in LTR; mirrored in RTL.
    attachSwipe(this.stage, (dir) => this.go(this.rtl ? dir : -dir), signal);
  }

  private lockBackground(): void {
    for (const child of Array.from(document.body.children)) {
      if (child === this.root || child.hasAttribute('inert')) continue;
      child.setAttribute('inert', '');
      this.inerted.push(child);
    }
    const body = document.body;
    this.saved.overflow = body.style.overflow;
    this.saved.paddingRight = body.style.paddingRight;
    const scrollbar = window.innerWidth - document.documentElement.clientWidth;
    if (scrollbar > 0) {
      const current = Number.parseFloat(window.getComputedStyle(body).paddingRight) || 0;
      body.style.paddingRight = `${current + scrollbar}px`;
    }
    body.style.overflow = 'hidden';
  }

  private onKey(e: KeyboardEvent): void {
    if (e.key === 'Tab') return this.trapTab(e);
    if (e.altKey || e.ctrlKey || e.metaKey) return;
    switch (e.key) {
      case 'Escape':
        e.preventDefault();
        this.close();
        break;
      case 'ArrowLeft':
        e.preventDefault();
        this.go(this.rtl ? 1 : -1);
        break;
      case 'ArrowRight':
        e.preventDefault();
        this.go(this.rtl ? -1 : 1);
        break;
      case 'Home':
        e.preventDefault();
        this.show(0);
        break;
      case 'End':
        e.preventDefault();
        this.show(this.opts.items.length - 1);
        break;
    }
  }

  private trapTab(e: KeyboardEvent): void {
    const focusable = Array.from(this.root.querySelectorAll<HTMLElement>('button:not([disabled])'));
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (!first || !last) {
      e.preventDefault();
      this.root.focus();
      return;
    }
    const active = document.activeElement;
    if (!this.root.contains(active)) {
      e.preventDefault();
      first.focus();
    } else if (e.shiftKey && (active === first || active === this.root)) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && active === last) {
      e.preventDefault();
      first.focus();
    }
  }

  private go(step: number): void {
    this.show(this.index + step);
  }

  private show(next: number): void {
    const { items, labels } = this.opts;
    const target = Math.min(Math.max(next, 0), items.length - 1);
    const item = items[target];
    if (!item || (this.shown && target === this.index)) return;
    this.shown = true;
    this.index = target;

    this.setState('loading');
    this.image.alt = item.alt;
    this.applySource(this.image, item);
    this.caption.textContent = item.caption;
    this.caption.hidden = item.caption === '';
    if (this.counter) {
      this.counter.textContent = formatCounter(labels.counter, target + 1, items.length);
    }
    this.updateNav();
    this.preload(target - 1);
    this.preload(target + 1);
  }

  private applySource(img: HTMLImageElement, item: GalleryItem): void {
    for (const [name, value] of [
      ['width', item.width],
      ['height', item.height],
    ] as const) {
      if (value === null) img.removeAttribute(name);
      else img.setAttribute(name, String(value));
    }
    for (const [name, value] of [
      ['sizes', item.sizes],
      ['srcset', item.srcset],
    ] as const) {
      if (value === null) img.removeAttribute(name);
      else img.setAttribute(name, value);
    }
    img.src = item.href;
  }

  private preload(index: number): void {
    const item = this.opts.items[index];
    if (item) this.applySource(new Image(), item);
  }

  private setState(state: 'loading' | 'loaded' | 'error'): void {
    this.figure.classList.toggle('swg-lightbox__figure--loading', state === 'loading');
    this.figure.classList.toggle('swg-lightbox__figure--error', state === 'error');
    this.figure.setAttribute('aria-busy', state === 'loading' ? 'true' : 'false');
    this.status.textContent = state === 'error' ? this.opts.labels.error : '';
    this.image.hidden = state === 'error';
  }

  private updateNav(): void {
    const last = this.opts.items.length - 1;
    const buttons: [HTMLButtonElement | null, boolean][] = [
      [this.prevButton, this.index === 0],
      [this.nextButton, this.index === last],
    ];
    for (const [node, disabled] of buttons) {
      if (!node) continue;
      node.disabled = disabled;
    }
    // A disabled control cannot keep focus: hand it to the close button.
    const active = document.activeElement;
    if (active instanceof HTMLButtonElement && active.disabled && this.root.contains(active)) {
      this.closeButton.focus({ preventScroll: true });
    }
  }
}
