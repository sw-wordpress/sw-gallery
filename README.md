# SW Gallery

*Open source by Stefan Warnat – free to use, free to change, better together.*

[![Tests](https://github.com/sw-wordpress/sw-gallery/actions/workflows/tests.yml/badge.svg)](https://github.com/sw-wordpress/sw-gallery/actions/workflows/tests.yml)
[![JavaScript](https://github.com/sw-wordpress/sw-gallery/actions/workflows/js.yml/badge.svg)](https://github.com/sw-wordpress/sw-gallery/actions/workflows/js.yml)
[![E2E](https://github.com/sw-wordpress/sw-gallery/actions/workflows/e2e.yml/badge.svg)](https://github.com/sw-wordpress/sw-gallery/actions/workflows/e2e.yml)
![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4?style=flat-square&logo=php&logoColor=white)
![WordPress 6.4+](https://img.shields.io/badge/WordPress-6.4%2B-21759b?style=flat-square&logo=wordpress&logoColor=white)
[![License GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue?style=flat-square)](LICENSE)

Gallery management and presentation on top of the WordPress Media Library. Galleries only reference attachments:
images are never copied, moved or deleted by gallery operations.

## Features

- **Gallery management** in an admin app (Vue 3, Quasar): list with search and status filter, editor with drag and drop and keyboard
  reordering, per-gallery image texts (title, alt, caption), cover image, undo/redo, conflict detection, live preview dialog.
- **Media Overview**, **Settings** and **System Status** pages; German and English, RTL aware, light and dark theme.
- **Layouts:** grid, justified and masonry, with responsive columns, gap, row height and corner radius where the layout has them;
  link-based paging that works without JavaScript; accessible lightbox as progressive enhancement; responsive images.
- **Embedding:** block `sw-gallery/gallery`, shortcode `[sw_gallery]`, PHP function `swg_render_gallery()`; all use one renderer and one access policy.
- **Access:** capability based (galleries use the `post` capability type), draft, pending, private, published and password states.
- **Media Library integration:** "used in n galleries" column and field, confirmation before deleting an image that galleries use.
- **WP-CLI:** `wp swg render`, `wp swg repair`.
- **Extensible:** layouts and import providers via PHP registries, documented hooks, REST API `swg/v1` ([docs/extending.md](docs/extending.md)).
- Multisite, per-site data.

### Not in 1.0

Planned, not implemented (backlog: [TODO.md](TODO.md#post-10-backlog-not-started-before-m5-is-done-split-into-tasks-when-scheduled)):
collections and categories (1.1), design presets (1.1), more layouts such as carousel, focus and mosaic (1.1, candidates in
[docs/layout-proposals.md](docs/layout-proposals.md)), import and export of galleries (1.2), external image sources such as cloud storage.
Galleries have no URL of their own; they are embedded in posts and pages.

## Requirements

PHP 8.2+, WordPress 6.4+, MySQL or MariaDB. Continuous integration tests PHP 8.2, 8.3 and 8.4 with WordPress 6.4, 6.9 and the latest release
(plus MariaDB and MySQL variants; WordPress trunk experimentally), see [docs/development.md](docs/development.md#supported-matrix).
The release ZIP contains the built admin app; no Composer or Node.js is needed on the server.

## Installation

Download `sw-gallery-<version>.zip` from the [GitHub releases](https://github.com/sw-wordpress/sw-gallery/releases) and upload it under
**Plugins > Add New Plugin > Upload Plugin**, or run `wp plugin install sw-gallery-<version>.zip --activate`. Nothing is published to wordpress.org and
installation is always manual. Verified GitHub offers are available in the native Plugins screen once a trusted public key and signed releases are configured; the shipped public key still needs trusted initial distribution. See [update operations](docs/GITHUB-UPDATES-BETRIEB.md) and [building releases](docs/release.md).

## Quick start

1. Open **Galleries** in the admin menu, click **New gallery**, enter a title and create it (it starts as a draft).
2. Open **Edit images**, click **Add images** (or drop files into the editor), drag to reorder, **Save**, set the status to **Published**.
3. Embed it (the list menu **Copy shortcode** gives the complete shortcode with the id):
   - Block editor: insert the block **SW Gallery** and pick the gallery.
   - Shortcode: `[sw_gallery id="123"]`, optionally `layout="masonry" columns="4" gap="12"`.
   - PHP: `echo swg_render_gallery( 123, array( 'columns' => 4 ) );`

User documentation: [SW Gallery guide](https://sw-wordpress.github.io/sw-gallery/) ([sources](pages/index.md), [changelog](pages/Changelog.md)). Publication requires the Pages setup described in the [documentation maintainer guide](pages/README.md), which also covers isolated builds and updates.

Existing task-oriented reference: [docs/user-guide.md](docs/user-guide.md).

## AI assistants via MCP

On WordPress 6.9 or newer, the [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) exposes SW Gallery's `sw-gallery/*` abilities through its default MCP server at `/wp-json/mcp/mcp-adapter-default-server`.
An MCP client connects over HTTPS with a WordPress user name and application password. SW Gallery starts with MCP access off. Enable `read` to inspect galleries, or `write` to create and edit them within the user's WordPress permissions.
See the [MCP setup and Masonry example](pages/ai-assistants.md) for configuration and the gallery workflow.

## Extending

Custom layouts, hooks, REST API and theming (`--swg-*` custom properties): [docs/extending.md](docs/extending.md), [docs/hooks.md](docs/hooks.md),
[docs/rest-api.md](docs/rest-api.md). Structure of the code: [docs/architecture.md](docs/architecture.md).

## Development

```bash
composer install && (cd tests/wp && composer install)
composer check                        # version, style, static analysis, unit tests
```
Everything else (WordPress suite with a private database, JavaScript projects, Playwright, ZIP build): [docs/development.md](docs/development.md).
Contribution rules: [CONTRIBUTING.md](CONTRIBUTING.md).

## Security and privacy

Reporting: [SECURITY.md](SECURITY.md); review and threat model: [docs/security.md](docs/security.md).

- **No telemetry, no external requests.** The plugin makes no outbound HTTP requests and loads no remote scripts, fonts or images. Verified:
  no HTTP client call exists in `packages/`, and a test (`DangerousCodeScanTest`) fails when one is added. Fonts of the admin app are bundled.
- **Cookies:** the plugin sets none. Password protected galleries use WordPress core's password form, which sets core's `wp-postpass_*` cookie.
- Images are served from the site's own Media Library; **EXIF/GPS metadata stays in the served files** unless you remove it before upload.
- **Private, draft and password protected galleries hide the gallery markup, not the image files:** anybody who knows an upload URL can fetch it.
  There is no right-click, hotlink or download protection.
- **Registered layouts are trusted code.** A layout added through `swg_register_layouts` runs with full PHP rights and must escape its own output.
- **No export in 1.0.** WordPress export and migration tools move galleries without their images and attachment IDs change on import, so galleries
  do not survive such a migration intact. A gallery export with ID remapping is planned (decision D12 in [docs/decisions.md](docs/decisions.md)).
- Deleting the plugin keeps all data unless the plugin setting **Delete all plugin data when the plugin is deleted** (Galleries > Settings) is on; images are never deleted.
- A suggested privacy policy text is added under **Settings > Privacy > Policy Guide**.

## License

Copyright (C) 2026 Stefan Warnat. GPL-2.0-or-later, see [LICENSE](LICENSE). Bundled third-party components: [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).
