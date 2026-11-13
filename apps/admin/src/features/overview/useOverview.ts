import { onScopeDispose, ref, shallowRef } from 'vue';
import { ApiError, isApiError } from '@/api/client';
import type { GalleriesApi, Gallery, GalleryStatus } from '@/api/galleries';
import type { StatusApi, StatusReport } from '@/api/status';

export const RECENT_COUNT = 5;

export interface GalleryCounts {
  all: number;
  publish: number;
  /** Drafts including pending review (same grouping as the gallery list). */
  draft: number;
  private: number;
}

const DRAFTS: GalleryStatus[] = ['draft', 'pending'];

function toApiError(failure: unknown): ApiError {
  return isApiError(failure) ? failure : new ApiError('swg_unknown', 'Unknown error', 0);
}

/**
 * Overview data from existing endpoints only: totals per status from `GET /galleries?per_page=1`
 * (the `X-WP-Total` header), the most recently modified galleries (the list is newest first), and
 * for managers the health summary of `GET /status`. The status request is a soft dependency: its
 * failure shows in the health line and never blocks the rest.
 */
export function useOverview(
  galleries: GalleriesApi,
  status: StatusApi | null,
  /** Whether the user may read `GET /status` (manage_options). */
  isManager: boolean,
) {
  const counts = shallowRef<GalleryCounts | null>(null);
  const recent = shallowRef<Gallery[]>([]);
  const loading = ref(false);
  const error = shallowRef<ApiError | null>(null);
  const report = shallowRef<StatusReport | null>(null);
  const statusLoading = ref(false);
  const statusError = shallowRef<ApiError | null>(null);

  let controller: AbortController | null = null;
  let sequence = 0;

  async function loadStatus(signal: AbortSignal, mine: number): Promise<void> {
    if (!isManager || status === null) return;
    statusLoading.value = true;
    statusError.value = null;
    try {
      const fresh = await status.get(signal);
      if (mine !== sequence) return;
      report.value = fresh;
    } catch (failure) {
      if (mine !== sequence) return;
      const apiError = toApiError(failure);
      if (!apiError.isAborted) statusError.value = apiError;
    }
    if (mine === sequence) statusLoading.value = false;
  }

  async function load(): Promise<void> {
    controller?.abort();
    const own = new AbortController();
    controller = own;
    const mine = ++sequence;
    loading.value = true;
    error.value = null;
    const statusDone = loadStatus(own.signal, mine);
    try {
      const [all, publish, draft, privateOnly, latest] = await Promise.all([
        galleries.list({ perPage: 1 }, own.signal),
        galleries.list({ status: ['publish'], perPage: 1 }, own.signal),
        galleries.list({ status: DRAFTS, perPage: 1 }, own.signal),
        galleries.list({ status: ['private'], perPage: 1 }, own.signal),
        galleries.list({ perPage: RECENT_COUNT }, own.signal),
      ]);
      if (mine !== sequence) return;
      counts.value = {
        all: all.total,
        publish: publish.total,
        draft: draft.total,
        private: privateOnly.total,
      };
      recent.value = latest.items;
    } catch (failure) {
      if (mine !== sequence) return;
      const apiError = toApiError(failure);
      if (apiError.isAborted) return;
      error.value = apiError;
    }
    await statusDone;
    if (mine === sequence) loading.value = false;
  }

  onScopeDispose(() => {
    sequence += 1;
    controller?.abort();
  });

  return { counts, recent, loading, error, report, statusLoading, statusError, load };
}
