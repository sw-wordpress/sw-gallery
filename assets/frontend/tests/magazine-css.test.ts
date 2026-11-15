import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const dir = resolve(import.meta.dirname, '../src');
const layouts = readFileSync(resolve(dir, 'layouts.css'), 'utf8');
const css = readFileSync(resolve(dir, 'magazine.css'), 'utf8');
const phpLayout = readFileSync(
  resolve(
    import.meta.dirname,
    '../../../packages/wordpress/src/Rendering/Layout/MagazineLayout.php',
  ),
  'utf8',
);
const templates = readFileSync(
  resolve(
    import.meta.dirname,
    '../../../packages/core/src/Domain/Layout/Magazine/RowTemplates.php',
  ),
  'utf8',
);
const fixture = readFileSync(resolve(import.meta.dirname, '../fixtures/magazine.html'), 'utf8');
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

describe('magazine.css', () => {
  it('is imported by the merged stylesheet before any rule', () => {
    const imports = [...layouts.matchAll(/^@import '\.\/magazine\.css';$/gm)];
    expect(imports).toHaveLength(1);
    const before = layouts.slice(0, imports[0].index).replace(/\/\*[\s\S]*?\*\//g, '');
    expect(before.replace(/@import[^;]+;/g, '').trim()).toBe('');
  });

  it('namespaces every selector', () => {
    const all = selectors();
    expect(all.length).toBeGreaterThan(20);
    for (const selector of all) expect(selector).toMatch(/^\.swg-gallery\.swg-layout-magazine\b/);
  });

  it('uses logical properties only and never inset-inline or :dir()', () => {
    expect(rules).not.toMatch(/(^|[\s;{])(margin|padding|border)-(left|right)\s*:/);
    expect(rules).not.toMatch(
      /(^|[\s;{])(left|right|top|bottom|width|height|max-width|min-width)\s*:/,
    );
    expect(rules).not.toContain('inset-inline');
    expect(rules).not.toContain(':dir(');
  });

  it('stacks by default and switches to template rows by container width', () => {
    expect(rules).toMatch(/container:\s*swg-magazine\s*\/\s*inline-size/);
    expect(
      [...rules.matchAll(/@container swg-magazine \(min-width: (\d+)px\)/g)].map((m) => m[1]),
    ).toEqual(['600']);
    const outside = rules.replace(/@container[^{]+\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/g, '');
    expect(outside).toMatch(/grid-template-columns:\s*repeat\(2/);
    expect(outside).not.toContain('grid-template-areas');
    expect(outside).not.toContain('position: absolute');
  });

  it('has a rule for every template of the core catalogue', () => {
    const ids = [...templates.matchAll(/new RowTemplate\('([a-z-]+)'/g)].map((m) => m[1]);
    expect(ids.length).toBeGreaterThanOrEqual(8);
    for (const id of ids) expect(rules).toContain(`.swg-tpl-${id} > .swg-row-items`);
  });

  it('reads exactly the custom properties the PHP view writes', () => {
    for (const name of ['--swg-slot', '--swg-fx', '--swg-fy', '--swg-gap', '--swg-mag-h']) {
      expect(rules).toContain(name);
    }
    for (const bp of ['sm', 'md', 'lg', 'xl']) expect(rules).toContain(`--swg-mag-h-${bp}`);
    expect(phpLayout).toContain("'--swg-mag-h'");
    expect(phpLayout).toContain("'--swg-slot:'");
    expect(phpLayout).toContain('--swg-fx:%s%%;--swg-fy:%s%%');
  });

  it('keeps a visible inner focus ring and hides captions only in row mode', () => {
    expect(rules).toMatch(/\.swg-link:focus-visible::after\s*\{[^}]*outline-offset:\s*-\d+px/);
    expect(rules).toMatch(/\.swg-caption\s*\{\s*display:\s*none/);
    expect(rules.indexOf('.swg-caption')).toBeGreaterThan(rules.indexOf('@container'));
  });

  it('fixture shows every template and an RTL gallery', () => {
    const ids = [...templates.matchAll(/new RowTemplate\('([a-z-]+)'/g)].map((m) => m[1]);
    const used = new Set([...fixture.matchAll(/swg-tpl-([a-z-]+)"/g)].map((m) => m[1]));
    expect(used.size).toBeGreaterThanOrEqual(12);
    for (const id of used) expect(ids).toContain(id);
    expect(fixture).toContain('swg-layout-magazine');
    expect(fixture).toContain('dir="rtl"');
  });
});
