# Troubleshooting

| Problem | What to check |
| --- | --- |
| No gallery on the website | Check gallery ID, images, gallery **Published** status and the containing page's visibility. Use **Preview** for editor-only diagnostics, then retest logged out. Private/draft galleries are not public; unsupported/deleted images are skipped. |
| Changes missing in preview | Preview uses saved membership, order and texts. Save those first. Unsaved layout, sorting and settings can be previewed. |
| Changes missing on the website | Save the gallery and update the page. Published fragments can be cached for up to 12 hours but gallery/image changes invalidate the plugin cache. A separate page cache or CDN may need purging. |
| Blank admin page or missing build | Reinstall the release ZIP, not a GitHub source archive. **System Status → Runtime** reports missing admin assets. A developer checkout must build them using the [development guide](https://github.com/sw-wordpress/sw-gallery/blob/main/docs/development.md). |
| No access or no Publish action | Check your role and ownership with an administrator. Contributors can submit drafts for review, not publish. Media Overview requires upload permission; Settings/System Status require administration permission. |
| Upload rejected | Use JPEG, PNG, GIF, WebP or AVIF supported by your server. Check upload permission and server size limits; the server may allow less than the editor's 64 MB limit. SVG/HEIC are unsupported. Retry failed files; check whether successfully uploaded files already exist in the Media Library. |
| Session expired | Keep the editor tab open. Choose **Log in again** in a new tab, then **I have logged in, continue** in the original editor. Unsaved input stays in that tab. |
| Saving conflict | Compare the dialog's server changes with your unsaved changes. Re-apply or discard deliberately; re-applied changes still need **Save**. |
| Interrupted save | Read the saved/not-saved summary. The editor retains remaining staged changes; do not assume everything failed or blindly repeat actions. |
| Newer database schema / saving refused | A newer plugin version upgraded the schema. Reinstall a compatible newer plugin; do not attempt to “fix” it by manually reducing the schema version. Reading/rendering can continue during this state. |
| Layout unavailable | The renderer falls back to Grid. Select an available layout and save, or restore the trusted extension providing the missing one. |
| Images missing after deletion/migration | Check Media Library files, attachment IDs and orphan relations. The plugin does not implement export/import remapping or recover deleted files. Restore from a tested backup where needed. |

## System Status and support

Administrators open **Galleries → System Status**. The read-only report includes **Database schema**, **Counts**, **Recent errors**, **Versions**, **Runtime** and **Good to know**. Orphan relations point at missing attachments and are skipped during rendering. The recent-error list is bounded to the latest 20 entries.

Use **Copy summary** for a support request. Review even sanitized diagnostics before sharing; never send login credentials or private image URLs. Include the action, expected/actual result, plugin/WordPress versions and whether the issue also occurs with a small gallery of non-sensitive test images. With `WP_DEBUG_LOG` enabled, an administrator can also inspect the site's `debug.log`; do not publish the full log without checking for information from other plugins.

Administrators/developers can investigate orphan relations with `wp swg repair`. Do not run `wp swg repair --apply` on production without a backup and an understanding of the changes. See [development and repair tools](https://github.com/sw-wordpress/sw-gallery/blob/main/docs/development.md) and [security reporting](https://github.com/sw-wordpress/sw-gallery/blob/main/SECURITY.md).

Keyboard behavior and known accessibility limits: [accessibility documentation](https://github.com/sw-wordpress/sw-gallery/blob/main/docs/accessibility.md).
