import type en from './en';

const de: typeof en = {
  app: {
    title: 'Galerieverwaltung',
    version: 'Version {version}',
  },
  nav: {
    label: 'Navigation der Galerieverwaltung',
    overview: 'Übersicht',
    galleries: 'Galerien',
    media: 'Medienübersicht',
    settings: 'Einstellungen',
    status: 'Systemstatus',
  },
  placeholder: {
    title: '{section}: noch nicht verfügbar',
    body: 'Dieser Bereich ist geplant, aber in dieser Version nicht umgesetzt. Er zeigt keine Daten und bietet keine Aktionen.',
  },
  common: {
    retry: 'Erneut versuchen',
    items: 'keine Einträge | ein Eintrag | {count} Einträge',
  },
  errors: {
    bootstrap: 'Die Admin-Anwendung konnte nicht starten: Die Serverkonfiguration fehlt.',
    generic: 'Die Anfrage ist fehlgeschlagen. Bitte erneut versuchen.',
    network: 'Der Server ist nicht erreichbar. Bitte Verbindung prüfen und erneut versuchen.',
    aborted: 'Die Anfrage wurde abgebrochen.',
    invalidResponse: 'Der Server hat eine unerwartete Antwort geliefert.',
    forbidden: 'Dafür fehlt die Berechtigung.',
    notFound: 'Der angeforderte Eintrag existiert (nicht mehr).',
    validation: 'Einige Werte sind ungültig. Bitte die Eingaben prüfen.',
    conflict:
      'Jemand anderes hat diesen Eintrag zwischenzeitlich geändert. Bitte neu laden, um die aktuelle Version zu sehen.',
    server: 'Der Server meldet einen Fehler. Bitte später erneut versuchen.',
  },
};

export default de;
