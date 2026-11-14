import { mkdir, rm } from 'node:fs/promises';
import { chromium } from '@playwright/test';
import { AUTH_DIR, authFile, loadGeneratedEnv, requireEnv } from '../support/env';
import { login } from '../support/login';

/** Logs in once as administrator (the only user of the ZIP stack) and stores the session. */
export default async function globalSetup(): Promise<void> {
  loadGeneratedEnv();
  const baseURL = requireEnv('SWG_E2E_BASE_URL');
  await rm(AUTH_DIR, { recursive: true, force: true });
  await mkdir(AUTH_DIR, { recursive: true });
  const browser = await chromium.launch();
  try {
    const context = await browser.newContext();
    await login(await context.newPage(), baseURL, 'administrator');
    await context.storageState({ path: authFile('administrator') });
    await context.close();
  } finally {
    await browser.close();
  }
}
