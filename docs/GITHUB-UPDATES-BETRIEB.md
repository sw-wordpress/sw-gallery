# Verifizierte GitHub-Updates betreiben

**Noch nicht produktiv eingerichtet:** Der ausgelieferte Schlüsselring ist leer. Ohne lokalen Ed25519-Vertrauensanker, geschützte GitHub-Umgebung und vollständig signierten Release besteht keine aktive Updateversorgung. Keine Produktionsschlüssel wurden erzeugt, keine GitHub-Secrets verändert und kein Release veröffentlicht.

Quelle: `sw-wordpress/sw-gallery` (Repository-ID **1453105629**). Paket `sw-gallery-<X.Y.Z>.zip`, Hauptdatei `sw-gallery/sw-gallery.php`, Version `SWGALLERY_VERSION`. Stabile Tags heißen `[v]X.Y.Z`; Pre-Releases und vierstellige ChurchTools-Versionen sind ausgeschlossen.

Vor der Schlüsselübergabe die GitHub-Umgebung **plugin-releases** mit erforderlichen Reviewern, geschützten Release-Tags und eingeschränkten Deployment-Branches einrichten. Branch-/Tag-Regeln müssen Manipulationen am signierenden Workflow verhindern; ein Secret allein schützt nicht vor berechtigtem bösartigem Workflow-Code.

## Copy & Paste: Schlüssel für SW Gallery einrichten

Diese Befehle sind für Bash/Zsh auf dem lokalen Entwicklungs-/Release-Rechner mit PHP und Sodium gedacht, nicht für den produktiven WordPress-Webserver. Der Checkout liegt hier unter `/var/www/html/wordpress_dev/wp-content/plugins/sw-gallery`. Private Dateien landen ausschließlich unter `$HOME/.local/share/sw-gallery-release-keys`, außerhalb dieses Checkouts und Webroots. Diesen Ordner separat verschlüsselt sichern; er gehört nicht in Git, Release-ZIPs oder öffentlich synchronisierte Ordner. Die Befehle wurden mit Wegwerfschlüsseln geprüft; produktive Schlüssel werden nicht durch die Dokumentation angelegt.

**1. Einmalig Schlüssel erzeugen.** Der Block verweigert einen bereits vorhandenen Schlüsselordner, damit ein erneutes Einfügen keinen bestehenden Schlüssel ersetzt. Die Key-ID ist eine öffentliche Kennung; beim späteren Schlüsselwechsel eine neue Kennung und einen neuen Ordner verwenden.

```bash
(
  set -eu
  umask 077
  export SWG_RELEASE_KEYS_DIR="$HOME/.local/share/sw-gallery-release-keys"
  php <<'PHP'
<?php
declare(strict_types=1);
if (!function_exists('sodium_crypto_sign_keypair')) {
    throw new RuntimeException('PHP-Sodium fehlt.');
}
$dir = getenv('SWG_RELEASE_KEYS_DIR');
if (!$dir || file_exists($dir) || is_link($dir)) {
    throw new RuntimeException('Schlüsselordner existiert bereits oder Pfad fehlt. Keine Dateien verändert.');
}
if (!mkdir($dir, 0700, true)) {
    throw new RuntimeException('Schlüsselordner konnte nicht angelegt werden.');
}
$id = 'sw-gallery-2026-11';
$pair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($pair);
$public = sodium_crypto_sign_publickey($pair);
$files = [
    'signing-key.base64' => base64_encode($secret) . "\n",
    'public-keys.php' => "<?php\nreturn " . var_export([$id => base64_encode($public)], true) . ";\n",
    'key-id.txt' => $id . "\n",
];
foreach ($files as $name => $contents) {
    $handle = fopen($dir . '/' . $name, 'x');
    if (!$handle || fwrite($handle, $contents) !== strlen($contents)) {
        throw new RuntimeException('Schlüsseldatei konnte nicht vollständig geschrieben werden.');
    }
    fclose($handle);
    chmod($dir . '/' . $name, 0600);
}
sodium_memzero($secret);
sodium_memzero($pair);
echo "Schlüsseldateien erzeugt; privater Schlüssel wurde nicht ausgegeben.\n";
PHP
)
```

