export default {
  app: {
    title: 'Gallery Management',
    version: 'Version {version}',
  },
  nav: {
    label: 'Gallery Management navigation',
    overview: 'Overview',
    galleries: 'Galleries',
    media: 'Media Overview',
    settings: 'Settings',
    status: 'System Status',
  },
  placeholder: {
    title: '{section}: not yet available',
    body: 'This section is planned but not implemented in this version. It shows no data and offers no actions.',
  },
  common: {
    retry: 'Retry',
    items: 'no items | one item | {count} items',
  },
  errors: {
    bootstrap: 'The admin application could not start: the server configuration is missing.',
    generic: 'The request failed. Please try again.',
    network: 'The server could not be reached. Check your connection and try again.',
    aborted: 'The request was cancelled.',
    invalidResponse: 'The server returned an unexpected response.',
    forbidden: 'You are not allowed to do this.',
    notFound: 'The requested item does not exist (anymore).',
    validation: 'Some values are invalid. Please check your input.',
    conflict:
      'Someone else changed this item in the meantime. Reload it to see the current version.',
    server: 'The server reported an error. Please try again later.',
  },
};
