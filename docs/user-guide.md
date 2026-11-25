# User guide

For site editors and administrators. Menu: **Galleries** in the WordPress admin (the app opens on the **Overview**; navigation: Overview,
Galleries, Media Overview, Settings, System Status). Developers: [architecture.md](architecture.md), [extending.md](extending.md).

## Galleries

- **Create:** Galleries > **New gallery**, enter a title (and an optional plain-text description). A new gallery is always a **draft**.
- **List:** search, filter by status (All, Published, Drafts, Private, Trash), grid or table view. The actions menu of a gallery offers
  **Edit images**, **Edit details**, **Duplicate** (the copy is a draft owned by you; images are not copied), **Copy shortcode**,
  **Move to trash**, **Restore as draft** and **Delete permanently** (asks you to type the title).
- Galleries only *reference* images of the Media Library. Removing an image from a gallery, trashing or deleting a gallery never deletes the image.

## The editor

Open **Edit images**. Gallery details, layout and settings are in the side panel; the images are in the grid.

| Task | Mouse | Keyboard |
|---|---|---|
| Add images | **Add images** (WordPress media picker), or drop files into the editor (upload; JPEG, PNG, GIF, WebP, AVIF, up to 64 MB each) | |
| Select | click, Shift-click for a range | arrow keys move, Space selects, Ctrl/Cmd+A selects all |
| Reorder | drag and drop, or the toolbar buttons (earlier, later, to start, to end) | Ctrl/Cmd + arrow keys, Home, End |
| Details | click an image | Enter |
| Remove from gallery | **Remove** (asks first) | Delete |

- **Staged editing:** removals, order, texts, cover, status, layout and settings are applied when you press **Save**; **Undo/Redo** and
  **Discard changes** cover them. Adding images happens at once (undo = remove).
