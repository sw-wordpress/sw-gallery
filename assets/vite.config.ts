import { resolve } from 'node:path';
import { defineConfig } from 'vitest/config';

/**
 * Public frontend assets, built in two runs that scripts/build-all.mjs merges into one manifest
 * (rolldown cannot emit several IIFE entries in one build, hence one run per entry, selected by SWG_ENTRY):
 * - `lightbox`: a classic (non-module) script, an IIFE bundle without runtime dependencies, plus its stylesheet;
 * - `masonry`: the progressive-enhancement script of the masonry layout (`frontend/src/masonry.ts`), classic IIFE too;
 * - `layouts`: the CSS-only layout stylesheet (`frontend/src/layouts.css`; grid, justified, masonry), no script.
 */
const entries: Record<string, string> = {
  lightbox: resolve(import.meta.dirname, 'lightbox/src/main.ts'),
  masonry: resolve(import.meta.dirname, 'frontend/src/masonry.ts'),
  layouts: resolve(import.meta.dirname, 'frontend/src/layouts.css'),
};
const name = process.env.SWG_ENTRY ?? 'lightbox';
const input = entries[name];
if (!input) throw new Error(`Unknown SWG_ENTRY "${name}"`);
const cssOnly = name === 'layouts';

export default defineConfig({
  root: import.meta.dirname,
  base: './',
  build: {
    outDir: resolve(import.meta.dirname, 'build'),
    // The first run of scripts/build-all.mjs empties the directory, later runs add to it.
    emptyOutDir: process.env.SWG_KEEP_OUT !== '1',
    manifest: true,
    cssCodeSplit: cssOnly,
    sourcemap: false,
    // Matches the shared browserslist (package.json): evergreen browsers, Safari >= 16.
    target: ['es2021', 'chrome100', 'edge100', 'firefox100', 'safari16', 'ios16'],
    modulePreload: false,
    // Library mode (IIFE) keeps the CSS as a separate file instead of injecting it from the script.
    ...(cssOnly
      ? {}
      : {
          lib: {
            entry: { [name]: input },
            formats: ['iife' as const],
            name: `swg_${name}`,
            cssFileName: name,
          },
        }),
    rolldownOptions: {
      ...(cssOnly ? { input } : {}),
      output: {
        entryFileNames: '[name]-[hash].js',
        assetFileNames: '[name]-[hash][extname]',
      },
    },
  },
  test: {
    environment: 'jsdom',
    include: ['lightbox/tests/**/*.test.ts', 'frontend/tests/**/*.test.ts'],
    css: false,
    coverage: {
      provider: 'v8',
      // All productive sources, including files no test imports. The strict > 80 % gate is
      // enforced by scripts/check-coverage.mjs, because Vitest thresholds accept exactly 80 %.
      include: ['lightbox/src/**/*.ts', 'frontend/src/**/*.ts'],
      exclude: ['**/*.d.ts'],
      // lcov paths relative to the repository root, as SonarQube expects.
      reporter: ['text-summary', 'json-summary', ['lcov', { projectRoot: '..' }]],
      reportsDirectory: 'coverage',
      thresholds: { lines: 80 },
    },
  },
});
