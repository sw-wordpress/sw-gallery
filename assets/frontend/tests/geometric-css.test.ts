import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const dir = resolve(import.meta.dirname, '../src');
const layouts = readFileSync(resolve(dir, 'layouts.css'), 'utf8');
const php = (name: string): string =>
  readFileSync(
    resolve(import.meta.dirname, `../../../packages/wordpress/src/Rendering/Layout/${name}.php`),
    'utf8',
  );
const strip = (css: string): string => css.replace(/\/\*[\s\S]*?\*\//g, '');

function selectors(rules: string): string[] {
  const result: string[] = [];
  for (const match of rules.matchAll(/([^{}]+)\{/g)) {
    const head = match[1].trim();
    if (head.startsWith('@')) continue;
    result.push(...head.split(',').map((s) => s.trim()));
  }
  return result;
}

const cases = [
  { id: 'honeycomb', file: 'honeycomb.css', layout: 'HoneycombLayout' },
  { id: 'jigsaw', file: 'jigsaw.css', layout: 'JigsawLayout' },
];

for (const { id, file, layout } of cases) {
  const rules = strip(readFileSync(resolve(dir, file), 'utf8'));
  const fixture = readFileSync(resolve(import.meta.dirname, `../fixtures/${id}.html`), 'utf8');

  describe(file, () => {
    it('is imported once by the merged stylesheet', () => {
      expect([...layouts.matchAll(new RegExp(`^@import '\\./${id}\\.css';$`, 'gm'))]).toHaveLength(
        1,
      );
    });

    it('namespaces every selector (an RTL ancestor may come first)', () => {
      const all = selectors(rules);
      expect(all.length).toBeGreaterThan(8);
      for (const selector of all) {
        expect(selector).toMatch(
          new RegExp(`^(\\[dir='rtl'\\] )?\\.swg-gallery\\.swg-layout-${id}\\b`),
        );
      }
    });

    it('stays within the lowered build target: no :dir(), no inset-inline, logical properties', () => {
      expect(rules).not.toContain(':dir(');
      expect(rules).not.toContain('inset-inline');
      expect(rules).not.toMatch(/(^|[\s;{])(margin|padding|border)-(left|right)\s*:/);
      expect(rules).not.toMatch(/(^|[\s;{])(left|right|top|bottom|width|height)\s*:/);
    });

    it('falls back to a two column grid below 480px container width (and without container queries)', () => {
      expect(rules).toMatch(new RegExp(`container:\\s*swg-${id}\\s*/\\s*inline-size`));
      expect(
        [...rules.matchAll(new RegExp(`@container swg-${id} \\(min-width: (\\d+)px\\)`, 'g'))].map(
          (m) => m[1],
        ),
      ).toEqual(['480']);
      const outside = rules.replace(
        /@container[^{]+\{(?:[^{}]*\{[^{}]*\}|[^{}]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\})*[^{}]*\}/g,
        '',
      );
      expect(outside).toMatch(/grid-template-columns:\s*repeat\(2/);
      expect(outside).not.toContain('clip-path');
    });

    it('uses the media queries of layouts.css for the column count', () => {
      expect([...rules.matchAll(/@media \(min-width: (\d+)px\)/g)].map((m) => m[1])).toEqual([
        '576',
        '768',
        '1024',
        '1280',
      ]);
      for (const n of [0, 1, 2, 3, 4]) expect(rules).toContain(`--swg-col-${n}`);
    });

    it('lifts with a drop-shadow on the item, shows a ring that follows the clip and respects reduced motion', () => {
      expect(rules).toMatch(/\.swg-item:hover\s*\{\s*filter:\s*drop-shadow/);
      expect(rules).toMatch(
        /\.swg-item:has\(\.swg-link:focus-visible\)\s*\{\s*filter:\s*drop-shadow\(3px 0 0 Highlight\)/,
      );
      expect(rules).toMatch(/@media \(prefers-reduced-motion: no-preference\)\s*\{[^@]*transition/);
      expect(rules).toMatch(/@media \(forced-colors: active\)/);
      expect(rules).toMatch(/\.swg-caption\s*\{\s*display:\s*none/);
    });

    it('ships a fixture with an RTL gallery that uses the markup of the PHP view', () => {
      expect(fixture).toContain(`swg-layout-${id}`);
      expect(fixture).toContain('dir="rtl"');
      expect(php(layout)).toContain(`swg-layout-${id}`);
    });
  });
}

describe('honeycomb.css contract with HoneycombLayout', () => {
  const rules = strip(readFileSync(resolve(dir, 'honeycomb.css'), 'utf8'));
  it('reads the row flags the PHP view writes and offsets by half a cell with a logical margin', () => {
    for (const n of [0, 1, 2, 3, 4]) expect(rules).toContain(`--swg-hs${n}`);
    expect(php('HoneycombLayout')).toContain("'--swg-hs%d:1;'");
    expect(rules).toContain('margin-inline-start: calc(var(--swg-hc-odd) * 50%)');
    expect(rules).toContain('calc(100% / (var(--swg-hc-cols) + 0.5))');
    expect(rules).toContain('polygon(50% 0, 100% 25%, 100% 75%, 50% 100%, 0 75%, 0 25%)');
    expect(rules).toContain('polygon(50% 0, 100% 50%, 50% 100%, 0 50%)');
    expect(rules).toMatch(/swg-honeycomb-diamond/);
    expect(php('HoneycombLayout')).toContain('swg-honeycomb-%1$s');
  });
});

describe('jigsaw.css contract with JigsawLayout', () => {
  const rules = strip(readFileSync(resolve(dir, 'jigsaw.css'), 'utf8'));
  it('reads the clip references and settings the PHP view writes', () => {
    for (const n of [0, 1, 2, 3, 4]) expect(rules).toContain(`--swg-cp${n}`);
    expect(php('JigsawLayout')).toContain("'--swg-cp%d:url(#%s-jc%d);'");
    for (const name of ['--swg-tab', '--swg-jr', '--swg-jaspect']) {
      expect(rules).toContain(name);
      expect(php('JigsawLayout')).toContain(name);
    }
    expect(rules).toContain('clip-path: var(--swg-jp)');
    expect(rules).toContain('swg-jigsaw-defs');
    expect(rules).not.toMatch(/display:\s*none;[^}]*\n[^}]*swg-jigsaw-defs/);
    expect(php('JigsawLayout')).toContain('swg-jigsaw-defs');
  });

  it('mirrors the pieces on right to left pages and keeps the image upright', () => {
    expect(rules).toContain("[dir='rtl'] .swg-gallery.swg-layout-jigsaw .swg-link img");
    expect(rules).toContain('transform: scaleX(-1)');
  });

  it('clips the overflow of the list so the oversized pieces never widen the page', () => {
    expect(rules).toMatch(/overflow:\s*clip/);
  });
});
