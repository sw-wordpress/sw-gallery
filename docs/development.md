# Development

Commands exist once the matching tooling is scaffolded (track in `TODO.md`); PHP tooling, WordPress suite, i18n and CI exist, `apps/admin`, `e2e/` and the build scripts are planned (E2E: see below).

## Setup
```bash
composer install
(cd apps/admin && npm install)
bin/test-db.sh start                 # private throw-away MariaDB (never touches real databases); also: stop (deletes data), status, env
(cd tests/wp && composer install)    # WordPress test environment (own PHPUnit 9.6, WP core downloaded into tests/wp/vendor)
```
`bin/test-db.sh` uses a temp data directory (`SWG_TEST_DB_DIR`, default `/tmp/swg-test-db`), a random free port (kept in `$DIR/port`) and the user
`swg`, which may only access databases `swg_*`. `tests/wp` reads `SWG_TEST_DB_HOST/PORT/USER/PASSWORD` (CI database service) and falls back to that port file.
WordPress tests skip themselves when the database is unreachable (the coverage gate then fails, because the adapter is unmeasured).
Needs `mariadbd`/`mariadb-install-db` on PATH and the `mysqli` extension.
Playwright lives in `e2e/` (own `package.json`, `playwright.config.ts`, specs; plugin-wide scope: admin SPA, Gutenberg, frontend, lightbox). It runs against the throw-away WordPress of `tests/e2e-env/` (Docker Compose), never the real install. `tests/E2e/` is reserved for PHPUnit end-to-end tests.

## End-to-end tests
```bash
bin/test-e2e.sh [--keep] [--headed] [-- <playwright args>]   # e.g. -- tests/locale.spec.ts
(cd e2e && npm run typecheck && npm run lint && npm run format:check)
```
Prerequisites: Docker with the compose plugin, Node 22 (`e2e/.nvmrc`), curl, openssl, python3; Chromium system libraries (`npx playwright install-deps chromium` on CI/Linux).
The script installs production Composer dependencies in a `composer:2` helper container if `vendor/` is missing, builds `apps/admin` if `assets/admin` is missing
(`SWG_E2E_REBUILD=1` forces both), starts a compose project `swg-e2e-<random>` on random localhost ports (WordPress, MariaDB, wp-cli provisioning), runs
Playwright and removes containers, network and volumes in every case (also failure/CTRL-C; `--keep` prints the clean-up command). The plugin is mounted read-only.
Throw-away passwords are generated per run into the git-ignored `tests/e2e-env/.generated/e2e.env`. Overrides: `SWG_E2E_WP_IMAGE` / `SWG_E2E_CLI_IMAGE`
(WordPress matrix, e.g. `wordpress:6.4-php8.2-apache` + `wordpress:cli-php8.2`), `SWG_E2E_PLUGIN_DIR` (mount another plugin copy). Provisioning needs network access
(wordpress.org language pack). Reports: `e2e/playwright-report`, traces/screenshots on failure in `e2e/test-results`, human-review screenshots in `e2e/screenshots`.
Users: `admin`, `editor`, `author`, `contributor`, `subscriber`, `german` (editor, `de_DE`); sessions are stored per role in `e2e/.auth` by `global-setup.ts`.
Covered (smoke, M2-36): menu and app shell per role, subscriber refusal, asset scoping and `ver=`, CSS isolation (computed styles of wp-admin chrome and injected bare elements vs. the
Dashboard), German locale, missing build (second WordPress service with `assets/admin` hidden), axe on the app root (fails on serious/critical; waivers with reasons in `a11y.spec.ts`).
CI: `.github/workflows/e2e.yml`.

## Checks
```bash
composer cs          # PSR-12 (core, tests)
composer cs:wp       # WordPress Coding Standards + PHPCompatibilityWP (adapter)
composer stan        # PHPStan level 8, no baseline
composer test        # unit + integration
composer test:wp     # WordPress integration tests (single site)
composer test:wp:ms  # same environment as multisite
composer test:coverage  # unit + WordPress suite Clover, merged (tests/coverage/merge.php), strict gate > 80 %
(cd apps/admin && npm run typecheck && npm run lint && npm run test:coverage)   # vue-tsc, ESLint+Prettier, Vitest
bin/test-e2e.sh       # starts tests/e2e-env (Docker: throw-away WordPress + DB), runs Playwright from e2e/, tears down
```
`composer check` = style + analysis + tests. Documented lint exceptions live in `phpcs.xml.dist` / `phpcs-wordpress.xml.dist`.
PHPStan covers all production PHP (core, adapter, plugin file, `uninstall.php`); `bin/` is not analysed; WordPress symbols/stubs live in `tests/phpstan/`.

## Build
`bin/build-zip.sh` creates `dist/sw-gallery-<version>.zip` (production Composer dependencies, compiled admin SPA/blocks/frontend). Build machine needs
Composer and Node.js; the target host needs neither. Bundled PHP libraries are prefixed (Mozart, namespace `SWGallery\Dependencies\`) to avoid collisions.
Release/tag/signing flow: decision D8 in `decisions.md`, task M5-17 in `TODO.md`.

## CI
`.github/workflows/tests.yml`: style/PHPStan/unit/i18n/`composer audit`, WordPress suite (PHP 8.2-8.4 x WordPress 6.4 and 6.9, single site + multisite, MariaDB service),
coverage gate, Sonar upload. Action versions are pinned to full tags. The Sonar job runs only when the `SONAR_TOKEN` secret exists (not for forks); the
organisation comes from the variable `SONAR_ORGANIZATION` (default `sw-wordpress`).

## Dependency security
`composer audit --locked --no-dev` and `npm audit --omit=dev --audit-level=high` fail CI on advisories in shipped dependencies; a daily workflow audits
all dependencies. Node version pinned in `apps/admin/.nvmrc`.

## SonarQube Cloud
CI uploads merged PHP Clover coverage (adapter, plugin file and `uninstall.php` included) and, once it exists, `apps/admin/coverage/lcov.info`; project mapping and source paths are in `sonar-project.properties`.
Coverage gate: line coverage **strictly above 80 %**, PHP and JS/TS separately (`tests/coverage/check.php`, `apps/admin/scripts/check-coverage.mjs`;
Vitest thresholds alone would accept exactly 80 %). Unmeasured production code is not excluded.

## Conventions
See `AGENTS.md`, `CODE-STYLE.md`, `SPECIFICATION.md`, `decisions.md`, `translations.md`, `hooks.md`.
