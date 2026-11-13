import { defineConfig, devices } from '@playwright/test';
import { loadGeneratedEnv } from './support/env';

loadGeneratedEnv();

export default defineConfig({
  testDir: './tests',
  globalSetup: './global-setup.ts',
  outputDir: './test-results',
  forbidOnly: !!process.env.CI,
  // One retry: Chromium aborts requests with ERR_NETWORK_CHANGED when the host's network interfaces change (Docker veth churn on busy
  // machines). Retried tests are reported as flaky by Playwright; there is no sleep-based workaround.
  retries: 2,
  // One WordPress container serves all workers; ~10 parallel workers made page loads fail on hosts with churning
  // network interfaces (net::ERR_NETWORK_CHANGED), even after the retry. Three is a good trade-off.
  workers: 3,
  fullyParallel: true,
  reporter: [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
  timeout: 30_000,
  expect: { timeout: 10_000 },
  use: {
    baseURL: process.env.SWG_E2E_BASE_URL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
    viewport: { width: 1280, height: 900 },
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