**2. Nur den öffentlichen Schlüssel ins Plugin übernehmen.** Dieser Block erhält bereits vorhandene öffentliche Schlüssel und lehnt eine abweichende Belegung derselben Key-ID ab. Dafür werden keine privaten Schlüssel in eine Umgebungsvariable oder nach WordPress kopiert.

```bash
(
  set -eu
  export SWG_PLUGIN_DIR='/var/www/html/wordpress_dev/wp-content/plugins/sw-gallery'
  export SWG_RELEASE_KEYS_DIR="$HOME/.local/share/sw-gallery-release-keys"
  php <<'PHP'
<?php
declare(strict_types=1);
$target = getenv('SWG_PLUGIN_DIR') . '/packages/wordpress/src/Update/Config/trusted-keys.php';
$existing = require $target;
$incoming = require getenv('SWG_RELEASE_KEYS_DIR') . '/public-keys.php';
if (!is_array($existing) || !is_array($incoming) || count($incoming) !== 1) {
    throw new RuntimeException('Ungültiger öffentlicher Schlüsselring.');
}
foreach ($incoming as $id => $key) {
    $decoded = is_string($key) ? base64_decode($key, true) : false;
    if (!is_string($decoded) || strlen($decoded) !== 32 || (isset($existing[$id]) && $existing[$id] !== $key)) {
        throw new RuntimeException('Ungültiger öffentlicher Schlüssel oder widersprüchliche Key-ID.');
    }
    $existing[$id] = $key;
}
$contents = "<?php\n/**\n * Public Ed25519 verification keys.\n *\n * @package sw-gallery\n */\n\nreturn " . var_export($existing, true) . ";\n";
if (file_put_contents($target, $contents, LOCK_EX) !== strlen($contents)) {
    throw new RuntimeException('Öffentlicher Schlüsselring konnte nicht geschrieben werden.');
}
echo "Öffentlicher Schlüsselring aktualisiert.\n";
PHP
  php -l "$SWG_PLUGIN_DIR/packages/wordpress/src/Update/Config/trusted-keys.php"
  git -C "$SWG_PLUGIN_DIR" diff -- packages/wordpress/src/Update/Config/trusted-keys.php
)
```

Die angezeigte Änderung an `packages/wordpress/src/Update/Config/trusted-keys.php` enthält ausschließlich den öffentlichen Schlüssel. Diese Datei nach Review regulär committen. Bestehende Installationen benötigen sie einmal über einen vertrauenswürdigen manuellen Upload oder eine lokale Konfiguration; erst dann können sie signierte Folgeupdates akzeptieren.

