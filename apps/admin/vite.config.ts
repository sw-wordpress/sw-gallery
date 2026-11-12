import { fileURLToPath, URL } from 'node:url';
import { resolve } from 'node:path';
import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { quasar, transformAssetUrls } from '@quasar/vite-plugin';
import { swgScope } from './tools/postcssScope.ts';

/** Ship only the woff2 icon font (all supported browsers); drops the legacy woff. */
function woff2Only() {
  return {
    name: 'swg-woff2-only',
    enforce: 'pre' as const,
    transform(code: string, id: string) {
      if (!id.includes('material-icons-round.css')) return null;
      return code.replace(/,\s*url\([^)]*\.woff\d?\??[^)]*\)\s*format\((['"])woff\1\)/g, '');
    },
  };
}

const outDir = resolve(import.meta.dirname, '../../assets/admin');

export default defineConfig({
  base: './',
  plugins: [woff2Only(), vue({ template: { transformAssetUrls } }), quasar()],
  resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
  css: { postcss: { plugins: [swgScope()] } },
  server: { port: 5173, host: '127.0.0.1' },
  build: {
    outDir,
    emptyOutDir: true,
    manifest: true,
    cssCodeSplit: false,
    sourcemap: false,
    target: 'es2022',
    modulePreload: { polyfill: false },
    chunkSizeWarningLimit: 900,
    rolldownOptions: {
      input: { app: resolve(import.meta.dirname, 'src/main.ts') },
      output: {
        // Code shared by the entry and lazy views goes into its own chunk. Otherwise lazy chunks import the ENTRY
        // (`../app-<hash>.js`): PHP loads the entry as `app-<hash>.js?ver=<plugin version>`, so the browser would
        // evaluate it a second time under the unversioned URL (a second Vue runtime and a second `bootstrap()`).
        advancedChunks: {
          groups: [
            { name: 'vendor', test: /node_modules/ },
            { name: 'core', minShareCount: 2 },
          ],
        },
        entryFileNames: 'app-[hash].js',
        chunkFileNames: 'chunks/[name]-[hash].js',
        assetFileNames: 'assets/[name]-[hash][extname]',
      },
    },
  },
  test: {
    environment: 'jsdom',
    include: ['tests/**/*.test.ts'],
    css: false,
    server: { deps: { inline: ['quasar'] } },
    coverage: {
      provider: 'v8',
      // All productive sources, including files no test imports. Only type declarations are
      // excluded (no executable code). The strict > 80 % gate is enforced by
      // scripts/check-coverage.mjs, because Vitest thresholds accept exactly 80 %.
      include: ['src/**/*.{ts,vue}'],
      exclude: ['src/types/**', 'src/**/*.d.ts'],
      // lcov paths relative to the repository root, as SonarQube expects.
      reporter: ['text-summary', 'json-summary', ['lcov', { projectRoot: '../..' }]],
      reportsDirectory: 'coverage',
      thresholds: { lines: 80 },
    },
  },
});
