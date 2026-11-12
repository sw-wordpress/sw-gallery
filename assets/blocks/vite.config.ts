import { resolve } from 'node:path';
import { defineConfig } from 'vitest/config';

/**
 * Block editor glue: ONE classic (non-module) IIFE `build/index.js` (+ `index.css`) with stable file names
 * (cache busting uses the plugin version, see Block/BlockRegistrar). Every `@wordpress/*` import is an
 * external taken from the `wp.*` global of the editor; nothing from WordPress is bundled.
 */
const wpGlobal = (id: string): string => {
  const name = id.replace(/^@wordpress\//, '');
  return `wp.${name.replace(/-([a-z])/g, (_match, letter: string) => letter.toUpperCase())}`;
};

const stub = (name: string): string => resolve(import.meta.dirname, `tests/stubs/${name}.tsx`);

export default defineConfig({
  root: import.meta.dirname,
  base: './',
  oxc: {
    jsx: { runtime: 'classic', pragma: 'createElement', pragmaFrag: 'Fragment' },
  },
  build: {
    outDir: resolve(import.meta.dirname, 'build'),
    emptyOutDir: true,
    sourcemap: false,
    cssCodeSplit: false,
    // Matches the shared browserslist (package.json): evergreen browsers, Safari >= 16.
    target: ['es2021', 'chrome100', 'edge100', 'firefox100', 'safari16', 'ios16'],
    lib: {
      entry: { index: resolve(import.meta.dirname, 'src/index.tsx') },
      formats: ['iife'],
      name: 'swgBlocks',
      cssFileName: 'index',
    },
    rolldownOptions: {
      external: [/^@wordpress\//],
      output: {
        entryFileNames: '[name].js',
        assetFileNames: '[name][extname]',
        globals: wpGlobal,
      },
    },
  },
  resolve: {
    // Tests run against light stubs of the editor packages (the real ones only exist inside WordPress).
    alias:
      process.env.VITEST === 'true'
        ? {
            '@wordpress/components': stub('components'),
            '@wordpress/block-editor': stub('block-editor'),
            '@wordpress/blocks': stub('blocks'),
            '@wordpress/server-side-render': stub('server-side-render'),
            '@wordpress/api-fetch': stub('api-fetch'),
          }
        : {},
  },
  test: {
    environment: 'jsdom',
    include: ['tests/**/*.test.ts', 'tests/**/*.test.tsx'],
    css: false,
    coverage: {
      provider: 'v8',
      // All productive sources, including files no test imports. The strict > 80 % gate is
      // enforced by scripts/check-coverage.mjs, because Vitest thresholds accept exactly 80 %.
      include: ['src/**/*.ts', 'src/**/*.tsx'],
      exclude: ['**/*.d.ts'],
      // lcov paths relative to the repository root, as SonarQube expects.
      reporter: ['text-summary', 'json-summary', ['lcov', { projectRoot: '../..' }]],
      reportsDirectory: 'coverage',
      thresholds: { lines: 80 },
    },
  },
});
