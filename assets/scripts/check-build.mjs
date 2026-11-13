// Post-build check: merged manifest shape, classic (non-module) script, no sourcemaps, size budgets.
import { readFileSync, readdirSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

const BUDGET_JS_GZIP = 12 * 1024;
const BUDGET_MASONRY_GZIP = 2 * 1024;
const BUDGET_CSS_GZIP = 4 * 1024;
const dir = new URL('../build/', import.meta.url);
const fail = (msg) => {
    console.error(`FAIL: ${msg}`);
    process.exit(1);
};

let manifest;
try {
    manifest = JSON.parse(readFileSync(new URL('.vite/manifest.json', dir), 'utf8'));
} catch {
    fail('build/.vite/manifest.json missing or invalid');
}

const entry = manifest['lightbox/src/main.ts'];
if (!entry?.isEntry || !entry.file?.endsWith('.js')) fail('manifest entry lightbox/src/main.ts');
const style = manifest['style.css'];
if (!style?.file?.endsWith('.css')) fail('manifest entry style.css');
const layouts = manifest['frontend/src/layouts.css'];
if (!layouts?.isEntry || !layouts.file?.endsWith('.css'))
    fail('manifest entry frontend/src/layouts.css');
const masonry = manifest['frontend/src/masonry.ts'];
if (!masonry?.isEntry || !masonry.file?.endsWith('.js'))
    fail('manifest entry frontend/src/masonry.ts');
if (readdirSync(dir).some((f) => f.endsWith('.map'))) fail('sourcemap found in build');

const js = readFileSync(new URL(entry.file, dir));
if (/^\s*(import|export)\s/m.test(js.toString('utf8'))) fail('lightbox script is not classic');
const masonryJs = readFileSync(new URL(masonry.file, dir));
if (/^\s*(import|export)\s/m.test(masonryJs.toString('utf8')))
    fail('masonry script is not classic');
for (const f of [entry.file, masonry.file, style.file, layouts.file]) {
    const buf = readFileSync(new URL(f, dir));
    console.log(`${f}: ${buf.length} B raw, ${gzipSync(buf).length} B gzip`);
}
const gz = gzipSync(js).length;
if (gz > BUDGET_JS_GZIP) fail(`lightbox JS gzip ${gz} B exceeds budget ${BUDGET_JS_GZIP} B`);
const masonryGz = gzipSync(masonryJs).length;
if (masonryGz > BUDGET_MASONRY_GZIP)
    fail(`masonry JS gzip ${masonryGz} B exceeds budget ${BUDGET_MASONRY_GZIP} B`);
for (const [label, file] of [
    ['lightbox CSS', style.file],
    ['layouts CSS', layouts.file],
]) {
    const size = gzipSync(readFileSync(new URL(file, dir))).length;
    if (size > BUDGET_CSS_GZIP) fail(`${label} gzip ${size} B exceeds budget ${BUDGET_CSS_GZIP} B`);
}
console.log(
    `OK: manifest valid, lightbox JS gzip ${gz} B <= ${BUDGET_JS_GZIP} B, masonry JS gzip ${masonryGz} B <= ${BUDGET_MASONRY_GZIP} B, CSS gzip <= ${BUDGET_CSS_GZIP} B each`,
);
