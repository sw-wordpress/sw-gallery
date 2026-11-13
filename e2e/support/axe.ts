import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';

export interface AxeOptions {
  /** Selectors left out of the scan (e.g. the preview iframe: its content is a separate document). */
  exclude?: string[];
  skipContrast?: boolean;
}

/** Serious and critical axe violations (WCAG 2.2 AA + best practice) inside `include`; all findings are logged. */
export async function seriousViolations(
  page: Page,
  include: string,
  options: AxeOptions = {},
): Promise<string[]> {
  const builder = new AxeBuilder({ page }).include(include);
  for (const selector of options.exclude ?? []) builder.exclude(selector);
  if (options.skipContrast === true) builder.disableRules('color-contrast');
  const results = await builder
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'])
    .analyze();
  for (const v of results.violations) {
    console.log(
      `axe [${v.impact ?? 'n/a'}] ${v.id}: ${v.help} (${v.nodes.length} node(s)) in ${include}: ${v.nodes
        .map((n) => `${n.target.join(' ')} ${n.html.slice(0, 200)}`)
        .join(' | ')}`,
    );
  }
  return results.violations
    .filter((v) => v.impact === 'serious' || v.impact === 'critical')
    .map((v) => `${v.id}: ${v.help}`);
}
