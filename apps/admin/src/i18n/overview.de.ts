import type en from './overview.en';

/** Übersicht (M2-34). */
const de: typeof en = {
  subtitle: 'Deine Galerien auf einen Blick, mit Abkürzungen zu den häufigsten Aufgaben.',
  errorTitle: 'Die Übersicht konnte nicht geladen werden',
  loading: 'Übersicht wird geladen',
  counts: {
    region: 'Galerie-Zahlen',
    all: 'Alle Galerien',
    mine: 'Deine Galerien',
    publish: 'Veröffentlicht',
    draft: 'Entwürfe',
    private: 'Privat',
    trash: 'Im Papierkorb',
  },
  recent: {
    title: 'Zuletzt geändert',
    emptyTitle: 'Noch keine Galerien',
    emptyText:
      'Eine Galerie fasst Bilder aus deiner Mediathek zusammen. Lege eine an, um zu beginnen.',
    all: 'Alle Galerien',
  },
  actions: {
    title: 'Schnellaktionen',
    galleries: 'Galerien öffnen',
    media: 'Medienübersicht öffnen',
  },
  health: {
    title: 'Zustand des Plugins',
    loading: 'Zustand des Plugins wird geprüft',
    unavailable: 'Die Zustandsübersicht konnte nicht geladen werden.',
    schemaOk: 'Schema ist aktuell',
    migrationPending: 'Migration ausstehend',
    attention: 'Braucht Aufmerksamkeit',
    noErrors: 'Keine aktuellen Fehler',
    errors: 'Keine aktuellen Fehler | Ein aktueller Fehler | {count} aktuelle Fehler',
    link: 'Systemstatus öffnen',
  },
};

export default de;
