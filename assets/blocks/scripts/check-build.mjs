// Post-build check: classic (non-module) IIFE script, @wordpress/* only from wp.* globals, no sourcemaps, size budget.
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

const BUDGET_JS_GZIP = 16 * 1024;
const dir = new URL('../build/', import.meta.url);
const fail = (msg) => {
    console.error(`FAIL: ${msg}`);
    process.exit(1);
};

if (!existsSync(new URL('index.js', dir))) fail('build/index.js missing');
if (readdirSync(dir).some((f) => f.endsWith('.map'))) fail('sourcemap found in build');

const source = readFileSync(new URL('index.js', dir), 'utf8');
if (/^\s*(import|export)\s/m.test(source)) fail('index.js is not a classic script');
if (/\brequire\(["']@wordpress/.test(source) || /from\s*["']@wordpress/.test(source)) {
    fail('index.js still references @wordpress/* modules instead of wp.* globals');
}
for (const global of ['wp.element', 'wp.blocks', 'wp.blockEditor', 'wp.i18n', 'wp.apiFetch']) {
    if (!source.includes(global)) fail(`index.js does not use the ${global} global`);
}

for (const file of readdirSync(dir)) {
    const buf = readFileSync(new URL(file, dir));
    console.log(`${file}: ${buf.length} B raw, ${gzipSync(buf).length} B gzip`);
}
const gz = gzipSync(source).length;
if (gz > BUDGET_JS_GZIP) fail(`index.js gzip ${gz} B exceeds budget ${BUDGET_JS_GZIP} B`);
console.log(`OK: classic script, JS gzip ${gz} B <= ${BUDGET_JS_GZIP} B`);
