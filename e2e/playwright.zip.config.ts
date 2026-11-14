import { defineConfig, devices } from '@playwright/test';
import { loadGeneratedEnv } from './support/env';

// Smoke run against a WordPress that got the release ZIP installed with `wp plugin install` (bin/test-zip-install.sh).
// Separate from playwright.config.ts: the normal suite (testDir ./tests) never picks these specs up.
loadGeneratedEnv();

export default defineConfig({
  testDir: './zip',
  globalSetup: './zip/global-setup.ts',
  outputDir: './test-results/zip',
  forbidOnly: !!process.env.CI,
  // Same host effect as in playwright.config.ts: Chromium may abort requests with ERR_NETWORK_CHANGED.
  retries: 2,
  workers: 1,
  reporter: [['list'], ['html', { outputFolder: 'playwright-report/zip', open: 'never' }]],
  timeout: 60_000,
  expect: { timeout: 10_000 },
  use: {
    baseURL: process.env.SWG_E2E_BASE_URL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    viewport: { width: 1280, height: 900 },
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
