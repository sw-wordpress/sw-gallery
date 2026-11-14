import type { Gallery, GalleryStatus } from '@/api/galleries';
import type { ChipKind } from '@/components/ui/StatusChip.vue';

export { formatDateTime as formatModified, localeTag } from '@/utils/format';

/** Deep link of the gallery editor (hash route). Only galleries the user may edit and that are not trashed. */
export function editorPath(gallery: Pick<Gallery, 'id' | 'canEdit' | 'status'>): string | null {
  return gallery.canEdit && gallery.status !== 'trash' ? `/galleries/${gallery.id}` : null;
}

export function shortcodeFor(id: number): string {
  return `[sw_gallery id="${id}"]`;
}

export interface StatusMeta {
  kind: ChipKind;
  icon: string;
}

/** Icon plus colour kind: status is never conveyed by colour alone. */
export const STATUS_META: Record<GalleryStatus, StatusMeta> = {
  publish: { kind: 'success', icon: 'r_check_circle' },
  draft: { kind: 'neutral', icon: 'r_edit_note' },
  pending: { kind: 'warning', icon: 'r_schedule' },
  private: { kind: 'info', icon: 'r_lock' },
  trash: { kind: 'danger', icon: 'r_delete' },
};

export type GalleryActionKind =
  'editImages' | 'edit' | 'duplicate' | 'copy' | 'trash' | 'restore' | 'delete';

/** Which row actions to offer, from the server's hints. The server enforces every one of them again. */
export function availableActions(gallery: Gallery): GalleryActionKind[] {
  if (gallery.status === 'trash') {
    return gallery.canDelete ? ['restore', 'delete'] : [];
  }
  const actions: GalleryActionKind[] = [];
  if (gallery.canEdit) actions.push('editImages', 'edit');
  actions.push('duplicate', 'copy');
  if (gallery.canDelete) actions.push('trash');
  return actions;
}
