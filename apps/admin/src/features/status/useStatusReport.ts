import { inject, onScopeDispose, ref, shallowRef } from 'vue';
import type { InjectionKey } from 'vue';
import { getApi } from '@/api';
import { ApiError, isApiError } from '@/api/client';
import { createStatusApi } from '@/api/status';
import type { StatusApi, StatusReport } from '@/api/status';

/** Tests provide a fake; production builds it from the shared client. Shared by the status page and the overview. */
export const STATUS_API: InjectionKey<StatusApi> = Symbol('swg-status-api');

export function useStatusApi(): StatusApi {
  return inject(STATUS_API, null) ?? createStatusApi(getApi());
}

/**
 * `GET /status` with explicit states. `load()` re-fetches on demand (refresh button): the previous
 * report stays on screen meanwhile and when the refresh fails. There is deliberately no polling.
 */
export function useStatusReport(api: StatusApi) {
  const report = shallowRef<StatusReport | null>(null);
  const loading = ref(false);
  const error = shallowRef<ApiError | null>(null);
  /** When the shown report was fetched. */
  const fetchedAt = ref<Date | null>(null);

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
      const fresh = await api.get(own.signal);
      if (mine !== sequence) return;
      report.value = fresh;
      fetchedAt.value = new Date();
    } catch (failure) {
      if (mine !== sequence) return;
      if (isApiError(failure) && failure.isAborted) return;
      error.value = isApiError(failure) ? failure : new ApiError('swg_unknown', 'Unknown error', 0);
    }
    if (mine === sequence) loading.value = false;
  }

  onScopeDispose(() => {
    sequence += 1;
    controller?.abort();
  });

  return { report, loading, error, fetchedAt, load };
}
