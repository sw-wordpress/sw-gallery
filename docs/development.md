# Development

Commands below describe the **target setup** and exist only once the matching tooling is scaffolded (track in `TODO.md`).

## Setup
```bash
composer install
(cd apps/admin && npm install)
bin/test-db.sh start                 # private throw-away MariaDB (never touches real databases)
(cd tests/wp && composer install)    # WordPress test environment (own PHPUnit, WP core downloaded into tests/wp/vendor)
```
Tests needing a database skip themselves when the service is unreachable. Connection settings: `SWG_TEST_DB_HOST/PORT/USER/PASSWORD`.
Playwright lives in `e2e/` (own `package.json`, `playwright.config.ts`, specs; plugin-wide scope: admin SPA, Gutenberg, frontend, lightbox). It runs against the throw-away WordPress of `tests/e2e-env/` (Docker Compose), never the real install. `tests/E2e/` is reserved for PHPUnit end-to-end tests.

## Checks
```bash
composer cs          # PSR-12 (core, tests)
composer cs:wp       # WordPress Coding Standards + PHPCompatibilityWP (adapter)
composer stan        # PHPStan level 8, no baseline
composer test        # unit + integration
composer test:wp     # WordPress integration tests (single site)
composer test:wp:ms  # same environment as multisite
(cd apps/admin && npm run typecheck && npm run lint && npm run test:coverage)   # vue-tsc, ESLint+Prettier, Vitest
bin/test-e2e.sh       # starts tests/e2e-env (Docker: throw-away WordPress + DB), runs Playwright from e2e/, tears down
```
`composer check` = style + analysis + tests. Documented lint exceptions live in `phpcs.xml.dist` / `phpcs-wordpress.xml.dist`.
PHPStan covers all production PHP (core, adapter, plugin file, `uninstall.php`); `bin/` is not analysed; WordPress symbols/stubs live in `tests/phpstan/`.

## Build
`bin/build-zip.sh` creates `dist/sw-gallery-<version>.zip` (production Composer dependencies, compiled admin SPA/blocks/frontend). Build machine needs
Composer and Node.js; the target host needs neither. Bundled PHP libraries are prefixed (Mozart, namespace `SWGallery\Dependencies\`) to avoid collisions.
Release/tag/signing flow: decision D8 in `decisions.md`, task M5-17 in `TODO.md`.

## Dependency security
`composer audit --locked --no-dev` and `npm audit --omit=dev --audit-level=high` fail CI on advisories in shipped dependencies; a daily workflow audits
all dependencies. Node version pinned in `apps/admin/.nvmrc`.

## SonarQube Cloud
CI uploads merged PHP Clover coverage and `apps/admin/coverage/lcov.info`; project mapping and source paths are in `sonar-project.properties`.
Coverage gate: line coverage **strictly above 80 %**, PHP and JS/TS separately (`tests/coverage/check.php`, `apps/admin/scripts/check-coverage.mjs`;
Vitest thresholds alone would accept exactly 80 %). Unmeasured production code is not excluded.

## Conventions
See `AGENTS.md`, `CODE-STYLE.md`, `SPECIFICATION.md`, `decisions.md`, `translations.md`, `hooks.md`.
