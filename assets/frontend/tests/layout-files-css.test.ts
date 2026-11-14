import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const read = (name: string): string =>
  readFileSync(resolve(import.meta.dirname, `../src/${name}`), 'utf8');
const strip = (css: string): string => css.replace(/\/\*[\s\S]*?\*\//g, '');

describe.each(['carousel.css', 'focus.css'])('%s', (name) => {
  const rules = strip(read(name));

  it('is imported by the merged stylesheet right after its header comment', () => {
    const layouts = read('layouts.css');
    const head = layouts.slice(layouts.indexOf('*/') + 2, layouts.indexOf('.swg-gallery {'));
    expect(head).toContain(`@import './${name}';`);
    expect(strip(layouts).trimStart().startsWith('@import')).toBe(true);
  });

  it('namespaces every selector with .swg- and keyframes with swg-', () => {
    for (const match of rules.matchAll(/([^{}]+)\{/g)) {
      const head = (match[1] ?? '').trim();
      if (head.startsWith('@keyframes')) expect(head).toMatch(/^@keyframes swg-/);
      if (head.startsWith('@')) continue;
      for (const selector of head.split(',')) expect(selector.trim()).toMatch(/^(\.swg-|from|to)/);
    }
  });

  it('uses logical properties only (RTL)', () => {
    expect(rules).not.toMatch(/(^|[\s;{])(margin|padding|border)-(left|right)\s*:/);
    expect(rules).not.toMatch(
      /(^|[\s;{])(left|right|width|height|max-width|max-height|min-width|min-height)\s*:/,
    );
    expect(rules).not.toMatch(/text-align\s*:\s*(left|right)/);
    // The build lowers `inset-inline-*` to :lang() based left/right rules that ignore `dir="rtl"`.
    expect(rules).not.toMatch(/inset-inline/);
  });

  it('keeps motion behind prefers-reduced-motion: no-preference', () => {
    const outside = rules.replace(
      /@media \(prefers-reduced-motion: no-preference\) \{[\s\S]*?\n\}\n/g,
      '',
    );
    expect(outside).not.toMatch(/(^|[\s;{])(transition|animation|scroll-behavior)\s*:/);
  });

  it('styles a visible focus ring', () => {
    expect(rules).toContain(':focus-visible');
    expect(rules).toContain('var(--swg-focus-ring');
  });
});

describe('carousel.css specifics', () => {
  const rules = strip(read('carousel.css'));
  it('uses native scroll snap on a contained scroller', () => {
    expect(rules).toMatch(/scroll-snap-type:\s*x mandatory/);
    expect(rules).toMatch(/scroll-snap-align:\s*start/);
    expect(rules).toMatch(/scroll-snap-align:\s*center/);
    expect(rules).toMatch(/overscroll-behavior-x:\s*contain/);
    expect(rules).toMatch(/overflow:\s*auto hidden/);
  });
  it('mirrors the breakpoints of the stylesheet for the slide height', () => {
    expect(
      [...rules.matchAll(/@media \(min-width:\s*(\d+)px\)/g)].map((m) => Number(m[1])),
    ).toEqual([576, 768, 1024, 1280]);
    expect(rules).toContain('--swg-carousel-height-xl');
  });
  it('keeps the focus on buttons that reach an end (aria-disabled, not disabled)', () => {
    expect(rules).toContain(".swg-carousel__btn[aria-disabled='true']");
  });
});

describe('focus.css specifics', () => {
  const rules = strip(read('focus.css'));
  it('shows one panel through :target without JavaScript and the class with it', () => {
    expect(rules).toContain(':has(.swg-anchor:target)');
    expect(rules).toContain('.swg-focus-active .swg-panel.swg-current');
    expect(rules).toMatch(/\.swg-anchor\s*\{[^}]*position:\s*fixed/);
  });
  it('never crops the stage image', () => {
    expect(rules).toMatch(/object-fit:\s*contain/);
    expect(rules).toContain('var(--swg-stage-ratio');
  });
  it('places the thumbnails beside the stage only in wide containers', () => {
    expect(rules).toMatch(/@container \(min-width:\s*600px\)/);
    expect(rules).toContain('.swg-thumbs-start .swg-focus__body');
  });
});