**3. GitHub vorbereiten und Secret hinterlegen.** Zuerst in den [Repository-Environments](https://github.com/sw-wordpress/sw-gallery/settings/environments) die Umgebung `plugin-releases` anlegen und Freigaben sowie erlaubte Release-Tags passend zum Workflow absichern. Erst danach folgenden Block mit einem für dieses Repository berechtigten GitHub-CLI-Konto ausführen. `gh auth status` prüft die Anmeldung; falls nötig zuvor `gh auth login` ausführen.

```bash
(
  set -eu
  SWG_RELEASE_KEYS_DIR="$HOME/.local/share/sw-gallery-release-keys"
  gh auth status
  gh secret set SW_PLUGIN_RELEASE_SIGNING_KEY \
    --repo sw-wordpress/sw-gallery --env plugin-releases \
    < "$SWG_RELEASE_KEYS_DIR/signing-key.base64"
  gh variable set SW_PLUGIN_RELEASE_KEY_ID \
    --repo sw-wordpress/sw-gallery --env plugin-releases \
    --body "$(cat "$SWG_RELEASE_KEYS_DIR/key-id.txt")"
  gh secret list --repo sw-wordpress/sw-gallery --env plugin-releases
  gh variable list --repo sw-wordpress/sw-gallery --env plugin-releases
)
```

Der private Schlüssel wird über Standardeingabe an GitHub übergeben und erscheint weder im Befehlsargument noch in der Terminalausgabe. Die Listen zeigen Secret-Namen beziehungsweise öffentliche Variablen. Referenzen: [GitHub CLI: Secret setzen](https://cli.github.com/manual/gh_secret_set), [Variable setzen](https://cli.github.com/manual/gh_variable_set), [PHP: Ed25519-Schlüsselpaar erzeugen](https://www.php.net/manual/en/function.sodium-crypto-sign-keypair.php).

Ein erneutes Setzen des Secrets ersetzt den GitHub-Wert. Deshalb vor Ausführung sicherstellen, dass dieser lokale Schlüssel zum gewünschten öffentlichen Schlüsselring passt; bei Rotation den Ablauf weiter unten verwenden. Die Befehle erzeugen keinen Release-Tag und veröffentlichen kein ZIP. Der eingerichtete Tag-Workflow übernimmt später Build, Signierung und Veröffentlichung.

## Prüfen und installieren

Bedienung ausschließlich unter **Plugins → Installierte Plugins**, bei Multisite in der Netzwerkverwaltung. Die eigene Pluginzeile zeigt „Jetzt auf Updates prüfen“, letzten Erfolg/Fehler und den Hinweis auf ausschließlich manuelle Aktualisierung. Es gibt keinen Installer in der Galerie-SPA.

Nach vollständigem Seitenaufbau prüft ein authentifizierter AJAX-Aufruf nur bei Fälligkeit: einmal je 24 Stunden. Auch Fehler verbrauchen den Versuch; Cache-/Transient-Löschung setzt ihn nicht zurück. Manuell sind Nonce, `update_plugins`, Dateimodifikationsrecht und mindestens 60 Sekunden Abstand nötig; Multisite verlangt Netzwerkrechte auch über `admin-ajax.php`. Frontend, Cron, REST und WP-CLI prüfen nie GitHub.

Verifizierte Angebote erscheinen in der nativen Pluginübersicht. Installation ausschließlich durch WordPress-Einzel-, AJAX- oder Sammelupdate mit Core-Nonce. Automatische Installation bleibt auch für alte gecachte Angebote gesperrt. Detailansicht und Angebotsfilter lesen ausschließlich den lokalen Zustand. Metadaten verfallen nach sieben Tagen; bei Fehlern läuft die Gültigkeit weiter ab.

Fehlende/falsche Schlüssel, Sodium/ZipArchive, Signatur, Identität, Hash, Header oder unsichere ZIPs sperren die Übergabe an Core. Auf dem Server werden nur öffentliche Schlüssel benötigt. Optional lokale Array-Konstante `SWGALLERY_RELEASE_PUBLIC_KEYS` (`key_id => Base64(public_key)`), niemals `SW_PLUGIN_RELEASE_PUBLIC_KEYS` oder ein remote geladenes Schlüsselverzeichnis. Fehlende Unterstützung beeinträchtigt nicht die Galerie selbst.

Zustand/Lock: `swg_verified_github_updates_v1`, `swg_verified_github_updates_lock_v1`; Netzwerkoptionen bei Multisite, sonst nicht autoloadende Optionen. Die Lease ist atomar mit MySQL/MariaDB-Namenslock geschützt und hat Eigentümerkennung/120 Sekunden Laufzeit. Ohne DB-Lock-Unterstützung wird abgebrochen. Migration `swg_verified_updates_migrated` entfernt nur eigene Core-Auto-Update-Auswahlen und Angebote. Es gab im Zielplugin keine früheren GitHub-Updater, Cronjobs oder Tokens zu entfernen. Deaktivierung räumt eigene Angebote vor Wegfall der Guards auf; fremde Plugins bleiben erhalten.

## Release

`make dist` baut ein installierbares ZIP lokal, `make release VERSION=1.2.3` ist der **explizite Veröffentlichungsauftrag**: sauberer Checkout, größere stabile Version, Versionen/Changelog aktualisieren, kleiner Versionscommit, annotierter Tag, atomarer Push von Branch und Tag nach `origin`. Vorher Release-Notizen unter `[Unreleased]` pflegen und den Code per PR prüfen. Dieser Auftrag erzeugt keine Schlüssel und setzt keine Secrets.

Der Tag-Workflow verwendet `plugin-releases`, ergänzt den bestehenden öffentlichen Schlüsselring um den Build-Schlüssel, baut die ZIP und prüft sie. Erst ZIP-Upload in einen neuen Draft, dann Signieren des Manifests über die tatsächlichen GitHub-Asset-IDs. Manifest und detached Signatur hochladen, alle hochgeladenen Artefakte erneut mit den Laufzeitprüfern verifizieren, erst danach veröffentlichen. Bestehende Releases werden nicht überschrieben; nach Fehler einen unvollständigen Draft bewusst prüfen/entfernen, bevor derselbe Tag neu gestartet wird. Fehlender Signierschlüssel/Key-ID bricht vor Upload ab. Testsuiten laufen ausschließlich auf Pull Requests; Release-Jobs prüfen Artefakte und führen keine Testsuite aus.

## Vertrauensstart und Rotation

Den öffentlichen Schlüssel einmal separat vertrauenswürdig an Bestandsinstallationen verteilen, z. B. kontrollierter manueller ZIP-Upload oder lokale Konstantenkonfiguration. Der erste Release darf seinen eigenen Schlüssel nicht legitimieren. Für Rotation erst eine mit dem alten Schlüssel signierte Übergangsversion mit beiden öffentlichen Schlüsseln ausliefern; danach mit neuer Key-ID signieren. Eine kompromittierte Vertrauensbasis verlangt einen separat vertrauenswürdigen Wiederherstellungsweg. Private Schlüssel verschlüsselt sichern, nie in ZIPs/Git/WordPress/Logs.

## Grenzen und WordPress.org

Kein privates GitHub-Repository/Token und kein automatischer Downgrade. Ohne signiertes zeitlich gültiges Channel-Verzeichnis kann ein Erstclient das absichtliche Vorenthalten neuerer Releases nicht erkennen. Nur konkrete GitHub-HTTPS-Hosts sind freigegeben; CDN-Änderungen können einen Client-Release erfordern. Die Guards schützen die unterstützten WordPress-Abläufe, nicht einen bereits kompromittierten PHP-Prozess. Ein bewusst manuell hochgeladenes ZIP ist ein separater Core-Installationsweg und wird nicht als GitHub-verifiziert bezeichnet.

Vor Store-Vertrieb den GitHub-Provider, seine UI/Skripte und den **Update URI**-Header aus dem Store-Build entfernen. Eine letzte signierte Übergangsversion muss diesen Wechsel an Bestandsinstallationen ausliefern und gezielt eigene Angebote/Zustände bereinigen. Kein automatischer Wechsel aufgrund eines gleichnamigen Store-Angebots.

## Verifikation

Siehe [Entwicklung](development.md) und `VerifiedUpdatesTest`: Wegwerfschlüssel, simuliertes HTTP, private MariaDB/WordPress; echte Upgrader ersetzen ausschließlich Fixtures unter `tests/wp/vendor`, niemals die produktiven Plugin-Dateien. Native Browserprüfung: `bin/test-e2e.sh -- tests/verified-updates.spec.ts`. Schlüsselblöcke werden isoliert mit Wegwerfschlüsseln und simuliertem `gh` geprüft. Der echte Build wird ebenfalls mit Wegwerfschlüssel signiert und mit Manifest-/Paketprüfer getestet.


### Lokaler Nachweis (17.11.2026)

- PHP: 494 Unit-/Integrationstests; vollständige WordPress-Suite mit 1.662 Fällen (zehn umgebungs-/netzwerkspezifische Überspringungen), anschließend finale Update-/Lebenszyklusprüfung mit 120 Fällen in Single-Site und Multisite. Zusätzlich 31 Admin-Tests nach der Footer-Ergänzung. Keine fehlgeschlagenen finalen Prüfungen. PHPStan Level 8, beide Style-Gates, Übersetzungen und Workflow-Lint erfolgreich.
- Zeilenabdeckung: PHP 97,09 % (7.529/7.755, alle 249 produktiven Dateien); Admin-App 96,34 %, Assets inklusive Update-Skript 100 %, Block 99,28 %. Finale Updater-Dateien und der anschließend ergänzte Admin-Footer wurden erneut instrumentiert; keine produktiven Dateien ausgeschlossen. Admin-Suite: 501 Tests, lokal mit zwei Workern, um CPU-bedingte Timeouts zu vermeiden.
- Browser: native englische/deutsche Pluginzeile, Sperre ohne Schlüssel, keine Update-UI in der SPA. Zusätzlich SW-BackupIt 0.8.1 aus dem vorhandenen lokalen ZIP parallel in der Wegwerf-Installation aktiviert; getrennte Aktionen/Schaltflächen geprüft. Die wiederholbare CI-Prüfung verwendet einen unabhängigen zweiten Provider mit eigenen Schlüsseln und Speichern.
- Die drei Schlüsselblöcke bestehen unter Bash und Zsh mit Wegwerfschlüsseln und simuliertem GitHub-CLI. `make release` wurde ausschließlich gegen ein privates temporäres Git-Repository ausgeführt. Der Artefakttest signiert echte Build-ZIPs und prüft Signatur, Paket, eingebetteten öffentlichen Build-Schlüssel, Manipulationsablehnung und Abbruch ohne Secret.

GitHub-Schutzregeln, Produktions-Vertrauensanker und erster signierter Release bleiben Betreiberaufgaben; die GitHub-CI-Matrix wurde lokal nicht ausgeführt.

### Praktische Abnahme durch Stefan

**Empfohlen:** Unter „Plugins → Installierte Plugins“ die eigene Zeile öffnen. Erwartet: Hinweis „Nur manuelle Aktualisierung“, Prüfschaltfläche und ohne Schlüssel eine klare Sperrmeldung. Schaltfläche betätigen: kein Download/Installationsangebot. Danach Galerie-App öffnen: dort keine Update-Bedienelemente.

**Optional, nach Betreiberkonfiguration auf Staging:** Den öffentlichen Vertrauensanker kontrolliert verteilen, eine signierte höhere Version veröffentlichen und nacheinander Einzel-, AJAX- und Sammelupdate über WordPress prüfen. Erwartet: ausschließlich explizite manuelle Installation; Auto-Updates bleiben gesperrt. In Multisite darf nur die berechtigte Netzwerkverwaltung prüfen/installieren. Diese Abnahme ist unabhängig von den oben genannten automatisierten Tests.

Finales lokales Paket: `dist/sw-gallery-0.1.0.zip`, 295 Dateien, 1.027.541 Bytes. SHA-256: `a5dc1d6a9cf2af97540204b1965a0f693979ec925813650b8bd62d767bd0fa40`. Statische Paketprüfung, Wegwerf-Signierung und drei isolierte Installations-Smoke-Tests bestanden; keine Plugin-PHP-Warnungen. Autorenzeile nur auf der Übersicht, Editorhöhe unverändert; separate Browserprüfung bestanden.
