/** Deep links into the Vue admin app (hash routes, contract: apps/admin/README.md). */

/** "Create a new gallery": the gallery list, where the admin app offers creation. */
export function galleryListUrl(galleriesUrl: string): string {
  return `${galleriesUrl}#/galleries`;
}

/** "Edit this gallery": the editor of one gallery; null for anything but a positive integer id. */
export function editGalleryUrl(galleriesUrl: string, galleryId: number): string | null {
  if (!Number.isInteger(galleryId) || galleryId < 1) return null;
  return `${galleriesUrl}#/galleries/${galleryId}`;
}
