import { expect } from '@playwright/test';
import type { Page } from '@playwright/test';

const root = (page: Page) => page.locator('#swg-admin-root');

async function expectViewportBounds(page: Page, height: number): Promise<void> {
  const editor = root(page).locator('.swg-ed');
  await expect
    .poll(async () => {
      const box = await editor.boundingBox();
      return box === null ? Infinity : Math.abs(box.y + box.height - height);
    })
    .toBeLessThanOrEqual(2);
}

/** Shared by the real WordPress E2E and the local actual-app/mocked-REST harness. */
export async function expectDesktopWorkspace(page: Page): Promise<void> {
  const head = root(page).locator('.swg-ed__head');
  const side = root(page).locator('.swg-ed__side');
  const grid = root(page).getByRole('listbox');
  await expect(root(page).locator('.swg-shell')).toHaveClass(/swg-shell--collapsed/);
  await expectViewportBounds(page, 1000);
  expect((await head.boundingBox())!.height).toBeLessThanOrEqual(60);
  expect((await side.boundingBox())!.width).toBe(320);
  expect((await grid.boundingBox())!.height).toBeGreaterThan(500);
  await side.getByText('Layout & settings', { exact: true }).click();
  await expect(
    root(page).getByRole('radio', { name: 'Layout & settings', exact: true }),
  ).toBeChecked();
  await expect(side.getByRole('combobox', { name: 'Aspect', exact: true })).toBeVisible();

  // Compact Grid settings fit at 1000 px (hover and page-size fields included).
  await expect.poll(() => side.evaluate((el) => el.scrollHeight - el.clientHeight)).toBe(0);

  // A short desktop window gives the same real settings a deterministic reason to overflow.
  await page.setViewportSize({ width: 1500, height: 600 });
  await expectViewportBounds(page, 600);
  await expect.poll(() => side.evaluate((el) => el.scrollHeight > el.clientHeight)).toBe(true);
  const stationary = async () => ({
    head: await head.boundingBox(),
    main: await root(page).locator('.swg-ed__main').boundingBox(),
    grid: await grid.boundingBox(),
    page: await page.evaluate(() => ({ x: window.scrollX, y: window.scrollY })),
    scrolls: await root(page)
      .locator('#swg-main, .swg-ed__main, .swg-igrid')
      .evaluateAll((elements) => elements.map((el) => ({ x: el.scrollLeft, y: el.scrollTop }))),
  });
  const before = await stationary();
  await side.hover();
  await page.mouse.wheel(0, 600);
  await expect.poll(() => side.evaluate((el) => el.scrollTop)).toBeGreaterThan(0);
  expect(await stationary()).toEqual(before);
  await expect(side.getByRole('combobox', { name: 'Aspect', exact: true })).toBeInViewport();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    ),
  ).toBe(0);

  await page.setViewportSize({ width: 1500, height: 1000 });
  await expectViewportBounds(page, 1000);
  await expect.poll(() => side.evaluate((el) => el.scrollTop)).toBe(0);
}

export async function expectMobileWorkspace(page: Page): Promise<void> {
  await page.setViewportSize({ width: 375, height: 800 });
  const main = root(page).locator('.swg-ed__main');
  const side = root(page).locator('.swg-ed__side');
  await expect
    .poll(async () => {
      const mainBox = (await main.boundingBox())!;
      const sideBox = (await side.boundingBox())!;
      return sideBox.y - mainBox.y - mainBox.height;
    })
    .toBeGreaterThanOrEqual(-1);
  const save = root(page).getByRole('button', { name: 'Save', exact: true });
  await save.scrollIntoViewIfNeeded();
  await expect(save).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    ),
  ).toBe(0);
}
