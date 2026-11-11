import { onScopeDispose, ref, shallowRef, toValue, watch } from 'vue';
import type { MaybeRefOrGetter } from 'vue';
import { ApiError, isApiError } from '@/api/client';
import type { Gallery, GalleryListQuery, GalleriesApi } from '@/api/galleries';

/**
 * Server-backed gallery list with explicit states. Every query change cancels the running request
 * (AbortController) and drops the old rows; a response that arrives for an outdated request is ignored
 * (sequence guard), also when the abort did not reach the network layer. `reload()` refetches the
 * current query and keeps the rows on screen meanwhile (no flash after an action).
 */
export function useGalleryList(api: GalleriesApi, query: MaybeRefOrGetter<GalleryListQuery>) {
  const items = shallowRef<Gallery[]>([]);
  const total = ref(0);
  const totalPages = ref(0);
  const loading = ref(false);
  const error = shallowRef<ApiError | null>(null);
  /** True once a request for the current query has finished (successfully or not). */
  const settled = ref(false);

  let controller: AbortController | null = null;
  let sequence = 0;

  async function load(): Promise<void> {
    controller?.abort();
    const own = new AbortController();
    controller = own;
    const mine = ++sequence;
    loading.value = true;
    error.value = null;
    try {
      const page = await api.list(toValue(query), own.signal);
      if (mine !== sequence) return;
      items.value = page.items;
      total.value = page.total;
      totalPages.value = page.totalPages;
    } catch (failure) {
      if (mine !== sequence) return;
      if (isApiError(failure) && failure.isAborted) return;
      error.value = isApiError(failure) ? failure : new ApiError('swg_unknown', 'Unknown error', 0);
    }
    if (mine === sequence) {
      loading.value = false;
      settled.value = true;
    }
  }

  watch(
    () => ({ ...toValue(query) }),
    () => {
      items.value = [];
      total.value = 0;
      totalPages.value = 0;
      settled.value = false;
      void load();
    },
    { immediate: true, deep: true },
  );

  onScopeDispose(() => {
    sequence += 1;
    controller?.abort();
  });

  return { items, total, totalPages, loading, error, settled, reload: load };
}
