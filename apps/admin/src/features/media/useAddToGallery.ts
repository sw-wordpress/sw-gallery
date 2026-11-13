import { onScopeDispose, ref, shallowRef } from 'vue';
import { ApiError, isApiError } from '@/api/client';
import type { Gallery } from '@/api/galleries';
import { countOf } from '@/api/items';
import type { MediaFeatureApi } from './injection';

export interface AddToGalleryResult {
  gallery: Gallery;
  added: number;
  alreadyAssigned: number;
  missing: number;
}

export const GALLERY_CHOICES = 50;
const SEARCH_DEBOUNCE_MS = 300;

function toApiError(failure: unknown): ApiError {
  return isApiError(failure) ? failure : new ApiError('swg_unknown', 'Unknown error', 0);
}

/**
 * The one action of the Media Overview (D6): add the chosen attachments to a gallery the user can
 * edit. The target list comes from `GET /galleries` (only `canEdit`, never the trash); the
 * assignment uses the gallery's CURRENT revision (fetched right before the request), and the
 * server's answer (added / already in the gallery / not usable) is passed on unchanged.
 */
export function useAddToGallery(
  api: MediaFeatureApi,
  onDone: (result: AddToGalleryResult) => void,
) {
  const open = ref(false);
  const attachmentIds = ref<number[]>([]);
  const search = ref('');
  const choices = shallowRef<Gallery[]>([]);
  /** Galleries matching the search in total (the list shows at most GALLERY_CHOICES). */
  const total = ref(0);
  const loading = ref(false);
  const loadError = shallowRef<ApiError | null>(null);
  const selectedId = ref<number | null>(null);
  const submitting = ref(false);
  const error = shallowRef<ApiError | null>(null);

  let timer: ReturnType<typeof setTimeout> | undefined;
  let sequence = 0;

  async function load(): Promise<void> {
    const mine = ++sequence;
    loading.value = true;
    loadError.value = null;
    try {
      const page = await api.galleries.list({
        search: search.value.trim() === '' ? undefined : search.value.trim(),
        perPage: GALLERY_CHOICES,
      });
      if (mine !== sequence) return;
      choices.value = page.items.filter((g) => g.canEdit && g.status !== 'trash');
      total.value = page.total;
      if (selectedId.value !== null && !choices.value.some((g) => g.id === selectedId.value)) {
        selectedId.value = null;
      }
    } catch (failure) {
      if (mine !== sequence) return;
      loadError.value = toApiError(failure);
    }
    if (mine === sequence) loading.value = false;
  }

  function openFor(ids: number[]): void {
    attachmentIds.value = [...ids];
    search.value = '';
    selectedId.value = null;
    choices.value = [];
    error.value = null;
    open.value = true;
    void load();
  }

  function setSearch(value: string): void {
    search.value = value;
    clearTimeout(timer);
    timer = setTimeout(() => void load(), SEARCH_DEBOUNCE_MS);
  }

  async function submit(): Promise<void> {
    const id = selectedId.value;
    if (id === null || submitting.value || attachmentIds.value.length === 0) return;
    submitting.value = true;
    error.value = null;
    try {
      const fresh = await api.galleries.get(id);
      if (!fresh.canEdit) throw new ApiError('swg_forbidden', 'Forbidden', 403);
      const result = await api.items.assign(id, attachmentIds.value, fresh.revision);
      open.value = false;
      onDone({
        gallery: fresh,
        added: countOf(result.added),
        alreadyAssigned: countOf(result.alreadyAssigned),
        missing: countOf(result.missing),
      });
    } catch (failure) {
      error.value = toApiError(failure);
    } finally {
      submitting.value = false;
    }
  }

  onScopeDispose(() => {
    sequence += 1;
    clearTimeout(timer);
  });

  return {
    open,
    attachmentIds,
    search,
    choices,
    total,
    loading,
    loadError,
    selectedId,
    submitting,
    error,
    openFor,
    setSearch,
    reload: load,
    submit,
  };
}

export type AddToGallery = ReturnType<typeof useAddToGallery>;
