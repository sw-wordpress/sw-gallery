import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const dir = resolve(import.meta.dirname, '../src');
const layouts = readFileSync(resolve(dir, 'layouts.css'), 'utf8');
const css = readFileSync(resolve(dir, 'mosaic.css'), 'utf8');
const phpLayout = readFileSync(
  resolve(import.meta.dirname, '../../../packages/wordpress/src/Rendering/Layout/MosaicLayout.php'),
  'utf8',
);
const fixture = readFileSync(resolve(import.meta.dirname, '../fixtures/mosaic.html'), 'utf8');
/** CSS without comments. */
const rules = css.replace(/\/\*[\s\S]*?\*\//g, '');

function selectors(): string[] {
  const result: string[] = [];
  for (const match of rules.matchAll(/([^{}]+)\{/g)) {
    const head = match[1].trim();
    if (head.startsWith('@')) continue;
    result.push(...head.split(',').map((s) => s.trim()));
  }
  return result;
}

describe('mosaic.css', () => {
  it('is imported by the merged stylesheet before any rule (CSS requires imports first)', () => {
    const imports = [...layouts.matchAll(/^@import '\.\/mosaic\.css';$/gm)];
    expect(imports).toHaveLength(1);
    const before = layouts.slice(0, imports[0].index).replace(/\/\*[\s\S]*?\*\//g, '');
    expect(before.replace(/@import[^;]+;/g, '').trim()).toBe('');
  });

  it('namespaces every selector and never leaves .swg-gallery', () => {
    const all = selectors();
    expect(all.length).toBeGreaterThan(8);
    for (const selector of all) expect(selector).toMatch(/^\.swg-gallery\.swg-layout-mosaic\b/);
  });

  it('uses logical properties only', () => {
    expect(rules).not.toMatch(/(^|[\s;{])(margin|padding|border)-(left|right)\s*:/);
    expect(rules).not.toMatch(
      /(^|[\s;{])(left|right|top|bottom|width|height|max-width|min-width)\s*:/,
    );
    expect(rules).toContain('margin-inline-start');
    expect(rules).not.toContain('inset-inline');
    expect(rules).toContain('inset-block-start');
  });

  it('picks the variant by container width with a grid default (also without container queries)', () => {
    expect(rules).toMatch(/container:\s*swg-mosaic\s*\/\s*inline-size/);
    expect(
      [...rules.matchAll(/@container swg-mosaic \(min-width: (\d+)px\)/g)].map((m) => m[1]),
    ).toEqual(['480', '720', '1024']);
    // The default (outside any @container rule) is the two column grid.
    const outside = rules.replace(/@container[^{]+\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/g, '');
    expect(outside).toMatch(/grid-template-columns:\s*repeat\(2/);
    expect(outside).not.toContain('position: absolute');
    expect(rules).not.toMatch(/@media/);
  });

  it('reads exactly the custom properties the PHP view writes', () => {
    for (const variant of ['n', 'm', 'w']) {
      for (const part of ['x', 'y', 'w', 'h', 'ar']) {
        expect(rules).toContain(`--swg-${variant}-${part}`);
      }
    }
    expect(phpLayout).toContain(
      "'--swg-%1$s-x:%2$s;--swg-%1$s-y:%3$s;--swg-%1$s-w:%4$s;--swg-%1$s-h:%5$s;'",
    );
    expect(phpLayout).toContain("'--swg-%s-ar:%s;'");
    expect(rules).toContain('--swg-fx');
    expect(rules).toContain('--swg-fy');
    expect(rules).toContain('--swg-gap');
    expect(phpLayout).toContain('--swg-fx:%s%%;--swg-fy:%s%%;');
  });

  it('keeps a visible inner focus ring and hides captions only in plan mode', () => {
    expect(rules).toMatch(/\.swg-link:focus-visible::after\s*\{[^}]*outline-offset:\s*-\d+px/);
    expect(rules).toMatch(/\.swg-caption\s*\{\s*display:\s*none/);
    const inContainer = rules.indexOf('@container');
    expect(rules.indexOf('.swg-caption')).toBeGreaterThan(inContainer);
  });

  it('mirrors the container thresholds in PHP and the fixture markup', () => {
    expect(phpLayout).toContain("'(max-width: 479px) 50vw");
    expect(fixture).toContain('swg-layout-mosaic');
    expect(fixture).toMatch(/--swg-w-x:/);
    expect(fixture).toContain('dir="rtl"');
  });
});
