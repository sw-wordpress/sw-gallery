# Settings, access and privacy

## Site-wide settings

Administrators open **Galleries → Settings**. Change a field and choose **Save settings**; **Discard changes** resets unsaved edits.

| Setting | Initial default | Meaning |
| --- | --- | --- |
| **Default layout** | Grid | Default where a gallery does not choose its own. |
| **Images per page** | 24 | Integer 1–200. A gallery-specific value or embed override takes precedence. |
| **Open images in a lightbox** | On | Enables the enhanced image viewer. |
| **Show the title on hover** / **Show the caption on hover** | Both off | Gallery hover defaults; a gallery can override these. |
| **Animation** | Fade in | Default hover animation. |
| **Delete all plugin data when the plugin is deleted** | Off | Destructive uninstall opt-in; see data retention below. |

## Roles and visibility

The plugin uses WordPress post capabilities; custom role configurations can differ from these standard roles. The server checks permissions, not only the visible controls.

| Standard role | Gallery capabilities |
| --- | --- |
| Subscriber | No administration-app access. |
| Contributor | Create/edit/delete own drafts and submit for review; cannot publish or upload. Can use readable Media Library images. |
| Author | Also publish own galleries and upload images. |
| Editor | Manage other users' galleries and publish; ownership changes need permission to edit others' posts. |
| Administrator | Also access Settings and System Status. |

On multisite, data and permissions are site-specific. A new owner must exist, be allowed to edit posts and belong to that site.

| Gallery state | Embedded output |
| --- | --- |
| Published | Visible to visitors. |
| Published with a password | WordPress password form until unlocked; editor preview ignores the password. |
| Private | Visible only to logged-in users allowed to read private galleries. |
| Draft, pending review, scheduled or trash | Not shown to visitors; authorized editors may see a note instead. |

The admin app can show **Password protected**, but has no gallery-password field. Do not confuse the page's password with protection of a separately embedded gallery. Use the visibility controls that your installation actually exposes; the plugin is not protected-file storage.

## Privacy and limits

- Visibility controls hide **gallery markup, not uploaded files**. Anyone who knows a direct upload URL may retrieve that file. There is no right-click, hotlink or download protection.
- Image files retain EXIF/GPS metadata unless you remove it before upload. Check image rights, consent and metadata before publishing.
- Images come from your site's Media Library; admin fonts are bundled. There is no plugin telemetry or remote asset loading. Optional verified GitHub update checks can make outbound requests; see [update operations](https://github.com/sw-wordpress/sw-gallery/blob/main/docs/GITHUB-UPDATES-BETRIEB.md). Do not interpret the older README's “no outbound requests” claim as applying to configured update checks.
- The plugin sets no cookie of its own; WordPress password forms use WordPress's `wp-postpass_*` cookie. Suggested policy text is in **Settings → Privacy → Policy Guide**.
- Gallery export/import and cloud-source connectors are not implemented. WordPress migration/export can change attachment IDs without remapping gallery relations; do not assume it preserves galleries intact. Test a full site backup/restore process before relying on it.

## Deactivation, deletion and data retention

Deactivating the plugin leaves galleries, relations and settings unchanged. Deleting it also preserves them by default. To intentionally remove plugin data on uninstall:

1. Back up the site and make sure you no longer need the galleries.
2. In **Galleries → Settings**, turn on **Delete all plugin data when the plugin is deleted**.
3. Read the confirmation and type `DELETE` (German interface: `LÖSCHEN`), then **Turn on** and **Save settings**.
4. Only then deactivate/delete the plugin through WordPress when intended.

With the opt-in enabled, uninstall removes galleries (including private/trash), image relations, plugin options and transients. On multisite the setting is per site. **Images in the Media Library are never deleted by this cleanup.** Deleting an image separately in the Media Library is a different action and can break its uses elsewhere.
