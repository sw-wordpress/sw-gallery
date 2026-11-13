import { onScopeDispose, ref, shallowRef, toValue, watch } from 'vue';
import type { MaybeRefOrGetter } from 'vue';
import { ApiError, isApiError } from '@/api/client';
import type { MediaLibraryApi, MediaLibraryItem, MediaListQuery } from '@/api/media';

/**
 * Server-backed media list with explicit states; same contract as the gallery list: a query change
 * cancels the running request and drops the old rows, a stale response is ignored (sequence guard),
 * `reload()` keeps the rows on screen meanwhile.
 */
export function useMediaList(api: MediaLibraryApi, query: MaybeRefOrGetter<MediaListQuery>) {
  const items = shallowRef<MediaLibraryItem[]>([]);
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
