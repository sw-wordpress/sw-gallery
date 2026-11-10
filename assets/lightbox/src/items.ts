export interface GalleryItem {
  link: HTMLAnchorElement;
  href: string;
  width: number | null;
  height: number | null;
  srcset: string | null;
  sizes: string | null;
  alt: string;
  caption: string;
}

const SAFE_URL = /^(https?:|data:image\/)/i;

function dimension(value: string | undefined): number | null {
  const n = Number.parseInt(value ?? '', 10);
  return Number.isFinite(n) && n > 0 ? n : null;
}

function nonEmpty(value: string | undefined): string | null {
  return value !== undefined && value.trim() !== '' ? value : null;
}

function toItem(link: HTMLAnchorElement): GalleryItem | null {
  const attr = link.getAttribute('href');
  if (attr === null || attr.trim() === '' || !SAFE_URL.test(link.href)) return null;
  const caption = link.closest('.swg-item')?.querySelector('.swg-caption');
  return {
    link,
    href: link.href,
    width: dimension(link.dataset.swgFullWidth),
    height: dimension(link.dataset.swgFullHeight),
    srcset: nonEmpty(link.dataset.swgFullSrcset),
    sizes: nonEmpty(link.dataset.swgFullSizes),
    alt: link.querySelector('img')?.getAttribute('alt') ?? '',
    // textContent only: captions are never parsed as markup.
    caption: (caption?.textContent ?? '').trim(),
  };
}

/** Reads the current items of one gallery container (called at open time, never cached). */
export function readItems(container: Element): GalleryItem[] {
  const items: GalleryItem[] = [];
  for (const link of container.querySelectorAll<HTMLAnchorElement>('a.swg-link')) {
    const item = toItem(link);
    if (item) items.push(item);
  }
  return items;
}

/** True when text direction at `el` is right-to-left (CSS direction or nearest dir attribute). */
export function isRtl(el: Element): boolean {
  if (window.getComputedStyle(el).direction === 'rtl') return true;
  return el.closest('[dir]')?.getAttribute('dir')?.toLowerCase() === 'rtl';
}
