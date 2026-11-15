import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const dir = resolve(import.meta.dirname, '../src');
const layouts = readFileSync(resolve(dir, 'layouts.css'), 'utf8');
const strip = (css: string): string => css.replace(/\/\*[\s\S]*?\*\//g, '');
const read = (name: string): string => strip(readFileSync(resolve(dir, name), 'utf8'));

describe.each([
  ['scattered.css', 'swg-layout-scattered'],
  ['bubbles.css', 'swg-layout-bubbles'],
])('%s', (name, layoutClass) => {
  const rules = read(name);

  it('is imported once by the merged stylesheet', () => {
    expect([...layouts.matchAll(new RegExp(`^@import './${name}';$`, 'gm'))]).toHaveLength(1);
  });

  it('prefixes every selector with the layout class and keyframes with swg-', () => {
    for (const match of rules.matchAll(/([^{}]+)\{/g)) {
      const head = (match[1] ?? '').trim();
      if (head.startsWith('@keyframes')) expect(head).toMatch(/^@keyframes swg-/);
      if (head.startsWith('@')) continue;
      for (const selector of head.split(',')) {
        const s = selector.trim();
        if (s === 'from' || s === 'to') continue;
        expect(s).toMatch(new RegExp(`^\\.swg-gallery\\.${layoutClass}`));
      }
    }
  });

  it('uses logical properties and never the lowered inset-inline or :dir()', () => {
    expect(rules).not.toMatch(/(^|[\s;{])(margin|padding|border)-(left|right)\s*:/);
    expect(rules).not.toMatch(
      /(^|[\s;{])(left|right|width|height|max-width|max-height|min-width|min-height)\s*:/,
    );
    expect(rules).not.toMatch(/inset-inline/);
    expect(rules).not.toContain(':dir(');
  });

  it('keeps all motion behind prefers-reduced-motion: no-preference', () => {
    const outside = rules.replace(
      /@media \(prefers-reduced-motion: no-preference\) \{[\s\S]*?\n\}\n/g,
      '',
    );
    expect(outside).not.toMatch(/(^|[\s;{])(transition|animation)\s*:/);
  });

  it('styles a visible focus ring and raises hovered or focused items', () => {
    expect(rules).toContain(':focus-visible');
    expect(rules).toContain('var(--swg-focus-ring');
    expect(rules).toMatch(/\.swg-item:hover,\s*\n[^{]*:has\(> \.swg-link:focus-visible\)/);
  });
});

describe('scattered.css specifics', () => {
  const rules = read('scattered.css');
  it('transforms the print from the planner numbers and straightens it on hover and focus', () => {
    for (const name of ['--swg-rot', '--swg-dx', '--swg-dy', '--swg-z', '--swg-overlap']) {
      expect(rules).toContain(`var(${name}`);
    }
    expect(rules).toMatch(/rotate\(0deg\)/);
  });
  it('reduces tilt and overlap on touch screens and keeps two columns in narrow containers', () => {
    expect(rules).toMatch(/@media \(hover: none\)[\s\S]*--swg-k: 0\.6;[\s\S]*--swg-ok: 0\.5/);
    expect(rules).toMatch(
      /@container swg-scattered \(max-width: 479px\)[\s\S]*--swg-cols: 2;[\s\S]*--swg-k: 0\.5/,
    );
  });
  it('pads the list by the bleed so rotated prints never create horizontal scroll', () => {
    expect(rules).toContain('var(--swg-bleed-x');
    expect(rules).toContain('padding-inline: calc(');
  });
  it('mirrors the breakpoints of the stylesheet', () => {
    expect(
      [...rules.matchAll(/@media \(min-width:\s*(\d+)px\)/g)].map((m) => Number(m[1])),
    ).toEqual([576, 768, 1024, 1280]);
  });
});

describe('bubbles.css specifics', () => {
  const rules = read('bubbles.css');
  it('draws circles and positions them like the mosaic variants', () => {
    expect(rules).toMatch(/border-radius:\s*50%/);
    for (const query of ['480px', '720px', '1024px'])
      expect(rules).toContain(`@container swg-bubbles (min-width: ${query})`);
    for (const key of ['n', 'm', 'w']) expect(rules).toContain(`--swg-${key}-ar`);
  });
  it('floats only with the class and only without a reduced-motion preference', () => {
    expect(rules).toMatch(
      /@media \(prefers-reduced-motion: no-preference\)[\s\S]*\.swg-float \.swg-item \{\s*animation:/,
    );
    expect(rules).toContain('@keyframes swg-bubble-float');
  });
  it('pops a bubble forward on hover and focus', () => {
    expect(rules).toMatch(
      /\.swg-link:hover,\s*\n[^{]*\.swg-link:focus-visible \{\s*transform: scale\(1\.06\)/,
    );
  });
});
