# AI assistants (MCP)

SW Gallery can be operated by an AI assistant through the WordPress **MCP Adapter** plugin. The assistant works as a normal WordPress user: it sees and changes only what that user may see and change in the admin, and every change goes through the same rules as the editor (revision checks, publishing rights, plain-text storage).

Without the MCP Adapter plugin nothing of this exists on your site: no tools, no options, no extra screens.

## Requirements

- WordPress **6.9 or newer** (it provides the Abilities API) and the **MCP Adapter** plugin, activated.
- A dedicated WordPress user for the assistant with the lowest role that fits the job (Author or Editor; an Administrator only for administration tools).
- An **application password** for that user (Users → Profile → Application Passwords). WordPress requires HTTPS for it.
- An MCP client that connects to `https://<your-site>/wp-json/mcp/mcp-adapter-default-server` with HTTP Basic authentication (user name and application password).

Revoke the application password to cut off access immediately. Failed log-in attempts are not rate-limited by WordPress; use your web server or a firewall for that.

## Access level

The **access level** limits what the assistant may do with SW Gallery, on top of the user's own rights. Tools above the level are **not offered at all**.

| Level | Tools available | Typical use |
| --- | --- | --- |
| `off` | None | MCP completely disabled for SW Gallery. |
| `read` (default) | Read-only tools | Let an assistant look at galleries, images and layouts. |
| `write` | Read tools plus tools that add or change data and can be undone | Let an assistant build and edit galleries. |
| `destructive` | Adds tools that remove data permanently | *No tools at this level yet.* |
| `admin` | Adds plugin-wide settings and diagnostics | *No tools at this level yet.* |

Levels are cumulative. The default is `read`, so a fresh installation can read but not write.

::: tip Change the level
A settings screen for this is not available yet. Use WP-CLI (the change applies to the next request, no restart needed):

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

### Not available yet

| Planned tool | Level | Status |
| --- | --- | --- |
| Remove images from a gallery, delete a gallery permanently | `destructive` | Not implemented. |
| Read and change plugin settings, read system status | `admin` | Not implemented. |
| Upload images | – | Not offered through MCP. Use images already in the Media Library (`list-media`). |
| Change a gallery's owner | – | Not offered through MCP; use the admin app. |

## Rules every change follows

- **Revision.** Each change needs `expectedRevision`, the number from the last read. If the gallery changed in between, the call fails and nothing is written. Read again, then decide.
- **Plain text.** Titles, descriptions and image texts are stored as plain text; markup is removed.
- **Rights.** Publishing, covers and image use follow the WordPress rights of the user, exactly as in the editor. A gallery the user cannot see is reported as "not found".
- **Images.** Attachments are never copied, modified or deleted.
- **Creating twice.** If a create or duplicate call times out, check `list-galleries` before repeating it; the gallery may already exist.

## Audit trail

Calls that change data, and refused calls, are recorded without any content: time, tool name, user ID, outcome and result code. The latest 50 entries are kept in the option `swg_mcp_audit` (`wp option get swg_mcp_audit --format=json`). Developers can observe calls with the `swg_mcp_ability_called` action; see the [hook reference](https://github.com/sw-wordpress/sw-gallery/blob/main/docs/hooks.md). Technical details and the security model are in the [MCP concept](https://github.com/sw-wordpress/sw-gallery/blob/main/docs/mcp-abilities.md).
