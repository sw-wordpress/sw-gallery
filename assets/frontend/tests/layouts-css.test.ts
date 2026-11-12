import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const css = readFileSync(resolve(import.meta.dirname, '../src/layouts.css'), 'utf8');
const gridLayout = readFileSync(
  resolve(import.meta.dirname, '../../../packages/wordpress/src/Rendering/Layout/GridLayout.php'),
  'utf8',
);
/** CSS without comments, as the rules are what matters. */
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

describe('layouts.css', () => {
  it('namespaces every selector with .swg-', () => {
    const all = selectors();
    expect(all.length).toBeGreaterThan(10);
    for (const selector of all) expect(selector).toMatch(/^\.swg-/);
  });

  it('uses logical properties only', () => {
    expect(rules).not.toMatch(/(^|[\s;{])(margin|padding|border)-(left|right)\s*:/);
    expect(rules).not.toMatch(
      /(^|[\s;{])(left|right|width|height|max-width|max-height|min-width|min-height)\s*:/,
    );
    expect(rules).not.toMatch(/text-align\s*:\s*(left|right)/);
  });

  it('reads the documented custom properties', () => {
    for (const name of [
      '--swg-columns',
      '--swg-columns-sm',
      '--swg-columns-md',
      '--swg-columns-lg',
      '--swg-columns-xl',
      '--swg-gap',
      '--swg-aspect',
      '--swg-radius',
      '--swg-caption-color',
      '--swg-caption-size',
      '--swg-focus-ring',
      '--swg-hover-transform',
    ]) {
      expect(rules).toContain(`var(${name}`);
      expect(css).toContain(name);
    }
  });

  it('respects reduced motion and shows a focus ring', () => {
    expect(rules).toContain('prefers-reduced-motion: no-preference');
    const motion = rules.slice(rules.indexOf('prefers-reduced-motion'));
    expect(rules.slice(0, rules.indexOf('prefers-reduced-motion'))).not.toMatch(/transition/);
    expect(motion).toContain('transition');
    expect(rules).toContain(':focus-visible');
  });

  it('renders the list without markers and styles captions and pagination', () => {
    expect(rules).toMatch(/\.swg-items\s*\{[^}]*list-style:\s*none/);
    expect(rules).toContain('.swg-caption');
    expect(rules).toContain('.swg-pagination__current');
    expect(rules).toContain('.swg-pagination__disabled');
  });

  it('uses the breakpoints of GridLayout exactly', () => {
    const cssWidths = [...rules.matchAll(/@media \(min-width:\s*(\d+)px\)/g)].map((m) =>
      Number(m[1]),
    );
    const phpWidths = [...gridLayout.matchAll(/'(?:sm|md|lg|xl)'\s*=>\s*(\d+),/g)].map((m) =>
      Number(m[1]),
    );
    expect(cssWidths).toEqual([576, 768, 1024, 1280]);
    expect(phpWidths).toEqual(cssWidths);
    expect(gridLayout).toContain('REFERENCE_WIDTH = 1200');
  });
});
