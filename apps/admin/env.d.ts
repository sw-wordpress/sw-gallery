/// <reference types="vite/client" />

import type { SwgAdminBootstrap } from './src/types/bootstrap';

declare global {
  interface Window {
    /** Bootstrap object printed by the PHP admin page before the module script. */
    swgAdmin?: SwgAdminBootstrap;
  }
}

declare module '*.vue' {
  import type { DefineComponent } from 'vue';
  const component: DefineComponent<Record<string, unknown>, Record<string, unknown>, unknown>;
  export default component;
}
