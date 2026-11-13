/** Settings page (M2-34). */
export default {
  subtitle: 'Defaults for new galleries and for what happens when the plugin is deleted.',
  errorTitle: 'The settings could not be loaded',
  loading: 'Loading settings',
  layoutUnavailable: '{layout} (not available)',
  default: 'Default: {value}',
  on: 'on',
  off: 'off',
  reset: 'Reset to default',
  save: 'Save settings',
  discard: 'Discard changes',
  unsaved: 'You have unsaved changes.',
  saved: 'Settings saved.',
  problem: {
    required: 'Enter a number.',
    integer: 'Enter a whole number.',
    min: 'Must be at least {min}.',
    max: 'Must be at most {max}.',
    layout: 'This layout is not available (any more). Pick another one.',
    server: 'The server rejected this value.',
    confirmation:
      'Deleting data on uninstall must be confirmed explicitly. Nothing was changed. Switch it on again and confirm.',
    unknownLayout: 'The selected layout is not available (any more). Nothing was saved.',
  },
  display: {
    title: 'Display',
    subtitle: 'Used where a gallery or a shortcode does not set its own value.',
  },
  defaultLayout: {
    label: 'Default layout',
    hint: 'The layout of galleries that do not choose their own.',
  },
  itemsPerPage: {
    label: 'Images per page',
    hint: 'From {min} to {max}. Default: {value}.',
  },
  lightbox: {
    label: 'Open images in a lightbox',
    hint: 'Visitors can enlarge images on the website.',
  },
  data: {
    title: 'Data when the plugin is deleted',
    subtitle: 'Deactivating the plugin never removes anything.',
    label: 'Delete all plugin data when the plugin is deleted',
    attachmentsSafe: 'Images in the Media Library are never deleted, whatever you choose.',
  },
  confirm: {
    title: 'Delete data when the plugin is deleted?',
    affects: 'Affects',
    galleries: 'All galleries and their image relations will be removed.',
    attachments: 'Images in the Media Library stay untouched.',
    confirm: 'Turn on',
    cancel: 'Cancel',
    word: 'DELETE',
    typeLabel: 'Type {word} to confirm',
  },
  leave: {
    title: 'Leave without saving?',
    message: 'Your changes to the settings have not been saved.',
    confirm: 'Leave without saving',
    cancel: 'Keep editing',
  },
};
