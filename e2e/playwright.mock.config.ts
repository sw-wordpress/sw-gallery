import { defineConfig, devices } from '@playwright/test';

/** Actual admin app in its simulated wp-admin host; no WordPress, credentials or database. */
export default defineConfig({
  testDir: './mocked',
  outputDir: './test-results/mocked',
  forbidOnly: true,
  retries: 0,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL: 'http://127.0.0.1:5173',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    channel: 'chromium',
  },
  webServer: {
    command: 'npm --prefix ../apps/admin run dev -- --strictPort',
    url: 'http://127.0.0.1:5173',
    reuseExistingServer: false,
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
