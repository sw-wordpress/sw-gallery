import { computed } from 'vue';
import { useCapabilities } from '@/composables/useCapabilities';

export type PickerResult =
  | { status: 'selected'; attachmentIds: number[] }
  | { status: 'cancelled' }
  | { status: 'unavailable' };

/**
 * Native WordPress media modal (decision D2): `wp.media({ library: { type: 'image' }, multiple: 'add' })`.
 * Only the chosen attachment ids come back; the caller assigns them through the REST API. Users
 * without `upload_files` (contributors, D11) cannot use the Media Library, so the picker is
 * reported as not permitted. A missing `wp.media` (script not enqueued) fails gracefully.
 */
export function useMediaPicker() {
  const { can } = useCapabilities();
  const permitted = computed(() => can('upload_files'));

  function isAvailable(): boolean {
    return typeof window.wp?.media === 'function';
  }

  function open(labels: { title: string; button: string }): Promise<PickerResult> {
    const factory = window.wp?.media;
    if (!permitted.value || typeof factory !== 'function') {
      return Promise.resolve({ status: 'unavailable' });
    }
    return new Promise<PickerResult>((resolve) => {
      let settled = false;
      const finish = (result: PickerResult): void => {
        if (settled) return;
        settled = true;
        resolve(result);
      };
      const frame = factory({
        title: labels.title,
        button: { text: labels.button },
        library: { type: 'image' },
        multiple: 'add',
      });
      frame.on('select', () => {
        const ids = frame
          .state()
          .get('selection')
          .map((attachment) => attachment.id)
          .filter((id) => Number.isInteger(id) && id > 0);
        finish(
          ids.length > 0 ? { status: 'selected', attachmentIds: ids } : { status: 'cancelled' },
        );
      });
      frame.on('close', () => finish({ status: 'cancelled' }));
      frame.open();
    });
  }

  return { permitted, isAvailable, open };
}
