/// <reference types="vite/client" />

import type { SwgAdminBootstrap } from './src/types/bootstrap';

/** The small part of WordPress' `wp.media` (Backbone based media modal) the picker uses. */
interface WpMediaSelection {
  map<T>(callback: (attachment: { id: number }) => T): T[];
}
interface WpMediaState {
  get(key: 'selection'): WpMediaSelection;
}
interface WpMediaFrame {
  on(event: 'select' | 'close', callback: () => void): WpMediaFrame;
  open(): WpMediaFrame;
  close(): void;
  state(): WpMediaState;
}
interface WpMediaOptions {
  title?: string;
  button?: { text?: string };
  library?: { type?: string };
  multiple?: boolean | 'add' | 'toggle';
}

declare global {
  interface Window {
    /** Bootstrap object printed by the PHP admin page before the module script. */
    swgAdmin?: SwgAdminBootstrap;
    /** Present when the PHP page called `wp_enqueue_media()` (plugin screen only). */
    wp?: { media?: (options: WpMediaOptions) => WpMediaFrame };
  }
}

declare module '*.vue' {
  import type { DefineComponent } from 'vue';
  const component: DefineComponent<Record<string, unknown>, Record<string, unknown>, unknown>;
  export default component;
}

/**
 * Plain HTML attributes that fall through to the root element of a Quasar component (accessibility attributes,
 * ids, form association, key handlers). Quasar's own prop types do not list them, which fails the strict template
 * check for perfectly valid markup. Only these named attributes and the `aria-*` / `data-*` families are allowed;
 * everything else stays strictly checked.
 */
declare module 'vue' {
  interface AllowedComponentProps {
    role?: string;
    id?: string;
    form?: string;
    autocomplete?: string;
    dataAutofocus?: boolean | string;
    onKeyup?: (event: KeyboardEvent) => void;
    [attribute: `aria-${string}`]: string | boolean | number | undefined;
    [attribute: `data-${string}`]: string | boolean | number | undefined;
  }
}
