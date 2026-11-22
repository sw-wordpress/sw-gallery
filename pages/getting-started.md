# Getting started

## Requirements and installation

You need WordPress **6.4 or newer**, PHP **8.2 or newer**, and MySQL or MariaDB. Installation requires a WordPress account allowed to install plugins. The release ZIP includes the built administration interface and dependencies; the server does not need Node.js or Composer.

1. Back up the site before installing or updating a plugin.
2. Download the `sw-gallery-<version>.zip` asset from [GitHub Releases](https://github.com/sw-wordpress/sw-gallery/releases). Use the release ZIP, not GitHub's automatically generated source-code archive.
3. In WordPress, open **Plugins → Add New Plugin → Upload Plugin** and choose the ZIP.
4. Install and activate **SW Gallery**. Open **Galleries** in the admin menu.

The plugin is not distributed through wordpress.org. Native WordPress update offers depend on trusted signing-key and signed-release setup; they are not a substitute for the initial manual installation. Administrators can read the [update operations guide](https://github.com/sw-wordpress/sw-gallery/blob/main/docs/GITHUB-UPDATES-BETRIEB.md).

![SW Gallery Overview showing five published demo galleries, quick actions and plugin health](/screenshots/sw-gallery-overview.png)

*Demo: the Overview screen in the WordPress test site. Gallery names and counts are sample data.*

## First successful gallery

1. Open **Galleries**. The app opens on **Overview**; choose **Galleries** in its navigation.
2. Click **New gallery**, enter a **Title** (for example “Community garden”) and optionally a plain-text **Description**, then **Create gallery**. It starts as a **Draft**.
3. Open the gallery's **Edit images** action. Click **Add images**, select existing images in the WordPress picker and choose **Add to gallery**. If your role permits uploads, you can also drop image files into the editor.
4. Arrange the images and click **Save**. In **Details**, choose **Published** as the **Status** and save, or use **Publish**. Publishing requires the appropriate role; contributors must submit for review instead.
5. Edit a WordPress page or post. Insert the **SW Gallery** block and select this gallery. Publish or update the page/post.
6. Open that page in a logged-out/private browser window. Confirm that the images appear and that clicking an image opens the lightbox (enabled by default).

Alternatively, the gallery list's **Copy shortcode** action provides `[sw_gallery id="123"]` with your actual gallery ID. Add it in a WordPress **Shortcode** block. [Embedding instructions](embedding.md) describe the overrides.

::: warning Image privacy
A draft or private gallery does not protect the uploaded image files. Anyone with a file URL may retrieve it. Use synthetic or publishable images when testing; see [privacy and limits](settings-and-access.md#privacy-and-limits).
:::

Continue with [image editing](galleries.md) and [layout selection](layouts.md).
