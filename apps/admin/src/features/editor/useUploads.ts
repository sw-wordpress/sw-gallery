import { computed, ref } from 'vue';
import { isApiError } from '@/api/client';
import type { MediaApi } from '@/api/media';
import type { AddOutcome } from './useGalleryEditor';

export type UploadStatus = 'queued' | 'uploading' | 'done' | 'added' | 'error' | 'cancelled';
export type UploadProblem =
  | 'type'
  | 'size'
  | 'empty'
  | 'tooLarge'
  | 'unsupported'
  | 'invalid'
  | 'network'
  | 'forbidden'
  | 'other';

export interface UploadEntry {
  key: number;
  name: string;
  size: number;
  status: UploadStatus;
  /** Real bytes sent so far (XHR upload progress). */
  loaded: number;
  /** `null` while the browser cannot compute it. */
  total: number | null;
  problem: UploadProblem | null;
  attachmentId: number | null;
}

/** Client-side hints only: the server checks type (from the bytes), size and decodability again. */
export const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];
export const SIZE_HINT_BYTES = 64 * 1024 * 1024;
export const UPLOAD_CONCURRENCY = 2;

export function problemOf(error: unknown): UploadProblem {
  if (!isApiError(error)) return 'other';
  if (error.isNetwork) return 'network';
  switch (error.status) {
    case 413:
      return 'tooLarge';
    case 401:
    case 403:
      return 'forbidden';
    case 422:
      return error.code === 'swg_unsupported_media_type' ? 'unsupported' : 'invalid';
    default:
      return 'other';
  }
}

/**
 * Upload queue (M2-28): at most two files at a time through `POST /media` with real per-file XHR
 * progress, per-file cancel (AbortController) and retry. When nothing is queued or running any
 * more, every successfully uploaded file that is not yet in the gallery is assigned in ONE call
 * (`onBatch`), so a partial failure still adds what worked ("n of m").
 */
export function useUploads(
  api: MediaApi,
  onBatch: (attachmentIds: number[]) => Promise<AddOutcome>,
  notifyBatch?: (summary: { uploaded: number; failed: number; outcome: AddOutcome }) => void,
) {
  const entries = ref<UploadEntry[]>([]);
  const files = new Map<number, File>();
  const controllers = new Map<number, AbortController>();
  let counter = 0;
  let running = 0;
  let flushing = false;

  const active = computed(() =>
    entries.value.some((e) => e.status === 'queued' || e.status === 'uploading'),
  );
  const busy = computed(() => active.value || flushing);
  const failedCount = computed(() => entries.value.filter((e) => e.status === 'error').length);

  function entry(key: number): UploadEntry | undefined {
    return entries.value.find((e) => e.key === key);
  }

  function hint(file: File): UploadProblem | null {
    if (file.type !== '' && !ACCEPTED_TYPES.includes(file.type)) return 'type';
    if (file.type === '') return 'type';
    if (file.size === 0) return 'empty';
    if (file.size > SIZE_HINT_BYTES) return 'size';
    return null;
  }

  /** Queues files; the ones failing the client hints are listed as errors without a request. */
  function add(list: File[]): void {
    for (const file of list) {
      counter += 1;
      const problem = hint(file);
      entries.value = [
        ...entries.value,
        {
          key: counter,
          name: file.name,
          size: file.size,
          status: problem === null ? 'queued' : 'error',
          loaded: 0,
          total: file.size,
          problem,
          attachmentId: null,
        },
      ];
      if (problem === null) files.set(counter, file);
    }
    pump();
  }

  function pump(): void {
    while (running < UPLOAD_CONCURRENCY) {
      const next = entries.value.find((e) => e.status === 'queued');
      if (next === undefined) break;
      void start(next.key);
    }
    if (running === 0 && !active.value) void flush();
  }

  function patch(key: number, change: Partial<UploadEntry>): void {
    entries.value = entries.value.map((e) => (e.key === key ? { ...e, ...change } : e));
  }

  async function start(key: number): Promise<void> {
    const file = files.get(key);
    if (file === undefined) {
      patch(key, { status: 'error', problem: 'other' });
      return;
    }
    running += 1;
    const controller = new AbortController();
    controllers.set(key, controller);
    patch(key, { status: 'uploading', loaded: 0, problem: null });
    try {
      const media = await api.upload(file, {
        signal: controller.signal,
        onProgress: (progress) => patch(key, { loaded: progress.loaded, total: progress.total }),
      });
      patch(key, { status: 'done', attachmentId: media.id, loaded: file.size, total: file.size });
      files.delete(key);
    } catch (error) {
      if (isApiError(error) && error.isAborted) {
        patch(key, { status: 'cancelled' });
      } else {
        patch(key, { status: 'error', problem: problemOf(error) });
      }
    } finally {
      controllers.delete(key);
      running -= 1;
      pump();
    }
  }

  async function flush(): Promise<void> {
    if (flushing) return;
    const ready = entries.value.filter((e) => e.status === 'done' && e.attachmentId !== null);
    if (ready.length === 0) return;
    flushing = true;
    try {
      const ids = ready.map((e) => e.attachmentId).filter((id): id is number => id !== null);
      const outcome = await onBatch(ids);
      const keys = new Set(ready.map((e) => e.key));
      if (outcome.ok) {
        entries.value = entries.value.map((e) => (keys.has(e.key) ? { ...e, status: 'added' } : e));
      }
      notifyBatch?.({
        uploaded: ready.length,
        failed: entries.value.filter((e) => e.status === 'error').length,
        outcome,
      });
    } finally {
      flushing = false;
    }
  }

  function cancel(key: number): void {
    const current = entry(key);
    if (current === undefined) return;
    if (current.status === 'uploading') {
      controllers.get(key)?.abort();
    } else if (current.status === 'queued') {
      files.delete(key);
      patch(key, { status: 'cancelled' });
      pump();
    }
  }

  function cancelAll(): void {
    for (const e of entries.value) cancel(e.key);
  }

  function retry(key: number): void {
    const current = entry(key);
    if (current === undefined || !files.has(key)) return;
    if (current.status !== 'error' && current.status !== 'cancelled') return;
    patch(key, { status: 'queued', problem: null, loaded: 0 });
    pump();
  }

  /** Removes finished, failed and cancelled rows from the list. */
  function dismiss(key?: number): void {
    entries.value = entries.value.filter((e) => {
      if (key !== undefined && e.key !== key) return true;
      return e.status === 'queued' || e.status === 'uploading' || e.status === 'done';
    });
    for (const k of [...files.keys()]) if (entry(k) === undefined) files.delete(k);
  }

  /** Uploaded but not yet added to the gallery (e.g. a conflict interrupted the assignment). */
  const unassigned = computed(() => entries.value.filter((e) => e.status === 'done').length);

  return {
    entries,
    active,
    busy,
    failedCount,
    unassigned,
    add,
    cancel,
    cancelAll,
    retry,
    dismiss,
    canRetry: (key: number): boolean => files.has(key),
    assignPending: flush,
  };
}

export type Uploads = ReturnType<typeof useUploads>;
