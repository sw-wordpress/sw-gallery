# AGENTS.md – long-term rules for sw-gallery

Read `SPECIFICATION.md` (product/engineering contract) and `CODE-STYLE.md` (binding style/security rules) before changing code.

## Architecture rules
- `packages/core` is framework independent: **no WordPress functions, classes or hooks**. PSR-12, `strict_types`, camelCase.
- `packages/wordpress` is the only place using WP APIs. WPCS style (tabs, snake_case functions), PSR-4 class names are an accepted exception (CODE-STYLE §3.2).
- Dependencies point inwards: wordpress → core. Core never imports the adapter.
- The admin/editor UI is **Vue 3 + Quasar + TypeScript** (never React); Gutenberg glue stays minimal (CODE-STYLE §7, §8).
- WordPress attachments are the source of truth: gallery changes only touch relations, never delete or copy attachments (CODE-STYLE §4).
- Every output path (block, shortcode, PHP API, preview, REST) uses the same renderer and access policy.
- Hooks/events: define failure policy explicitly (see `docs/hooks.md`).
- Security: no unserialize without `allowed_classes => false`, no secrets in logs/REST/post meta, SSRF/path guards for every import.
- PHPStan level 8 covers all production PHP (core, adapter, plugin file, `uninstall.php`) with no baseline or ignores; `bin/` (build and dev scripts, never shipped) is deliberately outside the scope; WordPress/WP-CLI symbols and PHPDoc fixes live in `tests/phpstan/`.

## Workflow
1. Write/adjust interface → implement → tests → `composer test`, `composer stan`, `composer cs`, `composer cs:wp`; admin app: `npm run typecheck/lint/test:coverage` (see `docs/development.md`) → docs → update `TODO.md`/`DONE.md` → small coherent commit.
2. Never run destructive operations on the real WordPress install; tests use temp dirs and a private MariaDB instance (`SWG_TEST_DB_*` env vars, see `docs/development.md`).
3. Document unimplemented features explicitly (no fake implementations).
4. After each implementation, include a concise German checklist of what Stefan can/should test as a user. Give concrete steps and the expected result for each relevant feature; distinguish recommended acceptance checks from optional checks. Report automated checks separately and never treat them as Stefan's practical acceptance.

## Parallel work (sub-agents)
Plan, waves and owned paths: `TODO.md` → "Parallelization plan". Sub-agents work in their own git worktree and:
- touch only the paths their stream owns; never edit `TODO.md`, `DONE.md`, shared config (`composer.json`, lockfiles, `phpunit.xml.dist`, `phpcs*`, `phpstan.neon.dist`) or public contracts (core interfaces) unless the brief says so — report the needed change instead;
- run the relevant checks in their worktree and commit small and coherent there; never push, never touch the real WordPress install;
- end with changed files, check results, open points and the German tester checklist (Workflow step 4).

## Generated frontend output
`assets/admin` (admin app) and `assets/build` (lightbox) are generated and git-ignored; never edit them. After an edit under `apps/admin/src` or `assets/*/src` the project hook (`.claude/settings.json` → `bin/rebuild-on-change.sh`) rebuilds the output and reports build errors back (exit 2). For manual edits run `bin/watch-build.sh`. The hook only builds (no type check): the normal gates (`npm run build`, `typecheck`, `lint`, `test:coverage`) stay mandatory.

## Translations
Every change to user-facing text updates all languages (English + German minimum; PHP gettext catalogs in `languages/`, SPA catalogs in `apps/admin/src/i18n/`). Run `bin/i18n.sh update` and `bin/i18n.sh check`; the PHPUnit test `I18nTest` and the Vitest parity test enforce it. See `docs/translations.md`.

## Dokumentation

- Verfasse Dokumentation möglichst knapp und ohne Wiederholungen, um Tokens zu sparen. Beschränke sie auf nötige Entscheidungen, Sicherheitsdetails und Testhinweise; verlinke vorhandene Dokumentation statt Inhalte zu duplizieren.

## Tests und Coverage

- Jede funktionale Codeänderung (neue Funktionen, Fehlerbehebungen und Refactorings) muss durch passende automatisierte Tests abgedeckt sein; Tests im selben Arbeitsschritt ergänzen oder anpassen.
- Pflicht ist eine Zeilenabdeckung von **> 80 %** des produktiven Codes je Plugin; genau 80 % reichen nicht. PHP und JavaScript/TypeScript, soweit vorhanden, jeweils prüfen. Bestehende strengere Coverage-Grenzen bleiben verbindlich.
- Vor Abschluss relevante Tests und Coverage-Prüfungen ausführen und Ergebnisse nennen. Fehlende Coverage-Infrastruktur ergänzen; fehlende Messwerte gelten nicht als erfüllte Vorgabe. Keine produktiven Dateien ausschließen oder Grenzen senken, um die Vorgabe zu umgehen.

Vorhanden: PHPUnit-/WordPress-Coverage und SonarQube; siehe `docs/development.md` (SonarQube Cloud) und `sonar-project.properties`.

## Automated review follow-up for pull / merge requests

1. After creating a pull request (PR) or merge request (MR), keep the task open and wait five minutes before checking for automated reviews. Inspect review submissions, inline comments and unresolved review threads, bot comments, and review-related check runs for the latest pushed commit.
2. Assess every reported finding against the code. Fix valid findings, run the relevant checks, and push the corrections to the same PR/MR. Document the reasoning for findings that are incorrect or do not apply; do not silently ignore them.
3. After every push of corrections, wait another five minutes and repeat the review check. Continue this cycle until there are no outstanding valid automated-review findings for the latest commit.
4. If an automated review is still queued or running, keep monitoring and recheck after another five minutes. If review results cannot be accessed or the review fails to run, report that blocker instead of claiming review success.
5. Report success only after the follow-up check finds no outstanding valid findings and no automated reviews still pending. Include the PR/MR link and the review outcome. If no automated review appeared after the five-minute wait, explicitly report that no automated review was received rather than implying approval.

## Statische Ressourcen und Cache-Busting
- Eingebundene CSS- und JavaScript-Dateien immer mit der aktuellen Plugin-Version als Versionsparameter versehen, um veraltete Browser-Caches nach Updates zu vermeiden.
- In WordPress bevorzugt wp_enqueue_script() und wp_enqueue_style() mit der zentral definierten Plugin-Version verwenden.
- Keine fest codierten Versionsnummern, Zeitstempel oder zufälligen Cache-Busting-Parameter nutzen.
- Auch dynamisch eingebundene Assets und weitere statische Ressourcen berücksichtigen.

## Unsicherheit und Entscheidungen
- Bei unklaren Anforderungen, Architektur- oder Sicherheitsentscheidungen **nachfragen statt Annahmen treffen**.
- Zuvor Code, Tests und Dokumentation prüfen. Bei mehreren sinnvollen Lösungen Alternativen mit Empfehlung nennen.
- Kleine, risikoarme Details eigenständig entscheiden. Keine spekulativen Fixes oder unbeabsichtigten Verhaltensänderungen.
