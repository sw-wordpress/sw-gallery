import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const dir = resolve(import.meta.dirname, '../src');
const layouts = readFileSync(resolve(dir, 'layouts.css'), 'utf8');
const css = readFileSync(resolve(dir, 'shattered.css'), 'utf8');
const phpLayout = readFileSync(
  resolve(
    import.meta.dirname,
    '../../../packages/wordpress/src/Rendering/Layout/ShatteredLayout.php',
  ),
  'utf8',
);
const fixture = readFileSync(resolve(import.meta.dirname, '../fixtures/shattered.html'), 'utf8');
/** CSS without comments. */
const rules = css.replace(/\/\*[\s\S]*?\*\//g, '');

function selectors(): string[] {
  const result: string[] = [];
  for (const match of rules.matchAll(/([^{}]+)\{/g)) {
    const head = (match[1] ?? '').trim();
    if (head.startsWith('@')) continue;
    result.push(...head.split(',').map((s) => s.trim()));
  }
  return result;
}

describe('shattered.css', () => {
  it('is imported by the merged stylesheet before any rule', () => {
    const imports = [...layouts.matchAll(/^@import '\.\/shattered\.css';$/gm)];
    expect(imports).toHaveLength(1);
    const before = layouts.slice(0, imports[0]?.index).replace(/\/\*[\s\S]*?\*\//g, '');
    expect(before.replace(/@import[^;]+;/g, '').trim()).toBe('');
  });

  it('namespaces every selector (an RTL ancestor selector may come first)', () => {
    const all = selectors();
    expect(all.length).toBeGreaterThan(12);
    for (const selector of all) {
      expect(selector).toMatch(/^(\[dir='rtl'\] )?\.swg-gallery\.swg-layout-shattered\b/);
    }
  });

  it('never uses what the lowered build target breaks', () => {
    expect(rules).not.toContain('inset-inline');
    expect(rules).not.toContain(':dir(');
    expect(rules).not.toMatch(/(^|[\s;{])(margin|padding|border)-(left|right)\s*:/);
    expect(rules).not.toMatch(/(^|[\s;{])(left|right|width|height)\s*:/);
    expect(rules).toContain('margin-inline-start');
    expect(rules).toContain('inset-block-start');
  });

  it('picks the variant by container width with a grid default (also without container queries)', () => {
    expect(rules).toMatch(/container:\s*swg-shattered\s*\/\s*inline-size/);
    const widths = [...rules.matchAll(/@container swg-shattered \(min-width: (\d+)px\)/g)].map(
      (m) => Number(m[1]),
    );
    expect(widths.slice(0, 3)).toEqual([480, 720, 1024]);
    const outside = rules.replace(/@container[^{]+\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/g, '');
    expect(outside).toMatch(/grid-template-columns:\s*repeat\(2/);
    expect(outside).not.toContain('position: absolute');
    expect(outside).not.toContain('clip-path');
  });

  it('reads exactly the custom properties the PHP view writes', () => {
    for (const variant of ['n', 'm', 'w']) {
      for (const part of ['x', 'y', 'w', 'h', 'ar', 'clip']) {
        expect(rules).toContain(`--swg-${variant}-${part}`);
      }
    }
    expect(phpLayout).toContain(
      "'--swg-%1$s-x:%2$s;--swg-%1$s-y:%3$s;--swg-%1$s-w:%4$s;--swg-%1$s-h:%5$s;--swg-%1$s-clip:%6$s;'",
    );
    expect(phpLayout).toContain("'--swg-%s-ar:%s;'");
    expect(phpLayout).toContain("'polygon('");
    expect(rules).toContain('--swg-fx');
    expect(phpLayout).toContain('--swg-fx:%s%%;--swg-fy:%s%%;');
  });

  it('clips the link and lets the pointer through the tile, so hit-testing follows the shard', () => {
    expect(rules).toMatch(/\.swg-item\s*\{[^}]*pointer-events:\s*none/);
    expect(rules).toMatch(
      /\.swg-link\s*\{[^}]*clip-path:\s*var\(--swg-clip\)[^}]*pointer-events:\s*auto/,
    );
  });

  it('lifts with a drop-shadow on the tile and keeps a visible focus indicator', () => {
    expect(rules).toMatch(/\.swg-item:hover\s*\{\s*filter:\s*drop-shadow/);
    expect(rules).toMatch(
      /\.swg-item:has\(\.swg-link:focus-visible\)\s*\{\s*filter:\s*drop-shadow/,
    );
    expect(rules).toMatch(/\.swg-link:focus-visible::after\s*\{[^}]*outline-offset:\s*-\d+px/);
    expect(rules).toMatch(/\.swg-caption\s*\{\s*display:\s*none/);
  });

  it('keeps motion behind prefers-reduced-motion: no-preference', () => {
    const outside = rules.replace(
      /@media \(prefers-reduced-motion: no-preference\) \{[\s\S]*$/,
      '',
    );
    expect(outside).not.toMatch(/(^|[\s;{])(transition|animation)\s*:/);
    expect(outside).not.toMatch(/transform:\s*scale\(var/);
    expect(rules).toMatch(/prefers-reduced-motion: no-preference/);
  });

  it('mirrors the whole list for RTL and the pictures back', () => {
    expect(rules).toMatch(/\[dir='rtl'\][^{]*\.swg-items\s*\{\s*transform:\s*scaleX\(-1\)/);
    expect(rules).toMatch(/\[dir='rtl'\][^{]*\.swg-link img\s*\{\s*transform:\s*scaleX\(-1\)/);
    expect(rules).toContain('direction: ltr');
  });

  it('mirrors the container thresholds in PHP and the fixture markup', () => {
    expect(phpLayout).toContain("'(max-width: 479px) 50vw");
    expect(fixture).toContain('swg-layout-shattered');
    expect(fixture).toMatch(/--swg-w-clip:\s*polygon\(/);
    expect(fixture).toContain('dir="rtl"');
  });
});
