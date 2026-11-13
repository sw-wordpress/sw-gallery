import type { ApiClient } from './client';

/** `GET /status` (docs/rest-api.md "Status"). The API sends no paths, hosts, secrets or SQL. */
export type CountedGalleryStatus = 'draft' | 'pending' | 'publish' | 'private' | 'future' | 'trash';

export interface StatusError {
  /** ISO 8601 UTC. */
  time: string;
  code: string;
  context: Record<string, string | number | boolean | null>;
}

export interface StatusHint {
  key: string;
  message: string;
}

export interface StatusReport {
  schema: {
    stored: number;
    current: number;
    migrationPending: boolean;
    itemTablePresent: boolean;
  };
  counts: {
    galleries: Partial<Record<CountedGalleryStatus, number>>;
    itemRows: { count: number; capped: boolean };
    /** `count: null` when the scan was skipped; `complete: false` when it stopped early. */
    orphanRelations: { count: number | null; complete: boolean };
  };
  recentErrors: StatusError[];
  versions: {
    plugin: string;
    wordpress: string;
    php: string;
    database: { type: string; version: string };
  };
  multisite: boolean;
  cache: { state: string };
  /** The build manifest is valid. */
  adminBuild: boolean;
  hints: StatusHint[];
}

export function createStatusApi(client: ApiClient) {
  return {
    get(signal?: AbortSignal): Promise<StatusReport> {
      return client.get<StatusReport>('status', undefined, signal);
    },
  };
}

export type StatusApi = ReturnType<typeof createStatusApi>;
