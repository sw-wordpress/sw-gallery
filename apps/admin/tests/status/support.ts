import { vi } from 'vitest';
import type { StatusApi, StatusReport } from '../../src/api/status';

export function makeStatus(overrides: Partial<StatusReport> = {}): StatusReport {
  return {
    schema: {
      stored: 3,
      current: 3,
      migrationPending: false,
      newerThanCode: false,
      itemTablePresent: true,
    },
    counts: {
      galleries: { draft: 2, pending: 0, publish: 5, private: 1, future: 0, trash: 3 },
      itemRows: { count: 120, capped: false },
      orphanRelations: { count: 0, complete: true },
    },
    recentErrors: [],
    versions: {
      plugin: '1.0.0',
      wordpress: '6.8.1',
      php: '8.3.4',
      database: { type: 'MariaDB', version: '11.4.2' },
    },
    multisite: false,
    cache: { state: 'database' },
    adminBuild: true,
    hints: [
      {
        key: 'export_limitation',
        message: 'Galleries are not exported in 1.0. Attachment IDs change on import.',
      },
    ],
    ...overrides,
  };
}

export function fakeStatusApi(report: StatusReport = makeStatus()) {
  return { get: vi.fn<StatusApi['get']>(async () => report) };
}
