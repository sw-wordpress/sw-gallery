// Builds every public entry (one Vite run each, see vite.config.ts) into assets/build and merges the per-run
// manifests into one `.vite/manifest.json` (keys: lightbox/src/main.ts, style.css, frontend/src/layouts.css).
import { spawnSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const ENTRIES = ['lightbox', 'layouts'];
const root = fileURLToPath(new URL('..', import.meta.url));
const manifestFile = new URL('../build/.vite/manifest.json', import.meta.url);
const vite = fileURLToPath(new URL('../node_modules/vite/bin/vite.js', import.meta.url));

const merged = {};
ENTRIES.forEach((entry, index) => {
    const run = spawnSync(process.execPath, [vite, 'build'], {
        cwd: root,
        stdio: 'inherit',
        env: { ...process.env, SWG_ENTRY: entry, SWG_KEEP_OUT: index === 0 ? '0' : '1' },
    });
    if (run.status !== 0) process.exit(run.status ?? 1);
    Object.assign(merged, JSON.parse(readFileSync(manifestFile, 'utf8')));
});

mkdirSync(new URL('../build/.vite/', import.meta.url), { recursive: true });
writeFileSync(manifestFile, `${JSON.stringify(merged, null, 2)}\n`);
console.log(`manifest merged: ${Object.keys(merged).join(', ')}`);
