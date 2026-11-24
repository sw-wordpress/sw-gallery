# Changelog

User-facing changes summarized from the repository's [existing changelog](https://github.com/sw-wordpress/sw-gallery/blob/main/CHANGELOG.md). Dates below are the recorded version dates, not a new claim that an artifact has been published. Current documentation can describe implemented behavior not yet assigned a release version.

## Unreleased

### Feature

- Set the focal point in image details to keep the subject visible in cropping layouts.
- Show image titles/captions on hover or focus with four animations; set defaults site-wide or per gallery.
- Set **Images per page** per gallery, with a shortcode override when needed.
- Let AI assistants read and edit galleries through the MCP Adapter plugin on WordPress 6.9+, with access off by default and an audit trail for changes. The [MCP guide](ai-assistants.md) covers setup and creating a Masonry gallery from the Media Library.
- Choose the MCP access level in Gallery Settings. Additional levels allow permanent removal with a strict JSON boolean confirmation and administration of display settings and diagnostics.

### Bugs

- Overview and System Status warn when the database schema is newer than the plugin, and saving gives an actionable error.
- Enforce gallery passwords when reading or copying galleries through the management interfaces.

### Other topics

- Share MCP input schemas and permission checks across gallery tools to keep their behavior consistent.
- Add searchable user documentation covering installation, editing, layouts, embedding, permissions, privacy and troubleshooting, with a navigable changelog and genuine demo screenshots of the overview, gallery list and Bubbles frontend.
- Layout-setting explanations are available behind **?** help buttons to keep the sidebar shorter.

## 0.9.0 — 2026-11-17

### Feature

- Add verified signed GitHub update offers in the native plugin list when trust and release setup are configured; initial installation remains manual.
- Add Overview, Media Overview, Settings and System Status, gallery usage counts, uploads and adding a media selection to a gallery.
- Preview layout/settings in a sandboxed editor dialog; adjust editor tile size and retain unsaved state when a session expires.
- Add Justified and Masonry layouts, Media Library usage links and confirmation before deleting images used in galleries.

### Bugs

- Keep Grid image width/height ratios to avoid layout shifts.
- Prevent item changes on trashed galleries and show dialogs above the WordPress admin menu.

### Other topics

- Support galleries up to 10,000 images; refuse writes after a database-schema downgrade while preserving reads and rendering.
- Respect block-theme content widths for shortcode output and improve RTL/site-timezone display.
- Remove all gallery statuses on opted-in uninstall, without deleting attachments.
- Provide privacy-policy guidance, English/German documentation and reproducible release packages; strengthen input/encoding validation and dependency checks.

## 0.1.0 — 2026-11-14

### Feature

- Introduce gallery creation/editing, image relations and ordering, local text overrides, role-based access and multisite support.
- Provide the gallery administration app, Grid/Justified/Masonry layouts, lightbox, block, shortcode and PHP embedding.
- Integrate Media Library usage/deletion checks and translated English/German interfaces.

### Other topics

- Recorded as the first development version, explicitly **not released** in the existing history.
