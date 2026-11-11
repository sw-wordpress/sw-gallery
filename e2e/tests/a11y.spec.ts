import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { authFile, PLUGIN_PAGE } from '../support/env';

test.use({ storageState: authFile('administrator') });

/** Rule ids waived for serious/critical violations; every entry needs a justification. Currently none. */
const WAIVED: Record<string, string> = {};

test('axe: no serious or critical violations inside the app root', async ({ page }, testInfo) => {
  await page.goto(PLUGIN_PAGE);
  await expect(page.locator('#swg-admin-root .swg-shell')).toBeVisible();

  const results = await new AxeBuilder({ page })
    .include('#swg-admin-root')
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'])
    .analyze();

  await testInfo.attach('axe-violations.json', {
    body: JSON.stringify(results.violations, null, 2),
    contentType: 'application/json',
  });
  for (const violation of results.violations) {
    console.log(
      `axe [${violation.impact ?? 'n/a'}] ${violation.id}: ${violation.help} (${violation.nodes.length} node(s))`,
    );
  }

  for (const v of results.violations.filter((x) => x.id in WAIVED)) {
    console.log(`axe WAIVED ${v.id}: ${WAIVED[v.id]}`);
  }
  const blocking = results.violations.filter(
    (v) => (v.impact === 'serious' || v.impact === 'critical') && !(v.id in WAIVED),
  );
  expect(
    blocking.map((v) => `${v.id}: ${v.help}`),
    'serious/critical axe violations',
  ).toEqual([]);
});
