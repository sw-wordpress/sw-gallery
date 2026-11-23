# MCP Server

An AI assistant can manage SW Gallery through the default server provided by the [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter). SW Gallery registers its abilities with WordPress, and the adapter exposes them to the MCP client. The assistant works within the authenticated WordPress user's permissions.

Without the MCP Adapter plugin nothing of this exists on your site: no tools, no options, no extra screens.

## Requirements

- WordPress **6.9 or newer** (it provides the Abilities API) and the **MCP Adapter** plugin, activated.
- A dedicated WordPress user for the assistant with the lowest role that fits the job (Author or Editor; an Administrator only for administration tools).
- An **application password** for that user (Users → Profile → Application Passwords). WordPress requires HTTPS for it.
- An MCP client that connects to `https://<your-site>/wp-json/mcp/mcp-adapter-default-server` with HTTP Basic authentication (user name and application password).

Revoke the application password to cut off access immediately. Failed log-in attempts are not rate-limited by WordPress; use your web server or a firewall for that.
The password also authenticates the account on other enabled WordPress endpoints. Choose an account whose permissions fit those endpoints as well.

## Access level

The **access level** limits what the assistant may do with SW Gallery, on top of the user's own rights. Tools above the level are **not offered at all**.

| Level | Tools available | Typical use |
| --- | --- | --- |
| `off` (default) | None | MCP access is disabled for SW Gallery. |
| `read` | Read-only tools | Let an assistant look at galleries, images and layouts. |
| `write` | Read tools plus tools that add or change data and can be undone | Let an assistant build and edit galleries. |
| `destructive` | Remove gallery items or permanently delete a gallery | Both actions keep the original Media Library attachments. |
| `admin` | Read and change site-wide display settings and read system status | Requires WordPress permission to manage site options. |

Levels are cumulative. Choose and save an access level to enable MCP for SW Gallery. Sites with a saved level continue to use it.

::: tip Change the level
In WordPress, open **Galleries → Settings → MCP access**, choose a level and save. The setting is visible while the adapter is active, including at **Off**. The new level applies to the next request. You can also use WP-CLI:

```sh
wp option update swg_mcp_max_tier write
```

An administrator can also cap the level in `wp-config.php`. The cap can only lower the level, never raise it:

```php
define( 'SW_GALLERY_MCP_MAX_TIER', 'write' );
// Switch the integration off entirely:
define( 'SW_GALLERY_MCP_DISABLED', true );
```

An unknown value is treated as `off`.
:::

## A tool is missing?

| Symptom | Cause and fix |
| --- | --- |
| `create-gallery` (or any change tool) is not listed | The access level is still `read`. Raise it to `write` as shown above. |
| No SW Gallery tool at all | The MCP Adapter plugin is not active, WordPress is older than 6.9, the level is `off`, or `SW_GALLERY_MCP_DISABLED` is set. |
| A tool is listed but refused | The WordPress user lacks the right in the admin, for example a Contributor cannot upload or publish, or the gallery belongs to someone else and is not visible to this user. |
| Level raised but the client still shows the old list | Reconnect or refresh the client; some clients cache the tool list. |
| An error about a changed gallery | Someone saved in between. Read the gallery again (`get-gallery`) and repeat with the new revision. |

## How the assistant sees the tools

The adapter's default server offers three **meta tools**, not one tool per action:

| Meta tool | Purpose |
| --- | --- |
| `mcp-adapter-discover-abilities` | Lists every available action, including `sw-gallery/…`. |
| `mcp-adapter-get-ability-info` | Returns the full parameter description of one action. |
| `mcp-adapter-execute-ability` | Runs an action: `ability_name` (for example `sw-gallery/create-gallery`) and `parameters`. |

So `create-gallery` does not appear as its own entry in the client's tool list. It appears in the result of `discover-abilities` once the level is `write`, and is run through `execute-ability`:

```json
{
  "ability_name": "sw-gallery/create-gallery",
  "parameters": { "title": "Community garden" }
}
```

## Tool overview

All actions are named `sw-gallery/<name>`. "Level" is the minimum access level.

### Read (level `read`)

| Tool | What it does | Main parameters |
| --- | --- | --- |
| `get-gallery` | One gallery with settings and its current **revision**. Read it before every change. | `id` |
| `list-galleries` | Galleries the user may see, with revision and image count. | `search`, `status`, `owner`, `page`, `per_page` |
| `list-items` | The images of a gallery in their stored order, with text overrides and per-image settings. | `id`, `page`, `per_page` |
| `list-layouts` | All layouts with their complete settings (value types, choices, defaults, ranges). Use it to build valid layout settings. | `page`, `per_page` |
| `list-media` | Media Library images the user may use, and in how many galleries each is used. | `search`, `orderby`, `order`, `uploadedBy`, `page`, `per_page` |
| `render-gallery` | Renders a gallery on the server and reports only the result: layout, page counts and diagnostic codes. It returns no markup. | `id` |

