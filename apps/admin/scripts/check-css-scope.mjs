// Verifies that every selector in the built CSS is namespaced (swg-*) or scoped to .swg-root,
// so no rule can restyle WordPress admin chrome. Reads the CSS files from the Vite manifest.
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import postcss from 'postcss';

const outDir =
    process.argv[2] ?? join(dirname(fileURLToPath(import.meta.url)), '../../../assets/admin');
const manifest = JSON.parse(readFileSync(join(outDir, '.vite/manifest.json'), 'utf8'));
const cssFiles = new Set();
for (const entry of Object.values(manifest)) {
    for (const css of entry.css ?? []) cssFiles.add(css);
    // With cssCodeSplit=false Vite lists the single stylesheet as its own `style.css` entry.
    if (entry.file.endsWith('.css')) cssFiles.add(entry.file);
}
if (cssFiles.size === 0) {
    console.error('No CSS file listed in the Vite manifest.');
    process.exit(1);
}

const allowed = [
    /\[data-v-[0-9a-f]+\]/,
    /^\.swg-/,
    /^\.swg-root/,
    /^\.q-portal/,
    /^:is\(\.swg-root /,
];
const bad = new Set();
for (const file of cssFiles) {
    const root = postcss.parse(readFileSync(join(outDir, file), 'utf8'));
    root.walkRules((rule) => {
        const parent = rule.parent;
        if (parent && parent.type === 'atrule' && /keyframes$/i.test(parent.name)) return;
        for (const sel of rule.selectors) {
            if (!allowed.some((re) => re.test(sel.trim()))) bad.add(sel.trim());
        }
    });
}
if (bad.size > 0) {
    console.error(`Unscoped selectors (${bad.size}):`);
    for (const s of [...bad].slice(0, 40)) console.error('  ' + s);
    process.exit(1);
}
console.log(`CSS scope OK: all selectors in ${[...cssFiles].join(', ')} are namespaced.`);
