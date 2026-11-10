import './lightbox.css';
import { init } from './init.ts';

declare global {
  interface Window {
    swgLightbox?: { init: typeof init };
  }
}

window.swgLightbox = { init };

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => init(), { once: true });
} else {
  init();
}
