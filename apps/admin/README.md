# sw-gallery admin app

Vue 3 (Composition API, `<script setup lang="ts">`) + Quasar (via `@quasar/vite-plugin`, no Quasar CLI) + Vite + strict TypeScript + vue-router (hash history) + vue-i18n. No React; Pinia only for shared UI state (`stores/app.ts`: theme, rail, live region); no CDN or runtime fetch of third-party resources.

## Commands (Node 22, see `.nvmrc`)

```bash
cd apps/admin
npm ci
npm run dev             # dev host page (index.html) simulating wp-admin; ?lang=de&rtl=1&caps=a,b
npm run typecheck       # vue-tsc --noEmit
npm run lint            # ESLint; bans v-html and `any`
npm run format:check    # Prettier
npm run test:coverage   # Vitest + strict gate: line coverage must be > 80 % (80.00 fails)
npm run build           # typecheck + Vite build into ../../assets/admin (git-ignored) + CSS scope check
```

## Server bootstrap contract: `window.swgAdmin`

The PHP admin page prints this object before the module script. It is the only source for REST root, nonce, locale and capabilities (never localStorage, URL or cookies; the nonce lives in memory only).

```ts
interface SwgAdminBootstrap {
    restRoot: string; // required, e.g. https://x/wp-json/swg/v1/ (trailing "/" optional, "?rest_route=/swg/v1/" works)
    nonce: string; // wp_rest nonce -> X-WP-Nonce
    locale: string; // user locale, e.g. "de_DE_formal"; first two letters select en|de, otherwise en
    version: string; // plugin version, display only
    adminUrl: string; // admin_url() with trailing slash
    capabilities: string[]; // UX hints only; the server enforces
    isRtl?: boolean; // is_rtl(); sets dir="rtl" on the root
    timezone?: string; // wp_timezone_string(), reserved for date formatting
}
```

Mount node: `<div id="swg-admin-root"></div>`. Without a valid bootstrap the app renders a translated `role="alert"` message instead of failing silently.

## Build output and enqueueing

`vite build` writes to `assets/admin/` (emptied on each build) with content hashes and `.vite/manifest.json`:

- JS entry: manifest key `src/main.ts` -> `file` (`app-<hash>.js`), load as `type="module"`. Lazy chunks are relative (`base: './'`).
- CSS: one stylesheet (`cssCodeSplit: false`), listed in the manifest as its own entry with key `style.css`.
- Fonts: the Material Icons Round woff2 under `assets/`.

PHP resolves files from the manifest and passes the plugin version as `ver` for each asset.

## CSS isolation

Quasar ships resets and utilities that target `html`, `body`, `*` and bare elements, which would restyle wp-admin. Approach:

1. All app CSS uses the `swg-` prefix and lives under the root class `.swg-root` (set on `#swg-admin-root`); there are no global resets and no element selectors.
2. `tools/postcssScope.ts` rewrites every selector that originates from `node_modules/quasar` or `@quasar/extras` at build time: `html`/`body`/`:root` become `.swg-root`, everything else becomes `.swg-root <selector>`, and dark/light/scroll-lock body classes are mapped to the root.
3. Quasar portals (dialogs, menus, notifications) are appended to `<body>`; `config.globalNodes.class = 'swg-root'` gives them the root class so the scoped CSS applies.
4. `scripts/check-css-scope.mjs` (part of `npm run build`) fails if any selector in the built CSS is not scoped.
5. The assets are only enqueued on sw-gallery admin screens (PHP side, M2-15), so the stylesheet never loads on foreign screens either.

## API layer (`src/api`)

Components never call `fetch`. `ApiClient` adds nonce and `same-origin` credentials, speaks JSON, takes over a refreshed `X-WP-Nonce`, normalises WP_Error/HTTP/network failures into `ApiError`, supports `AbortSignal`, retries idempotent `GET` on network errors and 502/503/504 (max 3 retries, exponential backoff, abortable) and never retries writes. HTTP 409 becomes `ConflictError` (`isConflictError`) carrying the server `data` in `details`. `errorMessage(error, t)` maps errors to catalog strings by code/status and never shows server text verbatim.

## i18n

Catalogs `src/i18n/{en,de}.ts` (typed: `de` must match `en` at compile time). `tests/config-i18n.test.ts` fails on missing/extra keys, differing `{placeholders}` and differing plural forms. Quasar's language pack follows the same locale (`quasarLang.ts`).

## Design system (D14)

Tokens (light/dark, palette identical to the other sw-* plugins) live in `src/styles/app.css` under `.swg-root`; dark mode is the class `swg-dark` on the root and on Quasar portals (`composables/useTheme.ts`). Fonts (Inter, JetBrains Mono; woff2, latin + latin-ext) come from `src/styles/fonts.css`. `layouts/ShellLayout.vue` holds rail, collapse, theme toggle, skip link and live region; theme and rail state persist per viewer in localStorage (`swg-theme`, `swg-rail-collapsed`), storage failures are tolerated. Building blocks: `components/ui/` (`Swg*`, props/slots only, no i18n). Nav entries carry an optional `capability` in `router` `NAV_ITEMS` (UX hint). `tsconfig` uses `strictTemplates: false` (aria attributes on Quasar components), as the sibling plugins do.
