# Documentation maintenance (not published)

## Discovery and page plan

Plan recorded before authoring the user pages. Audience: WordPress editors maintaining image galleries and administrators installing/configuring the plugin. English follows README.md, docs/user-guide.md and the source UI catalogs.

Evidence: plugin header (WordPress 6.4+, PHP 8.2+, version 0.9.0), docs/user-guide.md, existing CHANGELOG.md, apps/admin/src/i18n/*en.ts, PluginSettings.php, EmbedArguments.php, BuiltInLayouts.php and the individual layout schemas. Relevant tests include tests/wp/tests/{RestSettingsTest,PreviewRouteTest,ShortcodeTest,WpGalleryAccessPolicyTest}.php and apps/admin/tests/editor/*. The README's “Not in 1.0” layout list is older than the implemented registry: document all registered layouts, but do not assign them invented release versions.

| Page | Reason |
| --- | --- |
| index.md | Factual overview and task links, not a marketing landing page. |
| getting-started.md | Requirements, release-ZIP installation and first published gallery. |
| galleries.md | Gallery lifecycle, image editing, staged changes, preview limits and Media Overview. |
| layouts.md | Choosing among the actual registered layouts, responsive settings, focal point and hover texts. |
| embedding.md | Block/shortcode steps, supported overrides, paging and lightbox controls. |
| settings-and-access.md | Real defaults, role/visibility boundaries, privacy and uninstall implications. |
| troubleshooting.md | Recovery from documented errors and safe support diagnostics. |
| Changelog.md | User-facing version history, mapped from existing entries without new historical claims. |

No separate developer API or external-import guide: link existing technical docs; cloud connectors/export are not implemented. Keep screenshots out until genuine sanitized application captures are supplied under public/screenshots/ with descriptive alt text.

## Build and update

Edit the user Markdown and update Changelog.md in the same change. Keep the root CHANGELOG.md's engineering/release history intact; when releasing, reconcile both histories against the real release notes and move Unreleased entries only to the actual version/date. Recheck UI labels and defaults against code/tests, not just older README statements.

VitePress 1.6.4 is pinned; no documentation package.json, lockfile or checkout dependency installation. From the repository root, with Node.js 22 and npm available:

```sh
DOCS_BUILD="$(mktemp -d)"
cp -R pages "$DOCS_BUILD/pages"
npm install --prefix "$DOCS_BUILD" --no-save --package-lock=false --ignore-scripts --no-audit --no-fund vitepress@1.6.4
"$DOCS_BUILD/node_modules/.bin/vitepress" build "$DOCS_BUILD/pages"
# Optional local inspection:
"$DOCS_BUILD/node_modules/.bin/vitepress" preview "$DOCS_BUILD/pages"
# Remove the temporary directory when finished.
```

Only the top-level VitePress version is pinned; transitive versions are not locked. The build's built-in dead-link check must remain enabled. README.md is excluded from the site by srcExclude. The tiny theme override makes the required footer visible with the documentation sidebar (the default theme otherwise hides it).

## Publication

.github/workflows/docs.yml builds relevant PRs, main pushes and manual runs, then uploads the Pages artifact. Only non-PR runs on main deploy, using the github-pages environment and deployment concurrency group. No PAT or gh-pages branch is required. Build permissions are contents: read; Pages/OIDC permissions belong only to deployment.

The Pages API returned 404 during setup; that does not establish whether Pages is unconfigured or inaccessible. An authorized maintainer must verify **Settings → Pages → Source → GitHub Actions** and approve any environment deployment if required. No repository settings were changed. The intended project URL is https://sw-wordpress.github.io/sw-gallery/ with base /sw-gallery/; no custom-domain file exists in the repository. A passing build is not proof of publication.

## Verification of this documentation change

The isolated VitePress 1.6.4 production build passed using the shared temporary toolchain (local Node.js 26.12.1; CI requests Node.js 22). Built-in dead-link checks remained enabled. Generated HTML checks covered all eight user pages, exact footer text, Changelog navigation, project-base links and 164 local links including anchor targets; emitted footer CSS is not hidden. No browser was used for these checks. README.html is absent, as intended. Workflow YAML was parsed and the event/permission/deployment constraints checked. git diff --check and bash -n for both existing release scripts passed. The first build timed out while rendering; a clean rerun completed in 25.45 seconds and the final screenshot-inclusive build completed in 8.17 seconds. All three screenshot references, alt texts and built asset paths were checked.

PHP, Composer and the repository's application dependencies are unavailable in this workspace, so PHP/application suites and a real plugin ZIP build were not run. This change does not edit runtime code or application dependencies; release exclusion was checked against the existing explicit staging and verification allowlists, not represented as a successful ZIP build. Hosted publication remains a separate verification step.

### Screenshot provenance

The parent task captured the actual application read-only at https://wp-demo.stefanwarnat.de/ using synthetic gallery labels. The WordPress toolbar and user identity were cropped before supplying pages/public/screenshots/sw-gallery-overview.png, sw-gallery-list.png and sw-gallery-frontend.png. These are genuine application captures, not reconstructed markup. The frontend is the “Bubble Layout” demo with 24 sample landscape/travel photographs. The supplied images were visually checked before integration; no credentials, account identity or private environment details are shown. Descriptive alt text and captions distinguish these examples from guaranteed defaults. Future captures must follow the same sanitization convention.

## Release boundary

bin/build-zip.sh stages an explicit production allowlist (PHP packages, runtime assets, translations, license and root changelog); it does not copy pages/, this README, .github/ or documentation dependencies/output. bin/verify-zip.sh rejects unlisted top-level entries, including pages/, and node_modules anywhere. Preserve this allowlist; do not add the documentation tree to plugin packages.
