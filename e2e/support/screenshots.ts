import type { Page } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { SCREENSHOT_DIR } from './env';

/** Review screenshot (viewport; `fullPage` for the whole document) in `e2e/screenshots/` (git-ignored, for manual review). */
export async function shot(page: Page, name: string, fullPage = false): Promise<void> {
  await mkdir(SCREENSHOT_DIR, { recursive: true });
  await page.screenshot({ path: resolve(SCREENSHOT_DIR, `${name}.png`), fullPage });
}

/** Stores the theme the app reads on start (`swg-theme`); call before the first navigation of a page. */
export async function preferTheme(page: Page, theme: 'light' | 'dark'): Promise<void> {
  await page.addInitScript((value) => {
    window.localStorage.setItem('swg-theme', value);
  }, theme);
}
