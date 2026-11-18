import type en from './status.en';

/** Systemstatus (M2-34). */
const de: typeof en = {
  subtitle:
    'Schreibgeschützter Zustandsbericht des Plugins. Er zeigt keine Dateipfade, Hosts oder Geheimnisse.',
  refresh: 'Aktualisieren',
  errorTitle: 'Der Status konnte nicht geladen werden',
  loading: 'Status wird geladen',
  fetched: 'Stand: {time}',
  yes: 'Ja',
  no: 'Nein',
  copy: {
    button: 'Zusammenfassung kopieren',
    done: 'Diagnose-Zusammenfassung in die Zwischenablage kopiert.',
    failed:
      'Kopieren ist hier nicht möglich. Die Zusammenfassung steht unten, markiere und kopiere sie von Hand.',
    manual: 'Diagnose-Zusammenfassung',
  },
  schema: {
    title: 'Datenbankschema',
    pending: 'Migration ausstehend',
    upToDate: 'Schema ist aktuell',
    newerThanCode: 'Datenbankschema ist neuer als das Plugin',
    versions: 'Gespeicherte Version {stored}, aktuelle Version {current}',
    tablePresent: 'Item-Tabelle vorhanden',
    tableMissing: 'Item-Tabelle fehlt',
  },
  counts: {
    title: 'Zahlen',
    galleries: 'Galerien nach Status',
    relations: 'Bildzuordnungen',
    itemRows: 'Galerie-Item-Zeilen',
    orphans: 'Verwaiste Zuordnungen',
    notChecked: 'Nicht geprüft',
    atLeast: 'mindestens {count}',
    capped:
      'Die Zählung endet am Limit, die tatsächliche Zahl ist höher. Die Galeriedaten selbst sind nicht betroffen.',
    orphansSkipped:
      'Die Prüfung wurde übersprungen (zu viele Zeilen oder zu wenig Zeit), daher gibt es keine Zahl. Das ist nicht dasselbe wie null.',
    orphansIncomplete:
      'Die Prüfung wurde vorzeitig beendet. Die Zahl ist eine Untergrenze, es kann mehr geben.',
    orphansNone: 'Keine Zuordnung verweist auf fehlende Bilder.',
    orphansFound:
      'Zuordnungen verweisen auf Bilder, die nicht mehr existieren. Sie werden beim Rendern übersprungen. Repariere sie mit dem WP-CLI-Reparaturbefehl.',
    status: {
      publish: 'Veröffentlicht',
      draft: 'Entwürfe',
      pending: 'Wartet auf Review',
      private: 'Privat',
      future: 'Geplant',
      trash: 'Im Papierkorb',
    },
  },
  errors: {
    title: 'Letzte Fehler',
    noneTitle: 'Keine Fehler protokolliert',
    noneText: 'Das Plugin hat in letzter Zeit keinen Fehler protokolliert.',
  },
  versions: {
    title: 'Versionen',
    plugin: 'Plugin',
    wordpress: 'WordPress',
    php: 'PHP',
    database: 'Datenbank',
    multisite: 'Multisite',
  },
  runtime: {
    title: 'Laufzeit',
    cacheNone: 'Kein Render-Cache aktiv',
    cacheState: 'Cache: {state}',
    cacheHint: 'Galerien werden bei jeder Anfrage neu gerendert.',
    buildOk: 'Build der Admin-App ist vollständig',
    buildMissing: 'Build der Admin-App fehlt',
    buildHint: 'Baue die Admin-App neu oder installiere das Plugin-Paket erneut.',
  },
  hints: {
    title: 'Gut zu wissen',
  },
};

export default de;
