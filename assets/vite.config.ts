import { resolve } from 'node:path';
import { defineConfig } from 'vitest/config';

/**
 * Public frontend assets. Each entry is a classic (non-module) script: an IIFE bundle without
 * runtime dependencies. rolldown cannot emit several IIFE entries in one build, so the config
 * builds one entry per run, selected by SWG_ENTRY (see `entries`); add further entries
 * (e.g. `frontend`) only together with real code and a build loop in scripts/build-all.mjs.
 */
const entries: Record<string, string> = {
  lightbox: resolve(import.meta.dirname, 'lightbox/src/main.ts'),
};
const name = process.env.SWG_ENTRY ?? 'lightbox';
const input = entries[name];
if (!input) throw new Error(`Unknown SWG_ENTRY "${name}"`);

export default defineConfig({
  root: import.meta.dirname,
  base: './',
  build: {
    outDir: resolve(import.meta.dirname, 'build'),
    emptyOutDir: true,
    manifest: true,
    cssCodeSplit: false,
    sourcemap: false,
    // Matches the shared browserslist (package.json): evergreen browsers, Safari >= 16.
    target: ['es2021', 'chrome100', 'edge100', 'firefox100', 'safari16', 'ios16'],
    modulePreload: false,
    // Library mode (IIFE) keeps the CSS as a separate file instead of injecting it from the script.
    lib: {
      entry: { [name]: input },
      formats: ['iife'],
      name: `swg_${name}`,
      cssFileName: name,
    },
    rolldownOptions: {
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
