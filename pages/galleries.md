# Manage galleries and images

## Find and organize galleries

In **Galleries**, use **Search galleries**, the status filter (**All**, **Published**, **Drafts**, **Private**, **Trash**) and grid/table view. Each gallery's actions include:

| Action | Result |
| --- | --- |
| **New gallery** | Creates a draft with a title and optional plain-text description. |
| **Edit details** | Changes the title, description or status, subject to your permissions. |
| **Duplicate** | Creates a draft owned by you, referencing the same images without copying files. |
| **Copy shortcode** | Copies an embed with the gallery ID; if clipboard access is unavailable, copy the selected text manually. |
| **Move to trash** | Hides the gallery but keeps it available for restoration. |
| **Restore as draft** | Restores a trashed gallery; publish it again when ready. |
| **Delete permanently** | Removes the gallery and its image relations after you type its title to confirm. Cannot be undone. |

Removing a gallery or removing an image **from a gallery** never deletes the image file from the Media Library.

![Galleries grid with five published demo galleries, search, status filters and New gallery action](/screenshots/sw-gallery-list.png)

*Demo: the Galleries grid on the WordPress test site; each sample gallery references 24 images.*

## Add and arrange images

Open **Edit images**. **Add images** opens the WordPress media picker; select images and choose **Add to gallery**. You can also drop files into the editor or choose **Upload images** when you have upload permission. JPEG, PNG, GIF, WebP and AVIF are supported where the server accepts them. The editor checks a 64 MB file limit; the server's limit can be lower. SVG and HEIC are not supported.

Adding images is applied immediately. If an upload succeeds but assignment fails, the file can remain in the Media Library without being in the gallery; use **Add them to the gallery** for unassigned uploads or select the file again later. Cancelling an upload is not a way to delete an already uploaded attachment.

| Task | Mouse or toolbar | Keyboard |
| --- | --- | --- |
| Select | Click; Shift-click selects a range | Arrow keys move focus; Space selects; Ctrl/Cmd+A selects all |
| Reorder | Drag, **Move earlier**, **Move later**, **Move to start**, **Move to end** | Ctrl/Cmd plus arrow keys, Home or End |
| Image details | Open the image's details | Enter |
| Remove selection | **Remove**, then confirm | Delete |

**Order on the website** can be manual, date or title (ascending/descending). Your manual order is kept even when another order is displayed. The supported maximum is 10,000 images per gallery; larger galleries load only the first 10,000 in the editor and cannot be reordered.

## Texts, cover and saving

In image details, enable **Use gallery-specific text** for **Title**, **Alternative text** or **Caption**. These overrides affect this gallery only. Without an override the Media Library text is inherited; an explicitly empty override suppresses that text. Describe informative images in alternative text. Global attachment metadata is edited in the WordPress Media Library, not here.

Choose **Use as cover** for a gallery's cover image. Without an explicit cover, the first image is used. For cropping and per-image layout settings, see [Layouts](layouts.md).

**Save** applies staged order, removals, texts, cover, status, layout and settings. **Undo**, **Redo** and **Discard changes** operate on staged changes. Adding images is immediate; undoing that addition removes the gallery relation, not the uploaded file. Do not assume that closing the editor discards an upload.

If saving is interrupted, read the notice listing what was saved and what remains staged. A save is not an all-or-nothing operation across all steps. If another editor saved meanwhile, compare the conflict dialog before choosing **Reload and re-apply my changes** or **Discard my changes**. Re-applied changes are not saved yet, and the dialog explains which values would overwrite others or be dropped.

## Preview

**Preview** opens **Live preview**, with **Mobile**, **Tablet**, **Desktop** and **Fit** widths. It uses unsaved layout, sort order and settings, but the **saved** image order, membership and texts. Save reordering, removals and text edits before expecting them in the preview. The preview's page-size control affects that preview only.

Notes such as “not published” are for editors and do not appear on the website. Previewing is not publishing: also test the real embedded page as a visitor.

## Media Overview and the WordPress Media Library

**Media Overview** requires upload permission. Search, sort, filter by uploader or switch between grid/table views, select images and choose **Add to gallery**. Only galleries you can edit can be selected. Usage counts show where images are referenced.

In the WordPress Media Library, the **Galleries** column and attachment details show usage and links to galleries you may edit. Permanently deleting an attachment there asks for confirmation when galleries use it and removes it from those galleries. This differs from the editor's **Remove from gallery**, which leaves the file intact.