- **Per-image texts:** in the image details, title, alternative text and caption can be overridden for this gallery only ("Use gallery-specific
  text"). Without an override the Media Library text is used; an empty override shows nothing. The Media Library itself is never changed.
- **Cover image:** "Use as cover" in the image details or the selection toolbar; without a cover the first image is used.
- **Order on the website:** manual (default), date or title, ascending or descending. Your manual order stays stored.
- **Conflicts:** if somebody else saved the gallery meanwhile, a dialog lists the differences; you can re-apply your changes or discard them.
- **Preview:** the **Preview** button opens the live preview dialog. It shows your unsaved layout, sort order and settings, but not unsaved
  reordering, removals or text edits; close it to change settings. Notes for editors (for example "not published") appear there only.
- Galleries with more than 10,000 images load the first 10,000 and cannot be reordered (supported maximum, [performance.md](performance.md)).

## Layouts and their settings

| Layout | Settings |
|---|---|
| Grid | columns (1-12, per screen size), gap (0-64 px), aspect ratio (auto, 1:1, 4:3, 3:2, 16:9) |
| Justified | row height (80-600 px), gap (0-64 px), last row (natural height or stretched) |
| Masonry | columns (1-12, per screen size), gap (0-64 px), corner radius (0-64 px, default 0) |

Values you do not change use the defaults shown under each field ("Reset to default"). Responsive fields have a value per screen size
(small, medium, large, extra large); an unset size inherits the next smaller one. The default layout and the page size for new galleries come
from **Settings**. A gallery whose layout is no longer available is shown as grid.

## Who can see a gallery on the website

Status of the gallery -> what visitors of a page that embeds it get (decision D4 in [decisions.md](decisions.md)):

| Status | Visitors | Logged-in users |
|---|---|---|
| Published | the gallery | the gallery |
| Published with a password | WordPress' password form until the password was entered | the same form (the editor preview ignores the password) |
| Private | nothing | the gallery for users who may read private galleries |
| Draft, pending review, scheduled, trash | nothing | nothing; users who may edit the gallery see a short note instead |

Publishing (status Published or Private) needs the publish right: contributors can save drafts and submit for review. The admin app has no
field for a gallery password in 1.0 (it only shows the "Password protected" state). Hiding applies to the gallery **markup**, not to the image
files: see Privacy below.

## Focal point

Layouts that crop images (grid with a fixed aspect, honeycomb, jigsaw, scattered, mosaic, bubbles, shattered, magazine) show the centre of a picture by default. Open
the image details and click (or drag) in the picture to set the part that must stay visible; the square crop below shows the effect, the sliders allow exact
values. The focal point belongs to the image and applies in every layout.

## Hover texts

In the editor (layout panel, "Hover texts") a gallery shows the image **title** and/or **caption** when a visitor hovers or focuses an image, with one of four
animations: slide in from the bottom, slide in from the top, full-surface overlay, fade in. The same effect works for every layout. "Use the site default" takes
the values of the Settings page. On touch screens the texts are always visible.

## Embedding

- **Block:** insert **SW Gallery**, choose the gallery; optional layout, columns, gap and eager loading in the block sidebar; alignment and
  spacing come from the block toolbar.
- **Shortcode:** `[sw_gallery id="123"]`. Optional attributes: `layout` (a registered layout id such as `masonry`), `columns` (1-12), `gap` (0-64),
  `per_page` (1-200, overrides the gallery's "Images per page"), `page` (>= 1, the initial page). Unknown attributes are ignored and invalid values dropped silently. Without a valid `id` visitors see nothing,
  users who can edit posts see a short note. In feeds a plain image list is output.
- **PHP:** `echo swg_render_gallery( 123, array( 'layout' => 'grid', 'columns' => 4, 'eager' => true ) );` (same keys, plus `eager`; returns an
  empty string when nothing may be shown).
- Long galleries are split into pages (page size from Settings; real links, also without JavaScript). The lightbox opens an image on click, closes
  with Escape (or its button), moves with the arrow keys, Home and End.
- Details and the one difference between surfaces (the shortcode wrapper): [rendering.md](rendering.md).

## Media Overview

Galleries > **Media Overview** lists the images you may use (search, sort, uploader filter, grid or table, paging), shows how many galleries use
each, and offers **Add to gallery** for a selection (only galleries you can edit). It is read-only otherwise; titles and files are edited in
the WordPress Media Library. Needs the right to upload files.

## Settings

Galleries > **Settings** (administrators).

| Setting | Default | Meaning |
|---|---|---|
| Default layout | Grid | layout of new galleries and of galleries without their own |
| Images per page | 24 (1-200) | page size where an embed sets none |
| Open images in a lightbox | on | visitors can enlarge images |
| Delete all plugin data when the plugin is deleted | off | see Uninstall below; turning it on needs a typed confirmation word (`DELETE`, German `LÖSCHEN`) |

## System Status

Galleries > **System Status** (administrators) is a read-only health report without paths, hosts or secrets: **Database schema** (stored vs
current version, item table present; a stored version newer than the plugin means a downgrade: reading and rendering work, saving is refused until
the plugin is updated), **Counts** (galleries by status, image relations, item rows, *orphan relations* that point to deleted images and are skipped
when rendering; repair with `wp swg repair --apply`), **Recent errors** (last 20, no messages with paths), **Versions**, **Runtime** (cache,
whether the admin app build is present) and **Good to know** (including the export limitation). **Copy summary** puts a diagnostic text on the
clipboard for support requests.

## Media Library

In the Media Library (list view column **Galleries**, and the attachment details field) you see how many galleries use an image and links to
those you may edit. Permanently deleting an image that galleries use asks for confirmation; the image is then removed from those galleries.

## Permissions by role

Galleries use WordPress' post capabilities (decision D1; no roles are changed). The server enforces everything; the app only hides what you
cannot do.

| Role | Can |
|---|---|
| Subscriber | no access to the admin app |
| Contributor | create, edit and delete own galleries as drafts or pending review; cannot publish; may use library images they can read but cannot upload |
| Author | as contributor, plus publish own galleries and upload images |
| Editor | manage every gallery, publish, change the owner of foreign galleries |
| Administrator | everything, plus Settings and System Status (`manage_options`) |

Rules for ownership, images and link names (1.0):

- Changing the owner of a gallery needs the right to edit others' posts (`edit_others_posts`, also for the current owner); the new owner must exist, be able to edit posts and (multisite) belong to the site.
- Contributors may add images they can read in the Media Library but cannot upload new ones.
- Thumbnail links of images without alternative text get an `aria-label`: the image title, otherwise "Image N of M".

Multisite: permissions and data are per site; a super admin has the site administrator's rights.

## Accessibility

Keyboard operation, screen reader behaviour and known gaps: [accessibility.md](accessibility.md). The editor announces changes in a live region;
the preview, dialogs and menus trap and restore focus.

## Privacy and limits

- Private, draft and password protected galleries hide the gallery markup, **not the image files**: anybody who knows an upload URL can load it.
- No right-click, hotlink or download protection.
- Photo metadata (EXIF, GPS) stays in the image files unless you remove it before upload.
- The plugin sends no data anywhere and sets no cookie of its own (password galleries use WordPress' own cookie); a suggested privacy text
  is under Settings > Privacy > Policy Guide.
- Galleries are not exported: WordPress export and migration tools move galleries without their images and attachment IDs change.

## Troubleshooting

| Symptom | Check |
|---|---|
| The gallery shows nothing | Status must be Published (visitors) or you must be allowed to see it; open the **Preview** for editor notes. The gallery may be empty or all its images unavailable (deleted, wrong type: SVG and HEIC are not supported), see System Status > orphan relations. A wrong `id` prints nothing for visitors. |
| Changes do not appear on the website | Eligible gallery HTML can be cached for up to 12 hours in WordPress transients. Gallery and image changes invalidate the render cache, and a page cache or CDN in front may need a purge (`swg_gallery_changed`, [hooks.md](hooks.md)). |
| The admin page is empty or "build missing" | The plugin was not installed from the release ZIP (no `assets/admin`). Reinstall from the ZIP or build ([development.md](development.md)). System Status > Runtime shows it. |
| "Newer schema" or saving is refused (503) | The database was upgraded by a newer plugin version and this older one was installed afterwards: install the newer version again. |
| Saving reports a conflict | Somebody else saved the gallery; use the conflict dialog. |
| Upload fails | Type (JPEG, PNG, GIF, WebP, AVIF where the server supports it), size limit of the server (`upload_max_filesize`), your role needs the right to upload files. |
| Need more detail | System Status, **Copy summary**; with `WP_DEBUG_LOG` on, plugin errors also go to `debug.log`. |

## Uninstall and data retention

Deactivating changes nothing. Deleting the plugin keeps all galleries, relations and settings unless the setting **Delete all plugin data when the
plugin is deleted** was on (per site on multisite): then the galleries, the item table, the plugin's options and transients are removed.
Images in the Media Library are never deleted.
