# Integrations with sibling plugins (M4-11)

SPECIFICATION §7 allows optional integrations with `sw-image-optimizer`, `sw-slider`, `sw-forms`, `sw-backupit` and `sw-cloudcalendar`, **without hard runtime dependencies**.

**Verified:** a search of `sw-gallery.php`, `uninstall.php`, `packages/`, `assets/`, `apps/` and `composer.json` finds no reference to any of these plugins (no `class_exists`, `is_plugin_active`, `Requires Plugins` header, shared classes or option reads). The only mentions are in documentation: D8/D14 (design and update mechanism were copied *self-contained* from sw-backupit and the other sw-* plugins). sw-gallery runs on its own.

**Implemented dedicated integrations: none.** No concrete contract (hook, shortcode, data format) exists on the other side that sw-gallery could code against; building one speculatively would be a fake implementation. What exists today is generic and works for any plugin:

| Need | Integration point today |
|---|---|
| Show a gallery in another plugin's output (slider slide, form confirmation, calendar event) | `[sw_gallery id="123"]` (processed by `do_shortcode()`), or `swg_render_gallery( $id )` in PHP (`docs/extending.md`) |
| Edit-side embedding | block `sw-gallery/gallery` |
| Read galleries / items from outside | REST `swg/v1` (`docs/rest-api.md`), capability checked |
| React to gallery changes (backup, cache, optimizer) | `swg_<event>` actions and `swg_gallery_changed( $gallery_id )` (`docs/hooks.md`) |
| Image optimization | none needed: sw-gallery stores only attachment relations and uses WordPress sub-sizes (`wp_get_attachment_image_*`); an optimizer that regenerates sub-sizes or changes files is picked up through the media epoch (`wp_update_attachment_metadata`, `_wp_attached_file`) |
| Backup | the data is a post type (`swg_gallery`), post meta (`_swg_*`) and the table `{prefix}swg_gallery_items`; attachments stay in the Media Library (nothing is copied) |
| Additional layouts / import sources | `swg_register_layouts`, `swg_register_import_providers` |

A sibling plugin that wants a deeper integration can use these points today; a dedicated bridge is added only when a concrete contract is agreed (candidate for 1.1, none scheduled). Any such code must stay optional (feature detection at runtime, no hard dependency) per SPECIFICATION §7.
