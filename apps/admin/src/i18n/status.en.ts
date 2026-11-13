/** System Status page (M2-34). */
export default {
  subtitle: 'Read-only health report of the plugin. It shows no file paths, hosts or secrets.',
  refresh: 'Refresh',
  errorTitle: 'The status could not be loaded',
  loading: 'Loading status',
  fetched: 'Status as of {time}',
  yes: 'Yes',
  no: 'No',
  copy: {
    button: 'Copy summary',
    done: 'Diagnostic summary copied to the clipboard.',
    failed:
      'Copying is not available here. The summary is shown below, select and copy it by hand.',
    manual: 'Diagnostic summary',
  },
  schema: {
    title: 'Database schema',
    pending: 'Migration pending',
    upToDate: 'Schema is up to date',
    versions: 'Stored version {stored}, current version {current}',
    tablePresent: 'Item table present',
    tableMissing: 'Item table missing',
  },
  counts: {
    title: 'Counts',
    galleries: 'Galleries by status',
    relations: 'Image relations',
    itemRows: 'Gallery item rows',
    orphans: 'Orphan relations',
    notChecked: 'Not checked',
    atLeast: 'at least {count}',
    capped:
      'Counting stops at the limit, so the real number is higher. The gallery data itself is not affected.',
    orphansSkipped:
      'The scan was skipped (too many rows or not enough time), so no number is available. This is not the same as zero.',
    orphansIncomplete: 'The scan stopped early. The number is a lower bound, there may be more.',
    orphansNone: 'No relations point to missing images.',
    orphansFound:
      'Relations point to images that no longer exist. They are skipped when rendering. Repair them with the WP-CLI repair command.',
    status: {
      publish: 'Published',
      draft: 'Drafts',
      pending: 'Pending review',
      private: 'Private',
      future: 'Scheduled',
      trash: 'In trash',
    },
  },
  errors: {
    title: 'Recent errors',
    noneTitle: 'No errors recorded',
    noneText: 'The plugin has not logged any error recently.',
  },
  versions: {
    title: 'Versions',
    plugin: 'Plugin',
    wordpress: 'WordPress',
    php: 'PHP',
    database: 'Database',
    multisite: 'Multisite',
  },
  runtime: {
    title: 'Runtime',
    cacheNone: 'No render cache active',
    cacheState: 'Cache: {state}',
    cacheHint: 'Galleries are rendered on every request.',
    buildOk: 'Admin app build is complete',
    buildMissing: 'Admin app build is missing',
    buildHint: 'Rebuild the admin app or reinstall the plugin package.',
  },
  hints: {
    title: 'Good to know',
  },
};
