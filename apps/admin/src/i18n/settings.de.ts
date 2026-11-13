import type en from './settings.en';

/** Einstellungen (M2-34). */
const de: typeof en = {
  subtitle: 'Vorgaben für neue Galerien und für das Verhalten beim Löschen des Plugins.',
  errorTitle: 'Die Einstellungen konnten nicht geladen werden',
  loading: 'Einstellungen werden geladen',
  layoutUnavailable: '{layout} (nicht verfügbar)',
  default: 'Standard: {value}',
  on: 'an',
  off: 'aus',
  reset: 'Auf Standard zurücksetzen',
  save: 'Einstellungen speichern',
  discard: 'Änderungen verwerfen',
  unsaved: 'Du hast ungespeicherte Änderungen.',
  saved: 'Einstellungen gespeichert.',
  problem: {
    required: 'Gib eine Zahl ein.',
    integer: 'Gib eine ganze Zahl ein.',
    min: 'Muss mindestens {min} sein.',
    max: 'Darf höchstens {max} sein.',
    layout: 'Dieses Layout ist nicht (mehr) verfügbar. Wähle ein anderes.',
    server: 'Der Server hat diesen Wert abgelehnt.',
    confirmation:
      'Das Löschen der Daten bei der Deinstallation muss ausdrücklich bestätigt werden. Es wurde nichts geändert. Schalte die Option erneut ein und bestätige.',
    unknownLayout: 'Das gewählte Layout ist nicht (mehr) verfügbar. Es wurde nichts gespeichert.',
  },
  display: {
    title: 'Darstellung',
    subtitle: 'Gilt dort, wo eine Galerie oder ein Shortcode keinen eigenen Wert festlegt.',
  },
  defaultLayout: {
    label: 'Standard-Layout',
    hint: 'Das Layout von Galerien, die kein eigenes wählen.',
  },
  itemsPerPage: {
    label: 'Bilder pro Seite',
    hint: 'Von {min} bis {max}. Standard: {value}.',
  },
  lightbox: {
    label: 'Bilder in einer Lightbox öffnen',
    hint: 'Besucher können Bilder auf der Website vergrößern.',
  },
  data: {
    title: 'Daten beim Löschen des Plugins',
    subtitle: 'Das Deaktivieren des Plugins entfernt nie etwas.',
    label: 'Alle Plugin-Daten löschen, wenn das Plugin gelöscht wird',
    attachmentsSafe: 'Bilder in der Mediathek werden nie gelöscht, egal was du wählst.',
  },
  confirm: {
    title: 'Daten beim Löschen des Plugins entfernen?',
    affects: 'Betrifft',
    galleries: 'Alle Galerien und ihre Bildzuordnungen werden entfernt.',
    attachments: 'Bilder in der Mediathek bleiben unberührt.',
    confirm: 'Einschalten',
    cancel: 'Abbrechen',
    word: 'LÖSCHEN',
    typeLabel: 'Tippe {word} zur Bestätigung',
  },
  leave: {
    title: 'Ohne Speichern verlassen?',
    message: 'Deine Änderungen an den Einstellungen wurden nicht gespeichert.',
    confirm: 'Ohne Speichern verlassen',
    cancel: 'Weiter bearbeiten',
  },
};

export default de;
