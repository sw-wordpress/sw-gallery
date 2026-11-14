# Contributing

Read [AGENTS.md](AGENTS.md), [CODE-STYLE.md](CODE-STYLE.md) and [SPECIFICATION.md](SPECIFICATION.md) first. Setup, every command, the private test
database and CI: [docs/development.md](docs/development.md). Structure: [docs/architecture.md](docs/architecture.md).

## Rules in short

1. `packages/core` is WordPress free (PSR-12, `strict_types`); only `packages/wordpress` uses WordPress APIs (WPCS). Dependencies point inwards.
2. The admin app is Vue 3 + Quasar + TypeScript, never React. Generated output (`assets/admin`, `assets/build`) is never edited.
3. WordPress attachments are the source of truth: gallery changes touch relations only, never copy or delete attachments.
4. Every output path (block, shortcode, PHP API, preview, REST) uses the one renderer and access policy.
5. Security: no `unserialize` without `allowed_classes => false`, no secrets in logs, REST or meta, path and SSRF guards for imports, no outbound requests.
6. Every functional change comes with tests in the same commit; line coverage must stay strictly above 80 % (PHP and JS/TS). Never lower gates or exclude files.
7. User-facing texts: update English and German together (`bin/i18n.sh update`, then `bin/i18n.sh check`; [docs/translations.md](docs/translations.md)).
8. CSS and JavaScript are enqueued with the plugin version as `ver`; no hard-coded or random cache busters.
9. Document unimplemented features as such; keep docs short and link instead of repeating ([docs/decisions.md](docs/decisions.md) records decisions).
10. Unclear requirement or a security decision: ask instead of assuming.

## Checks before a pull request

```bash
export COMPOSER_PROCESS_TIMEOUT=0
composer check                 # version, PSR-12, WPCS, PHPStan level 8, unit tests
composer test:wp && composer test:wp:ms   # one after the other; needs the private DB (bin/test-db.sh)
(cd apps/admin && npm run typecheck && npm run lint && npm run test:coverage && npm run build)   # also assets, assets/blocks
bin/i18n.sh check
```
Browser tests (`bin/test-e2e.sh`) need Docker. A release ZIP can be built and verified locally ([docs/release.md](docs/release.md)).

## Commits and pull requests

Small, coherent commits; imperative English subject line, a short body that says why. No secrets, build output or backups. Update `CHANGELOG.md`
(`[Unreleased]`) for user-visible changes. After opening a pull request wait for the automated reviews and answer every finding (fix it, or explain
why it does not apply).

Vulnerabilities: do not open an issue, follow [SECURITY.md](SECURITY.md).
