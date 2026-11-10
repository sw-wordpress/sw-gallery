import type { Page } from '@playwright/test';

/** Everything the browser reported while a page was in use. */
export interface PageMonitor {
  /** console errors/warnings and uncaught exceptions */
  problems: string[];
  /** requests that failed at network level or answered with HTTP >= 400 */
  failedRequests: string[];
  /** every requested URL */
  requests: string[];
}

export function monitorPage(page: Page): PageMonitor {
  const monitor: PageMonitor = { problems: [], failedRequests: [], requests: [] };
  page.on('console', (message) => {
    if (message.type() === 'error' || message.type() === 'warning') {
      monitor.problems.push(`${message.type()}: ${message.text()} (${message.location().url})`);
    }
  });
  page.on('pageerror', (error) => monitor.problems.push(`pageerror: ${error.message}`));
  page.on('request', (request) => monitor.requests.push(request.url()));
  page.on('requestfailed', (request) =>
    monitor.failedRequests.push(`${request.url()} ${request.failure()?.errorText ?? ''}`),
  );
  page.on('response', (response) => {
    if (response.status() >= 400) {
      monitor.failedRequests.push(`${response.status()} ${response.url()}`);
    }
  });
  return monitor;
}
