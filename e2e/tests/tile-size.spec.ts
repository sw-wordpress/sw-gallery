import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { authFile, PLUGIN_PAGE } from '../support/env';
import { GalleryFixtures, tinyPng, uniquePrefix } from '../support/galleries';
import type { GalleryRecord } from '../support/galleries';
import { shot } from '../support/screenshots';

/**
 * Items grid tile size (FU-31): the control picks the minimum tile width, the virtualised grid computes columns from
 * it and the container width, tiles grow to fill the row, nothing scrolls horizontally.
 */
const PREFIX = uniquePrefix('tiles');
const COUNT = 40;
test.use({ storageState: authFile('administrator') });

let admin: GalleryFixtures;
let gallery: GalleryRecord;

test.beforeAll(async ({ browser, baseURL }) => {
  const context = await browser.newContext({ storageState: authFile('administrator'), baseURL });
  admin = await GalleryFixtures.forPage(await context.newPage());
  gallery = await admin.create({ title: `${PREFIX} gallery` });
  const ids: number[] = [];
  for (let n = 0; n < COUNT; n += 1) {
    const hue = (n * 37) % 255;
    ids.push((await admin.uploadImage(`${PREFIX} ${n}`, tinyPng([hue, 120, 255 - hue]))).id);
  }
  await admin.assign(gallery.id, ids);
});

test.afterAll(async () => {
  await admin.purge(PREFIX);
  await admin.purgeMedia(PREFIX);
});

const root = (page: Page): Locator => page.locator('#swg-admin-root');
const grid = (page: Page): Locator => root(page).getByRole('listbox');
const size = (page: Page, label: string): Locator =>
  root(page).getByRole('group', { name: 'Thumbnail size' }).getByRole('button', { name: label });

async function open(page: Page): Promise<void> {
  await page.goto(`${PLUGIN_PAGE}#/galleries/${gallery.id}`);
  await expect(grid(page).getByRole('option').first()).toBeVisible();
}

/** Tiles in the first row (same top edge) and how far the row reaches into the container. */
async function firstRow(page: Page): Promise<{ columns: number; width: number; fill: number }> {
  return grid(page).evaluate((listbox) => {
    const scroller = listbox as HTMLElement;
    const tiles = Array.from(listbox.querySelectorAll<HTMLElement>('[role="option"]'));
    const first = tiles[0]?.getBoundingClientRect();
    if (first === undefined) return { columns: 0, width: 0, fill: 0 };
    const row = tiles
      .map((t) => t.getBoundingClientRect())
      .filter((r) => Math.abs(r.top - first.top) < 1);
    const box = scroller.getBoundingClientRect();
    const last = row[row.length - 1] as DOMRect;
    return { columns: row.length, width: first.width, fill: (box.right - last.right) / box.width };
  });
}

async function noHorizontalScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => {
    const doc = document.scrollingElement as HTMLElement;
    const scroller = document.querySelector<HTMLElement>(
      '#swg-admin-root [role="listbox"]',
    ) as HTMLElement;
    return {
      page: doc.scrollWidth - doc.clientWidth,
      grid: scroller.scrollWidth - scroller.clientWidth,
    };
  });
  expect(overflow.page).toBeLessThanOrEqual(0);
  expect(overflow.grid).toBeLessThanOrEqual(0);
}

for (const [width, height] of [
  [1920, 1080],
  [2560, 1300],
] as const) {
  test(`${width}px: the size setting changes tile count and size, rows stay filled`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height });
    await open(page);
    await expect(size(page, 'Medium thumbnails')).toHaveAttribute('aria-pressed', 'true');

    const medium = await firstRow(page);
    await size(page, 'Small thumbnails').click();
    await expect(size(page, 'Small thumbnails')).toHaveAttribute('aria-pressed', 'true');
    await expect.poll(async () => (await firstRow(page)).columns).toBeGreaterThan(medium.columns);
    const small = await firstRow(page);
    await shot(page, `tile-size-${width}-small`);
    await noHorizontalScroll(page);

    await size(page, 'Large thumbnails').click();
    await expect.poll(async () => (await firstRow(page)).columns).toBeLessThan(medium.columns);
    const large = await firstRow(page);
    await shot(page, `tile-size-${width}-large`);
    await noHorizontalScroll(page);

    expect(small.width).toBeLessThan(medium.width);
    expect(large.width).toBeGreaterThan(medium.width);
    // Tiles grow to fill the row: no leftover space beyond the grid gap on the right.
    for (const row of [small, medium, large]) expect(row.fill).toBeLessThan(0.02);
    // The wider the screen, the more tiles per row (the container is the limit, not a fixed count).
    if (width === 2560) expect(medium.columns).toBeGreaterThan(8);
    await shot(page, `tile-size-${width}-medium`);
  });
}

test('the choice is remembered per viewer, works by keyboard and keeps the first visible tile', async ({
  page,
}) => {
  await open(page);
  const small = size(page, 'Small thumbnails');
  await small.focus();
  await page.keyboard.press('Enter');
  await expect(small).toHaveAttribute('aria-pressed', 'true');
  await expect(root(page).locator('.swg-shell > [role="status"]')).toHaveText('Small thumbnails');
  await page.keyboard.press('Tab');
  await expect(size(page, 'Medium thumbnails')).toBeFocused();
  await page.keyboard.press('Space');
  await expect(size(page, 'Medium thumbnails')).toHaveAttribute('aria-pressed', 'true');
  await size(page, 'Large thumbnails').click();

  await page.reload();
  await expect(grid(page).getByRole('option').first()).toBeVisible();
  await expect(size(page, 'Large thumbnails')).toHaveAttribute('aria-pressed', 'true');
  expect(await page.evaluate(() => window.localStorage.getItem('swg-tile-size'))).toBe('large');

  // Scroll down, switch the size: the grid does not jump back to the top.
  await grid(page).evaluate((el) => {
    el.scrollTop = 1500;
  });
  const before = await grid(page).evaluate((el) => el.scrollTop);
  expect(before).toBeGreaterThan(0);
  await size(page, 'Medium thumbnails').click();
  await expect.poll(() => grid(page).evaluate((el) => el.scrollTop)).toBeGreaterThan(0);
});
