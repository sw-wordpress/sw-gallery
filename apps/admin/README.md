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

## Gallery editor (`src/features/editor`, route `#/galleries/<id>`)

**Staged editing model.** `model.ts` (pure) and `useGalleryEditor.ts` keep `base` (what the server last confirmed) and a local working copy. Removals, order, text overrides, cover, status, layout, sort mode and settings are _staged_ and only sent by "Save"; undo/redo (Ctrl/Cmd+Z, Ctrl/Cmd+Shift+Z, buttons) work on snapshots, "Discard changes" is itself undoable, a successful or partly successful save clears the history. Adding images (media modal, upload) is applied immediately because the server assigns the item ids; undo = remove the image.

**Save order** (`buildPlan`): remove (batches of 500) → text overrides (one PATCH per item, changed fields only) → order (PUT with the complete remaining list) → gallery details (one PATCH, `settings` is the complete merged map; publishing last). Every response's `revision` becomes the next `expectedRevision` (chaining). A failure stops the pipeline, reports what was saved and what not, keeps the rest staged and re-syncs: if the server revision differs from the chained one, the conflict dialog opens.

**Conflict (409).** The dialog shows what others changed (details, images added/removed/moved, texts) and the staged change count. "Reload and re-apply" (`conflict.ts: rebase`): staged details and texts always win and differing server changes are listed first; removals of vanished items are moot; texts of vanished items are dropped and counted; the staged order survives only if nobody else reordered (others' additions are appended), otherwise it is dropped with a notice. "Discard my changes" takes the server state. Nothing is dropped silently.

**Items grid.** All pages of 100 are loaded (bounded by the reorder limit of 10,000: above it the first 10,000 are shown, reordering is disabled and a notice says so). Windowed rendering (`virtual.ts`): only visible rows plus overscan are in the DOM; one focusable `listbox` with `aria-activedescendant`, `aria-posinset`/`aria-setsize`. Keys: arrows/Home/End/PageUp/PageDown move, Space toggles, Shift extends, Ctrl/Cmd+A selects all, Enter opens details, Delete removes (with confirmation), Ctrl/Cmd+arrows/Home/End reorder. Drag and drop is native HTML5 (no dependency); the toolbar buttons and keys are the touch/assistive alternative.

**Picker and upload.** `useMediaPicker` opens the native `wp.media` modal (`wp_enqueue_media()` runs on the plugin screen for users with `upload_files`; contributors get the button disabled with an explanation). WordPress closes the modal before it triggers `select`, so a close only counts as "cancelled" after the same tick. `ApiClient.upload()` (XMLHttpRequest, real byte progress, AbortController) feeds `useUploads`: two files at a time, per-file cancel/retry, client-side type/size checks are hints only, and all uploaded files are assigned in one call (partial failures still add what worked).

**Chunking rule.** PHP loads the entry as `app-<hash>.js?ver=<version>`. A lazy chunk importing the entry would make the browser evaluate it again under the unversioned URL (second Vue, second `bootstrap()`), so `vite.config.ts` puts node_modules and code shared by several chunks into `vendor`/`core`, and `scripts/check-chunks.mjs` (part of `npm run build`) fails if any chunk imports the entry. `bootstrap()` is idempotent as a second line of defence.

## Design system (D14)

Tokens (light/dark, palette identical to the other sw-* plugins) live in `src/styles/app.css` under `.swg-root`; dark mode is the class `swg-dark` on the root and on Quasar portals (`composables/useTheme.ts`). Fonts (Inter, JetBrains Mono; woff2, latin + latin-ext) come from `src/styles/fonts.css`. `layouts/ShellLayout.vue` holds rail, collapse, theme toggle, skip link and live region; theme and rail state persist per viewer in localStorage (`swg-theme`, `swg-rail-collapsed`), storage failures are tolerated. Building blocks: `components/ui/` (`Swg*`, props/slots only, no i18n). Nav entries carry an optional `capability` in `router` `NAV_ITEMS` (UX hint). `tsconfig` uses `strictTemplates: false` (aria attributes on Quasar components), as the sibling plugins do.
