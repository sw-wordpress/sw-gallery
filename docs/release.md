# Release

How a version is cut, built, verified and published. Decisions: D8 (self-update module) and D9 (1.0 via GitHub releases only) in `decisions.md`.

## Version: one source
The `Version:` header of `sw-gallery.php` is the source. `SWGALLERY_VERSION` (used for every `ver=` cache-busting parameter), the four `package.json` + lockfiles (`apps/admin`, `assets`, `assets/blocks`, `e2e`) and the newest `CHANGELOG.md` section must equal it.

```bash
bin/version.sh get                 # print
bin/version.sh check               # fails on drift; part of `composer check`, PHPUnit (VersionScriptTest) and the release workflow
bin/version.sh set 1.0.0           # writes everywhere, turns "## [Unreleased]" into "## [1.0.0] - <today>", then checks
```
Semver with optional pre-release suffix (`1.0.0-rc.1`). The compare links at the end of `CHANGELOG.md` are maintained by hand. `composer.json` has no `version` (Composer derives it; the ZIP does not ship it).

## Procedure
1. Collect the changes under `## [Unreleased]` in `CHANGELOG.md` (Keep a Changelog).
2. `bin/version.sh set <semver>`, review the diff, commit ("Release <semver>").
3. Run the local gates (`composer check`, `composer test:wp`, JS gates, `bin/test-e2e.sh`, see `development.md`) or let CI do it.
4. Optional dry run: `bin/build-zip.sh && bin/verify-zip.sh dist/sw-gallery-<semver>.zip && bin/test-zip-install.sh dist/sw-gallery-<semver>.zip`.
5. Tag and push: `git tag v<semver> && git push origin v<semver>` (tag pattern `v?X.Y.Z` or `v?X.Y.Z-suffix`; the leading `v` is optional). The owner decides when; nothing pushes or tags automatically.

## What `release.yml` does
`version` (tag equals header version, `bin/version.sh check`) -> the three CI suites as reusable workflows (`tests.yml` incl. the PHP x WordPress matrix, `js.yml`, `e2e.yml`; the SonarQube upload skips itself because no secrets are passed) -> `build` (`bin/build-zip.sh`, `bin/verify-zip.sh`, SHA-256 file, both uploaded as workflow artifact) -> `install-test` (`bin/test-zip-install.sh` on the downloaded artifact) -> `publish` (GitHub release with the ZIP and `.sha256`, notes = the `CHANGELOG.md` section; stable tags create a normal release, tags with a suffix a pre-release). Permissions are `contents: read`, only `publish` has `contents: write`; no secrets; actions pinned like in the other workflows. **No signing yet** (see below), a marked hook sits in the `build` job.

## The ZIP
`bin/build-zip.sh [dir]` -> `dist/sw-gallery-<version>.zip`, top-level folder `sw-gallery/`. Needs bash, zip, node/npm, php, composer, msgfmt (gettext); no Docker. It runs `npm ci` + build for `apps/admin`, `assets`, `assets/blocks` (`SWG_ZIP_REUSE_NODE_MODULES=1` skips `npm ci`, local only), checks translations (`bin/i18n.sh check`), copies the allowlist below into a staging directory, compiles the `.mo` files from the `.po` files there, runs `composer install --no-dev --classmap-authoritative` **in the staging copy** (never the working `vendor/`) and zips a sorted file list with normalised modes and mtimes (`SOURCE_DATE_EPOCH`, default: last commit time). Same commit -> byte-identical ZIP. It fails when it changed the working tree.

Shipped: `sw-gallery.php`, `uninstall.php`, `CHANGELOG.md` (+ `LICENSE`/`README.md`/`readme.txt` if present), `packages/**/*.php`, `vendor/` (generated autoloader only), `languages/*.{pot,po,mo,json}`, `assets/build/`, `assets/admin/` (incl. fonts and `.vite/manifest.json`), `assets/blocks/block.json` + `assets/blocks/build/`, `assets/media-library/media-library.js`.
Not shipped: tests, `e2e`, `apps`, `bin`, `docs` (docs live in the repository), `node_modules`, source maps, lockfiles, `composer.json`, dev tooling, dotfiles. There are **no runtime Composer dependencies**, so Mozart prefixing is not needed; the script stops with an error when one is added so the prefixing step (`SWGallery\Dependencies\`) is not forgotten.
The block JSON catalog is named after the md5 of the **path** `assets/blocks/build/index.js` (WordPress rule), not of its content: the build order of blocks and `bin/i18n.sh` does not matter; the verifier checks the name.

## Verification
`bin/verify-zip.sh <zip>` (static, also run by the workflow): intact ZIP named `sw-gallery-<semver>.zip` with everything below `sw-gallery/`; top-level and `assets/` allowlists; forbidden paths (tests, dev dependencies, `.env*`, `.npmrc`, lockfiles, `composer.json`, `*.dist`, source maps unless `SWG_ZIP_ALLOW_SOURCEMAPS=1`, TypeScript/Vue sources, coverage, docs, bin, e2e, apps, VCS/CI/editor files); content heuristics (private keys, GitHub/AWS/Slack/npm tokens, absolute developer paths such as `/var/www`, `/home/<user>`); header version = file name = `SWGALLERY_VERSION`, real Plugin URI, matching `CHANGELOG.md` section; `php -l` on every PHP file and a loading autoloader; required files (block, manifests with every referenced file, `.mo` per `.po`, block JSON catalog); size budget 3 MiB (`SWG_ZIP_MAX_BYTES`; the 0.1.0 ZIP is ~0.9 MiB). `TODO` markers are not checked (minified code). `VerifyZipScriptTest` builds tiny fake ZIPs and proves acceptance and rejection.

Install test: `bin/test-zip-install.sh <zip> [--keep]` verifies the ZIP, starts a throw-away WordPress + MariaDB (`tests/e2e-env/compose.zip.yml`, random port, own compose project, removed afterwards), installs the ZIP with `wp plugin install` + `activate` (source tree **not** mounted) and runs `e2e/zip/zip-smoke.spec.ts` via `e2e/playwright.zip.config.ts`: plugin active in the right version and admin app mounts with `ver=`, gallery created and rendered by the shortcode with a working lightbox, block editor registers the block from the ZIP, no failing plugin request or console error; afterwards `debug.log` must contain no PHP notice/warning/deprecation/fatal from `plugins/sw-gallery/`. `SWG_E2E_WP_IMAGE`/`SWG_E2E_CLI_IMAGE` select another WordPress/PHP. The normal `bin/test-e2e.sh` is unchanged.

## Dependency audit
`.github/workflows/security-audit.yml` (daily, manual, PRs touching a lockfile) runs `bin/security-audit.sh`: shipped dependencies (`composer audit --locked --no-dev`, `npm audit --omit=dev --audit-level=high` for `apps/admin`, `assets`, `assets/blocks`) fail the run; development tooling and the dev-only `e2e` project are recorded (job summary, artifact `audit-report`) but do not block.

## Open decisions (owner)
- **Signing:** no key, no signature yet. The ZIP is accompanied by a SHA-256 file only. Decide key type/storage (e.g. minisign or GPG, secret in the repository) and whether the D8 self-updater verifies it; then add the step at the marked hook in `release.yml`.
- **D8/D9:** the self-update module (copy from `sw-backupit`) is not part of this ZIP; 1.0 is distributed through GitHub releases only. The ZIP content is wordpress.org-compatible.
- **When to bump to 1.0.0** and create the first tag. Nothing here bumps or tags.
- No `LICENSE` file exists in the repository (the header declares GPL-2.0-or-later); the build ships it automatically once it is added.
