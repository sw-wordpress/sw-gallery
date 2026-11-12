import type { Gallery, GalleryStatus } from '@/api/galleries';
import type { ChipKind } from '@/components/ui/StatusChip.vue';

/** `de_DE_formal` -> `de-DE`, `ar` -> `ar`; anything Intl rejects falls back to English. */
export function localeTag(wpLocale: string): string {
  const [language, region] = wpLocale.split('_');
  const tag =
    region !== undefined && /^[A-Za-z]{2}$/.test(region) ? `${language}-${region}` : language;
  try {
    return Intl.getCanonicalLocales(tag ?? 'en')[0] ?? 'en';
  } catch {
    return 'en';
  }
}

/**
 * Formats the ISO UTC `modified` value in the user's locale and the WordPress timezone.
 * An unknown timezone falls back to UTC, an unparsable date to the raw value.
 */
export function formatModified(iso: string, wpLocale: string, timezone: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso;
  const locale = localeTag(wpLocale);
  const options: Intl.DateTimeFormatOptions = { dateStyle: 'medium', timeStyle: 'short' };
  try {
    return new Intl.DateTimeFormat(locale, {
      ...options,
      ...(timezone === '' ? {} : { timeZone: timezone }),
    }).format(date);
  } catch {
    return new Intl.DateTimeFormat(locale, { ...options, timeZone: 'UTC' }).format(date);
  }
}

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
