import { readItems } from './items.ts';
import { parseLabels } from './labels.ts';
import { Viewer } from './viewer.ts';

const READY_ATTR = 'data-swg-lightbox-ready';
let active: Viewer | null = null;

function onClick(container: HTMLElement, event: MouseEvent): void {
  if (event.defaultPrevented || event.button !== 0) return;
  // Keep the browser default (new tab/window/download) for modified clicks.
  if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
  if (active?.isOpen()) return;
  const target = event.target;
  if (!(target instanceof Element)) return;
  const link = target.closest<HTMLAnchorElement>('a.swg-link');
  if (!link || !container.contains(link)) return;

  // Items are read now, so galleries changed by paging/AJAX stay correct.
  const items = readItems(container);
  const index = items.findIndex((item) => item.link === link);
  if (index < 0) return; // unusable href: let the browser follow the link

  event.preventDefault();
  active = new Viewer({
    container,
    items,
    index,
    labels: parseLabels(container.dataset.swgLabels),
    opener: link,
    onClose: () => {
      active = null;
    },
  });
}

/** Enhances every `[data-swg-gallery]` in `root` (and `root` itself). Safe to call repeatedly. */
export function init(root: ParentNode = document): void {
  const containers = Array.from(root.querySelectorAll<HTMLElement>('[data-swg-gallery]'));
  if (root instanceof HTMLElement && root.matches('[data-swg-gallery]')) containers.push(root);
  for (const container of containers) {
    if (container.hasAttribute(READY_ATTR)) continue;
    container.setAttribute(READY_ATTR, '');
    container.addEventListener('click', (event) => onClick(container, event));
  }
}