Lists are returned in pages. When there is more, the answer contains `next` with `page` and `offset`; pass both on the next call with the same filters. Image and cover URLs are never returned.

### Change (level `write`)

| Tool | What it does | Main parameters |
| --- | --- | --- |
| `create-gallery` | Creates a gallery owned by the user, a draft unless `status` says otherwise. Without `layout` the site default layout is used. | `title`, `description`, `status`, `layout`, `sortMode`, `perPage`, `hover`, `settings` |
| `update-gallery` | Changes selected fields of a gallery. `settings` replaces the whole layout settings map. The owner cannot be changed. | `id`, `expectedRevision`, the same fields as create, `coverAttachmentId` |
| `duplicate-gallery` | Copies a gallery, its images and texts into a new draft owned by the user. | `id`, `title` |
| `trash-gallery` | Moves a gallery to the trash. Nothing is lost. | `id`, `expectedRevision` |
| `restore-gallery` | Brings a trashed gallery back as a draft. | `id`, `expectedRevision` |
| `assign-items` | Adds Media Library images to a gallery. Images already in it or not usable by the user are reported, not added. | `id`, `attachmentIds`, `expectedRevision` |
| `update-item` | Changes the title, caption and alt text of one image in the gallery and/or its per-image settings. | `id`, `itemId`, `expectedRevision`, `overrides`, `settings` |
| `reorder-items` | Sets the manual order. The list must contain every image of the gallery exactly once. | `id`, `itemIds`, `expectedRevision` |

### Permanent removal (level `destructive`)

Both tools require the current gallery revision and an explicit JSON boolean `"confirm": true`.

| Tool | What it does | Main parameters |
| --- | --- | --- |
| `remove-items` | Removes gallery items, their text overrides and per-image settings. | `id`, `itemIds`, `expectedRevision`, `confirm` |
| `delete-gallery` | Permanently deletes a gallery and its item relations. Use `trash-gallery` for reversible removal. | `id`, `expectedRevision`, `confirm` |

### Administration (level `admin`)

| Tool | What it does |
| --- | --- |
| `get-settings` | Reads the default layout, page size, lightbox and hover settings. |
| `update-settings` | Changes the supplied display settings. |
| `get-status` | Reports schema state, counts, software versions, recent error codes and diagnostic hint keys. |

Use the WordPress admin for media uploads, ownership changes, MCP access and the uninstall setting.

## Create a Masonry gallery from the Media Library

With the `write` access level, use these abilities through the adapter's execute tool. Look up each ability's input schema with the adapter's ability-info tool.

1. Call `sw-gallery/list-layouts` to confirm the `masonry` layout. Call `sw-gallery/list-media` and follow every `next` value to collect the attachment IDs the user can access.
2. Call `sw-gallery/create-gallery` with `title: "Masonry"` and `layout: "masonry"`. Keep the default draft status while assembling the gallery.
3. Call `sw-gallery/get-gallery` for the new gallery ID. Send the attachment IDs to `sw-gallery/assign-items` with that gallery ID and the current revision as `expectedRevision`. For more than 500 IDs, split the assignment into batches and read the latest revision before each batch.
4. Compare the assigned IDs using `sw-gallery/list-items`, then call `sw-gallery/render-gallery` to check the layout, image count and diagnostics. To publish, read the gallery again and call `sw-gallery/update-gallery` with the current revision and `status: "publish"`.
5. Embed the published gallery in a WordPress page using its shortcode, for example `[sw_gallery id="123"]` for gallery ID 123. Creating the WordPress page itself requires a separate page-management ability or the WordPress page editor. [Embedding guide](embedding.md)

## Rules every change follows

- **Revision.** Tools that require `expectedRevision` use the number from the last `get-gallery` call. If the gallery changed in between, the call fails before applying the change. Read again, then decide.
- **Plain text.** Titles, descriptions and image texts are stored as plain text; markup is removed.
- **Rights.** Publishing, covers and image use follow the WordPress rights of the user, exactly as in the editor. A gallery the user cannot see is reported as "not found".
- **Images.** Attachments are never copied, modified or deleted.
- **Creating twice.** If a create or duplicate call times out, check `list-galleries` before repeating it; the gallery may already exist.

## Audit trail

Calls that change data, and refused calls, are recorded without any content: time, tool name, user ID, outcome and result code. The latest 50 entries are kept in the option `swg_mcp_audit` (`wp option get swg_mcp_audit --format=json`). Developers can observe calls with the `swg_mcp_ability_called` action; see the [hook reference](https://github.com/sw-wordpress/sw-gallery/blob/main/docs/hooks.md). Technical details and the security model are in the [MCP concept](https://github.com/sw-wordpress/sw-gallery/blob/main/docs/mcp-abilities.md).
