import type { StatusError, StatusReport } from '@/api/status';

/** `key=value` list of an error context; the API already sanitised it, this only flattens it. */
export function contextText(context: StatusError['context']): string {
  const parts = Object.entries(context).map(([key, value]) => `${key}=${String(value)}`);
  return parts.join(', ');
}

/**
 * Plain-text diagnostic summary for support requests. It contains only what `GET /status` sends
 * (counts, versions, codes): no paths, hosts, secrets or user data, and the page adds nothing
 * of its own. The text is deliberately English, so it reads the same in every support channel.
 */
export function buildSummary(report: StatusReport, generatedAt: Date = new Date()): string {
  const { schema, counts, versions } = report;
  const galleries = Object.entries(counts.galleries)
    .map(([status, count]) => `${status}=${count}`)
    .join(', ');
  const orphans = counts.orphanRelations;
  const orphanText =
    orphans.count === null
      ? 'not checked'
      : `${orphans.count}${orphans.complete ? '' : ' (incomplete scan, at least)'}`;
  const lines = [
    'sw-gallery diagnostic summary',
    `Generated: ${generatedAt.toISOString()}`,
    '',
    `Plugin: ${versions.plugin}`,
    `WordPress: ${versions.wordpress}${report.multisite ? ' (multisite)' : ''}`,
    `PHP: ${versions.php}`,
    `Database: ${versions.database.type} ${versions.database.version}`,
    '',
    `Schema: stored ${schema.stored}, current ${schema.current}, migration ${
      schema.migrationPending ? 'PENDING' : 'not pending'
    }, item table ${schema.itemTablePresent ? 'present' : 'MISSING'}`,
    `Galleries: ${galleries === '' ? 'none' : galleries}`,
    `Item rows: ${counts.itemRows.count}${counts.itemRows.capped ? ' (capped, at least)' : ''}`,
    `Orphan relations: ${orphanText}`,
    `Cache: ${report.cache.state}`,
    `Admin build: ${report.adminBuild ? 'ok' : 'MISSING'}`,
    '',
    `Recent errors: ${report.recentErrors.length}`,
    ...report.recentErrors.map((e) => {
      const context = contextText(e.context);
      return `- ${e.time} ${e.code}${context === '' ? '' : ` (${context})`}`;
    }),
  ];
  return lines.join('\n');
}
