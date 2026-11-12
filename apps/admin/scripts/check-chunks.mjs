// Fails if a lazy chunk imports the ENTRY file. PHP loads the entry as `app-<hash>.js?ver=<plugin version>`; a chunk
// importing `../app-<hash>.js` would make the browser evaluate the entry a second time under the unversioned URL
// (a second Vue runtime and a second bootstrap() that remounts the app). Shared code must live in a shared chunk
// (see `advancedChunks` in vite.config.ts).
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const outDir =
    process.argv[2] ?? join(dirname(fileURLToPath(import.meta.url)), '../../../assets/admin');
const manifest = JSON.parse(readFileSync(join(outDir, '.vite/manifest.json'), 'utf8'));
const entries = Object.values(manifest)
    .filter((entry) => entry.isEntry && entry.file.endsWith('.js'))
    .map((entry) => entry.file);
if (entries.length === 0) {
    console.error('No JS entry listed in the Vite manifest.');
    process.exit(1);
}
const bad = [];
const chunkDir = join(outDir, 'chunks');
for (const name of readdirSync(chunkDir).filter((file) => file.endsWith('.js'))) {
    const source = readFileSync(join(chunkDir, name), 'utf8');
    for (const entry of entries) {
        const base = entry.split('/').pop();
        if (source.includes(`/${base}"`) || source.includes(`/${base}'`))
            bad.push(`${name} -> ${base}`);
    }
}
if (bad.length > 0) {
    console.error(`Chunks import the entry file:\n  ${bad.join('\n  ')}`);
    process.exit(1);
}
console.log(`Chunk graph OK: no chunk imports the entry (${entries.join(', ')}).`);
