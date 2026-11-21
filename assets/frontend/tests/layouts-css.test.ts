import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const css = readFileSync(resolve(import.meta.dirname, '../src/layouts.css'), 'utf8');
const gridLayout = readFileSync(
  resolve(import.meta.dirname, '../../../packages/wordpress/src/Rendering/Layout/GridLayout.php'),
  'utf8',
);
const phpFile = (name: string): string =>
  readFileSync(
    resolve(import.meta.dirname, `../../../packages/wordpress/src/Rendering/${name}.php`),
    'utf8',
  );
const responsiveSetting = phpFile('View/ResponsiveSetting');
const justifiedLayout = phpFile('Layout/JustifiedLayout');
const masonryLayout = phpFile('Layout/MasonryLayout');
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
      '--swg-thumb-radius',
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

  it('keeps the breakpoints of the shared responsive helper identical to the media queries', () => {
    const phpWidths = [...responsiveSetting.matchAll(/'(?:sm|md|lg|xl)'\s*=>\s*(\d+),/g)].map((m) =>
      Number(m[1]),
    );
    expect(phpWidths).toEqual([576, 768, 1024, 1280]);
    // Every breakpoint block also switches the justified row height and the masonry columns.
    for (const [index, width] of [576, 768, 1024, 1280].entries()) {
      const block =
        rules.slice(rules.indexOf(`@media (min-width: ${width}px)`)).split('\n}\n')[0] ?? '';
      expect(block).toContain('.swg-layout-masonry > .swg-items');
      expect(block).toContain(`--swg-col-${index + 1}`);
      expect(block).toContain(`--swg-rh: var(--swg-rh-${index + 1})`);
    }
  });

  it('styles the justified layout with flex rows driven by the per item ratio', () => {
    expect(rules).toMatch(/\.swg-layout-justified > \.swg-items\s*\{[^}]*display:\s*flex/);
    expect(rules).toMatch(/flex-grow:\s*calc\(var\(--swg-ar/);
    expect(rules).toMatch(/flex-basis:\s*calc\(var\(--swg-ar[^;]*var\(--swg-rh\)/);
    expect(rules).toMatch(/aspect-ratio:\s*var\(--swg-ar/);
    // The last-row filler exists unless `lastRow` is `justify`.
    expect(rules).toMatch(/\.swg-layout-justified:not\(\.swg-last-justify\) > \.swg-items::after/);
    expect(rules).toMatch(/flex:\s*1000000 1 0/);
    for (const name of ['--swg-row-height', '--swg-row-height-xl', '--swg-ar']) {
      expect(rules).toContain(`var(${name}`);
    }
    expect(justifiedLayout).toContain("'swg-gallery swg-layout-justified swg-last-justify'");
    expect(justifiedLayout).toContain('--swg-row-height');
  });

  it('styles masonry as a grid that the script packs with 1px rows', () => {
    expect(rules).toMatch(/\.swg-layout-masonry > \.swg-items\s*\{[^}]*align-items:\s*start/);
    expect(rules).toMatch(/\.swg-masonry-active > \.swg-items\s*\{[^}]*grid-auto-rows:\s*1px/);
    expect(rules).toMatch(/\.swg-masonry-active > \.swg-items\s*\{[^}]*row-gap:\s*0/);
    expect(masonryLayout).toContain('class="swg-gallery swg-layout-masonry"');
    expect(masonryLayout).toContain('--swg-columns');
  });

  it('never overrides the attribute-derived image ratio unless a fixed aspect is set', () => {
    // An author `aspect-ratio: auto` (or a fallback of it) would disable the width/height attribute ratio: layout shift.
    expect(rules).not.toMatch(/aspect-ratio:\s*(auto|var\(--swg-aspect,)/);
    expect(rules).toMatch(
      /\.swg-gallery\[style\*='--swg-aspect'\] \.swg-link img\s*\{[^}]*aspect-ratio:\s*var\(--swg-aspect\)/,
    );
  });

  it('defines no layout motion beyond the hover transform and the hover texts', () => {
    // thumbnail transform, hover-text overlay (both under no-preference) and `transition: none` for touch devices
    expect(rules.match(/transition/g)?.length).toBe(3);
    expect(rules).not.toMatch(/animation/);
  });

  it('crops every thumbnail around the per-image focal point (centre by default)', () => {
    expect(rules).toMatch(/object-position: var\(--swg-fx, 50%\) var\(--swg-fy, 50%\)/);
  });

  it('slides and fades the hover texts only without reduced-motion preference and shows them on touch devices', () => {
    const motion =
      rules.match(/@media \(prefers-reduced-motion: no-preference\) \{[\s\S]*?\n\}\n/g) ?? [];
    expect(motion.join('')).toContain('translateY(100%)');
    expect(motion.join('')).toContain('translateY(-100%)');
    expect(rules).toMatch(/@media \(hover: none\) \{[\s\S]*?\.swg-hover \{[\s\S]*?opacity: 1/);
  });
});
