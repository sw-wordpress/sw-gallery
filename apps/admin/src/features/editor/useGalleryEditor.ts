import { computed, ref, shallowRef } from 'vue';
import { invalidFields } from '@/api/errorMessage';
import type { UpdateGalleryInput } from '@/api/galleries';
import { ApiError, isApiError } from '@/api/client';
import type { GalleryItem, GlobalTexts, ItemTexts } from '@/api/items';
import { ITEM_PAGE_SIZE, MAX_ORDER_ITEMS, countOf } from '@/api/items';
import { baseFromFresh, diffServer, rebase } from './conflict';
import type { FreshState, RebaseNotices, ServerDiff } from './conflict';
import type { EditorApi } from './injection';
import {
  buildPlan,
  changedDetailKeys,
  changedOverrideIds,
  countChanges,
  deepEqual,
  detailsOf,
  effectiveTexts,
  initialWorking,
  orderChanged,
  removedIds,
} from './model';
import type { Base, DetailKey, Details, SaveStep, Working } from './model';
import { moveIds, moveIdsTo } from './reorder';
import type { MoveMode } from './reorder';

export type FailureKind =
  'conflict' | 'gone' | 'itemGone' | 'invalidState' | 'forbidden' | 'invalid' | 'network' | 'other';

export function classifyFailure(error: unknown): FailureKind {
  if (!isApiError(error)) return 'other';
  if (error.isNetwork) return 'network';
  if (error.code === 'swg_revision_conflict') return 'conflict';
  if (error.code === 'swg_invalid_state') return 'invalidState';
  if (error.code === 'swg_item_not_found') return 'itemGone';
  if (error.status === 404) return 'gone';
  if (error.status === 401 || error.status === 403) return 'forbidden';
  if (error.status === 400 || error.status === 422) return 'invalid';
  return 'other';
}

/** A validation problem the server reported for a settings value (`path` relative to the settings map). */
export interface SettingIssue {
  path: string[];
  code: string;
}

export interface SaveReport {
  ok: boolean;
  total: number;
  completed: SaveStep['kind'][];
  failed: { kind: SaveStep['kind']; failure: FailureKind; error: unknown } | null;
}

export interface SaveProgress {
  done: number;
  total: number;
  kind: SaveStep['kind'];
}

export interface ConflictState {
  diff: ServerDiff;
  notices: RebaseNotices;
  /** Server-side titles/revision for the dialog. */
  serverTitle: string;
  stagedCount: number;
  fresh: FreshState;
}

export type AddOutcome =
  | { ok: true; added: number; alreadyAssigned: number; missing: number }
  | { ok: false; failure: FailureKind };

export interface ItemView {
  item: GalleryItem;
  /** Staged texts if edited, otherwise the stored ones. */
  texts: ItemTexts;
  effective: GlobalTexts;
  edited: boolean;
}

export interface LoadedState extends FreshState {
  total: number;
  /** More items than the reorder limit: only the first MAX_ORDER_ITEMS are loaded and reorder is off. */
  truncated: boolean;
}

const HISTORY_LIMIT = 100;
const COALESCE_MS = 1500;
const MAX_LOAD_ATTEMPTS = 3;

/**
 * Loads the gallery and ALL its items (pages of 100, bounded by the reorder limit). A load whose
 * pages carry different revisions raced with a writer and is repeated.
 */
export async function fetchEditorState(
  api: EditorApi,
  galleryId: number,
  signal?: AbortSignal,
): Promise<LoadedState> {
  for (let attempt = 1; attempt <= MAX_LOAD_ATTEMPTS; attempt += 1) {
    const gallery = await api.galleries.get(galleryId, signal);
    const first = await api.items.list(galleryId, 1, ITEM_PAGE_SIZE, signal);
    const maxPages = Math.ceil(MAX_ORDER_ITEMS / ITEM_PAGE_SIZE);
    const pages = Math.min(first.totalPages, maxPages);
    const items = [...first.items];
    let stable = first.revision === gallery.revision;
    for (let page = 2; stable && page <= pages; page += 1) {
      const next = await api.items.list(galleryId, page, ITEM_PAGE_SIZE, signal);
      if (next.revision !== first.revision) stable = false;
      items.push(...next.items);
    }
    if (stable) {
      return {
        gallery,
        items: items.slice(0, MAX_ORDER_ITEMS),
        revision: first.revision,
        total: first.total,
        truncated: first.total > MAX_ORDER_ITEMS,
      };
    }
  }
  throw new ApiError('swg_unstable', 'The gallery kept changing while loading', 409);
}

/**
 * The editor session: base (server truth), working copy (staged edits), undo/redo, the save
 * pipeline with revision chaining and the conflict flow. Framework-light on purpose (refs only)
 * so it is unit-testable without mounting.
 */
export function createEditorSession(galleryId: number, api: EditorApi) {
  const phase = ref<'loading' | 'ready' | 'error'>('loading');
  const loadError = ref<unknown>(null);
  const gone = ref(false);
  const invalidState = ref(false);
  const truncated = ref(false);
  const total = ref(0);

  const base = shallowRef<Base | null>(null);
  const working = shallowRef<Working | null>(null);
  const undoStack = shallowRef<Working[]>([]);
  const redoStack = shallowRef<Working[]>([]);
  let lastKey = '';
  let lastTime = 0;

  const saving = ref(false);
  const progress = ref<SaveProgress | null>(null);
  const report = ref<SaveReport | null>(null);
  const lastFailure = ref<FailureKind | null>(null);
  const settingIssues = ref<SettingIssue[]>([]);
  const invalidDetails = ref<string[]>([]);
  const conflict = shallowRef<ConflictState | null>(null);
  /** Notices of the last rebase, shown once after "reload and re-apply". */
  const rebaseNotices = ref<RebaseNotices | null>(null);

  // ---- derived
  const changeCount = computed(() =>
    base.value !== null && working.value !== null ? countChanges(base.value, working.value) : 0,
  );
  const dirty = computed(() => changeCount.value > 0);
  const canUndo = computed(() => undoStack.value.length > 0);
  const canRedo = computed(() => redoStack.value.length > 0);
  const revision = computed(() => base.value?.revision ?? 0);
  const order = computed<readonly number[]>(() => working.value?.order ?? []);
  const removedCount = computed(() =>
    base.value !== null && working.value !== null
      ? removedIds(base.value, working.value).length
      : 0,
  );
  const reorderEnabled = computed(() => !truncated.value && !saving.value);

  // ---- loading
  function adopt(state: LoadedState | FreshState): void {
    base.value = baseFromFresh(state);
    working.value = initialWorking(base.value);
    undoStack.value = [];
    redoStack.value = [];
    lastKey = '';
    if ('truncated' in state) {
      truncated.value = state.truncated;
      total.value = state.total;
    }
  }

  async function load(signal?: AbortSignal): Promise<void> {
    phase.value = 'loading';
    loadError.value = null;
    gone.value = false;
    try {
      adopt(await fetchEditorState(api, galleryId, signal));
      invalidState.value = base.value?.gallery.status === 'trash';
      phase.value = 'ready';
    } catch (error) {
      if (isApiError(error) && error.isAborted) return;
      loadError.value = error;
      // Missing and not-viewable galleries look identical (existence-leak policy), so 403 reads as "not found" too.
      const kind = classifyFailure(error);
      gone.value = kind === 'gone' || kind === 'forbidden';
      phase.value = 'error';
    }
  }

  // ---- history
  function commit(next: Working, key?: string): void {
    const previous = working.value;
    if (previous === null || next === previous) return;
    const now = Date.now();
    const coalesce =
      key !== undefined &&
      key === lastKey &&
      now - lastTime < COALESCE_MS &&
      undoStack.value.length > 0;
    if (!coalesce) undoStack.value = [...undoStack.value, previous].slice(-HISTORY_LIMIT);
    lastKey = key ?? '';
    lastTime = now;
    redoStack.value = [];
    working.value = next;
    settingIssues.value = [];
    invalidDetails.value = [];
  }

  function undo(): boolean {
    const previous = undoStack.value[undoStack.value.length - 1];
    if (previous === undefined || working.value === null) return false;
    redoStack.value = [...redoStack.value, working.value];
    undoStack.value = undoStack.value.slice(0, -1);
    working.value = previous;
    lastKey = '';
    return true;
  }

  function redo(): boolean {
    const next = redoStack.value[redoStack.value.length - 1];
    if (next === undefined || working.value === null) return false;
    undoStack.value = [...undoStack.value, working.value];
    redoStack.value = redoStack.value.slice(0, -1);
    working.value = next;
    lastKey = '';
    return true;
  }

  /** Throws all staged changes away (itself undoable until the next save). */
  function discard(): void {
    if (base.value === null) return;
    commit(initialWorking(base.value));
  }

  // ---- staged edits
  function setDetail<K extends DetailKey>(key: K, value: Details[K]): void {
    const current = working.value;
    if (current === null || deepEqual(current.details[key], value)) return;
    commit({ ...current, details: { ...current.details, [key]: value } }, `detail:${key}`);
  }

  /** Sets (or, with `undefined`, removes) one value inside the settings map at `path`. */
  function setSetting(path: string[], value: unknown): void {
    const current = working.value;
    if (current === null || path.length === 0) return;
    const update = (node: unknown, depth: number): Record<string, unknown> => {
      const copy: Record<string, unknown> =
        node !== null && typeof node === 'object' && !Array.isArray(node)
          ? { ...(node as Record<string, unknown>) }
          : {};
      const key = path[depth];
      if (key === undefined) return copy;
      if (depth === path.length - 1) {
        if (value === undefined) delete copy[key];
        else copy[key] = value;
      } else {
        const child = update(copy[key], depth + 1);
        if (Object.keys(child).length === 0) delete copy[key];
        else copy[key] = child;
      }
      return copy;
    };
    const settings = update(current.details.settings, 0);
    if (deepEqual(settings, current.details.settings)) return;
    commit({ ...current, details: { ...current.details, settings } }, `setting:${path.join('.')}`);
  }

  function removeItems(ids: Iterable<number>): number {
    const current = working.value;
    if (current === null) return 0;
    const drop = new Set(ids);
    const order = current.order.filter((id) => !drop.has(id));
    const count = current.order.length - order.length;
    if (count === 0) return 0;
    const overrides = { ...current.overrides };
    for (const id of drop) delete overrides[id];
    commit({ ...current, order, overrides });
    return count;
  }

  function move(selected: ReadonlySet<number>, mode: MoveMode): boolean {
    const current = working.value;
    if (current === null || !reorderEnabled.value) return false;
    const next = moveIds(current.order, selected, mode);
    if (next === current.order) return false;
    commit({ ...current, order: next });
    return true;
  }

  function moveTo(dragged: ReadonlySet<number>, targetIndex: number): boolean {
    const current = working.value;
    if (current === null || !reorderEnabled.value) return false;
    const next = moveIdsTo(current.order, dragged, targetIndex);
    if (next === current.order) return false;
    commit({ ...current, order: next });
    return true;
  }

  function storedItem(id: number): GalleryItem | undefined {
    return base.value?.items.get(id);
  }

  /** Stage a text change of one item (`null` = inherit, `''` = intentional blank). */
  function setItemTexts(id: number, patch: Partial<ItemTexts>): void {
    const current = working.value;
    const stored = storedItem(id);
    if (current === null || stored === undefined) return;
    const next: ItemTexts = { ...(current.overrides[id] ?? stored.overrides), ...patch };
    const overrides = { ...current.overrides };
    if (deepEqual(next, stored.overrides)) delete overrides[id];
    else overrides[id] = next;
    commit({ ...current, overrides }, `item:${id}:${Object.keys(patch).join(',')}`);
  }

  function itemView(id: number): ItemView | null {
    const item = storedItem(id);
    if (item === undefined) return null;
    const staged = working.value?.overrides[id];
    const texts = staged ?? item.overrides;
    return {
      item,
      texts,
      effective: item.missing ? item.effective : effectiveTexts(item, texts),
      edited: staged !== undefined,
    };
  }

  // ---- failures, conflict and re-sync
  async function openConflict(known?: FreshState): Promise<void> {
    const current = base.value;
    const staged = working.value;
    if (current === null || staged === null) return;
    try {
      const fresh = known ?? (await fetchEditorState(api, galleryId));
      const freshBase = baseFromFresh(fresh);
      conflict.value = {
        diff: diffServer(current, freshBase),
        notices: rebase(current, staged, fresh).notices,
        serverTitle: fresh.gallery.title,
        stagedCount: countChanges(current, staged),
        fresh,
      };
    } catch (error) {
      const kind = classifyFailure(error);
      if (kind === 'gone') gone.value = true;
      lastFailure.value = kind;
    }
  }

  /** After a failure: look at the server; if someone else changed it, show the conflict dialog. */
  async function resync(): Promise<void> {
    const current = base.value;
    if (current === null) return;
    try {
      const fresh = await fetchEditorState(api, galleryId);
      if (fresh.revision !== current.revision) await openConflict(fresh);
    } catch (error) {
      if (classifyFailure(error) === 'gone') gone.value = true;
    }
  }

  async function handleFailure(error: unknown): Promise<FailureKind> {
    const kind = classifyFailure(error);
    lastFailure.value = kind;
    switch (kind) {
      case 'conflict':
        await openConflict();
        break;
      case 'gone':
        gone.value = true;
        break;
      case 'invalidState':
        invalidState.value = true;
        break;
      case 'itemGone':
      case 'network':
      case 'other':
        await resync();
        break;
      default:
        break;
    }
    return kind;
  }

  function recordValidation(error: unknown): void {
    invalidDetails.value = invalidFields(error);
    const issues: SettingIssue[] = [];
    if (isApiError(error) && error.details !== null && typeof error.details === 'object') {
      const list = (error.details as { errors?: unknown }).errors;
      if (Array.isArray(list)) {
        for (const entry of list) {
          const { path, code } = (entry ?? {}) as { path?: unknown; code?: unknown };
          if (typeof path === 'string') {
            issues.push({
              path: path
                .split('/')
                .filter((part) => part !== '')
                .map((part) => part.replace(/~1/g, '/').replace(/~0/g, '~')),
              code: typeof code === 'string' ? code : '',
            });
          }
        }
      }
    }
    settingIssues.value = issues;
  }

  /** Forgets reported validation problems (e.g. after a preview of the current state rendered fine). */
  function clearValidation(): void {
    settingIssues.value = [];
    invalidDetails.value = [];
  }

  /** `reapply`: staged changes onto the fresh state; `discard`: take the server state. */
  function resolveConflict(choice: 'reapply' | 'discard'): void {
    const state = conflict.value;
    const current = base.value;
    const staged = working.value;
    if (state === null || current === null || staged === null) return;
    if (choice === 'discard') {
      adopt(state.fresh);
      rebaseNotices.value = null;
    } else {
      const result = rebase(current, staged, state.fresh);
      base.value = result.base;
      working.value = result.working;
      undoStack.value = [];
      redoStack.value = [];
      lastKey = '';
      rebaseNotices.value = result.notices;
    }
    conflict.value = null;
    invalidState.value = base.value?.gallery.status === 'trash';
  }

  // ---- save pipeline
  function updateBase(change: (current: Base) => Base): void {
    if (base.value !== null) base.value = change(base.value);
  }

  async function runStep(step: SaveStep): Promise<void> {
    const current = base.value;
    if (current === null) return;
    switch (step.kind) {
      case 'remove': {
        const result = await api.items.bulkRemove(galleryId, step.itemIds, current.revision);
        const gone = new Set(step.itemIds);
        updateBase((b) => ({
          ...b,
          revision: result.revision,
          order: b.order.filter((id) => !gone.has(id)),
          items: new Map([...b.items].filter(([id]) => !gone.has(id))),
        }));
        break;
      }
      case 'override': {
        const result = await api.items.patch(
          galleryId,
          step.itemId,
          { overrides: step.overrides },
          current.revision,
        );
        updateBase((b) => ({
          ...b,
          revision: result.revision,
          items: new Map(b.items).set(step.itemId, result.item),
        }));
        break;
      }
      case 'order': {
        const result = await api.items.setOrder(galleryId, step.itemIds, current.revision);
        updateBase((b) => ({ ...b, revision: result.revision, order: [...step.itemIds] }));
        break;
      }
      case 'details': {
        // Only the changed fields; `settings` is the complete merged map (the server replaces it).
        const saved = await api.galleries.update(
          galleryId,
          current.revision,
          step.changes as UpdateGalleryInput,
        );
        updateBase((b) => ({ ...b, revision: saved.revision, gallery: saved }));
        break;
      }
    }
  }

  async function save(): Promise<SaveReport | null> {
    const current = base.value;
    const staged = working.value;
    if (saving.value || current === null || staged === null) return null;
    const plan = buildPlan(current, staged);
    if (plan.length === 0) return null;
    saving.value = true;
    report.value = null;
    lastFailure.value = null;
    settingIssues.value = [];
    invalidDetails.value = [];
    const completed: SaveStep['kind'][] = [];
    let failed: SaveReport['failed'] = null;
    let failureError: unknown = null;
    for (const step of plan) {
      progress.value = { done: completed.length, total: plan.length, kind: step.kind };
      try {
        await runStep(step);
        completed.push(step.kind);
      } catch (error) {
        failureError = error;
        failed = { kind: step.kind, failure: classifyFailure(error), error };
        break;
      }
    }
    progress.value = null;
    // Saved steps are part of the server truth now; their old snapshots are meaningless.
    if (completed.length > 0) {
      undoStack.value = [];
      redoStack.value = [];
      lastKey = '';
    }
    const result: SaveReport = { ok: failed === null, total: plan.length, completed, failed };
    if (failed === null) {
      if (base.value !== null) working.value = initialWorking(base.value);
      rebaseNotices.value = null;
    } else {
      if (failed.failure === 'invalid') recordValidation(failureError);
      await handleFailure(failureError);
    }
    report.value = result;
    saving.value = false;
    return result;
  }

  /** Stage "published" and save everything in one go. */
  async function publish(): Promise<SaveReport | null> {
    setDetail('status', 'publish');
    return save();
  }

  // ---- adding images (applied immediately, the server assigns the item ids)
  async function addAttachments(attachmentIds: number[]): Promise<AddOutcome> {
    const current = base.value;
    if (current === null || saving.value) return { ok: false, failure: 'other' };
    const unique = [...new Set(attachmentIds)];
    try {
      const result = await api.items.assign(galleryId, unique, current.revision);
      const added = countOf(result.added);
      updateBase((b) => ({ ...b, revision: result.revision }));
      if (added > 0) await mergeAppended(current.order.length, added, result.revision);
      return {
        ok: true,
        added,
        alreadyAssigned: countOf(result.alreadyAssigned),
        missing: countOf(result.missing),
      };
    } catch (error) {
      return { ok: false, failure: await handleFailure(error) };
    }
  }

  /** Fetches only the tail of the list (new items are appended) and merges it into base, working and history. */
  async function mergeAppended(
    previousCount: number,
    added: number,
    revision: number,
  ): Promise<void> {
    const current = base.value;
    if (current === null) return;
    const fresh: GalleryItem[] = [];
    let page = Math.floor(previousCount / ITEM_PAGE_SIZE) + 1;
    let result = await api.items.list(galleryId, page);
    fresh.push(...result.items.slice(previousCount % ITEM_PAGE_SIZE));
    while (page < result.totalPages) {
      page += 1;
      result = await api.items.list(galleryId, page);
      fresh.push(...result.items);
    }
    const seenRevision = result.revision;
    const totalNow = result.total;
    const known = new Set(current.order);
    const unseen = fresh.filter((item) => !known.has(item.id));
    if (
      seenRevision !== revision ||
      totalNow !== previousCount + added ||
      unseen.length !== added
    ) {
      // Someone else changed the gallery meanwhile: let the user reconcile explicitly.
      await openConflict();
      return;
    }
    const ids = unseen.map((item) => item.id);
    updateBase((b) => ({
      ...b,
      order: [...b.order, ...ids],
      items: new Map([...b.items, ...unseen.map((item): [number, GalleryItem] => [item.id, item])]),
    }));
    const append = (snapshot: Working): Working => ({
      ...snapshot,
      order: [...snapshot.order, ...ids],
    });
    if (working.value !== null) working.value = append(working.value);
    undoStack.value = undoStack.value.map(append);
    redoStack.value = redoStack.value.map(append);
    total.value = totalNow;
  }

  return {
    phase,
    loadError,
    gone,
    invalidState,
    truncated,
    total,
    base,
    working,
    saving,
    progress,
    report,
    lastFailure,
    settingIssues,
    invalidDetails,
    conflict,
    rebaseNotices,
    changeCount,
    dirty,
    canUndo,
    canRedo,
    revision,
    order,
    removedCount,
    reorderEnabled,
    load,
    undo,
    redo,
    discard,
    setDetail,
    setSetting,
    removeItems,
    move,
    moveTo,
    setItemTexts,
    itemView,
    save,
    publish,
    addAttachments,
    resolveConflict,
    openConflict,
    /** Shows the field errors of a 400/422 answer (also used by the live preview). */
    recordValidation,
    clearValidation,
    /** What is currently staged, by kind (details keys, edited item ids, order, removed count). */
    stagedSummary: () =>
      base.value !== null && working.value !== null
        ? {
            details: changedDetailKeys(detailsOf(base.value.gallery), working.value.details),
            overrides: changedOverrideIds(base.value, working.value),
            order: orderChanged(base.value, working.value),
            removed: removedIds(base.value, working.value).length,
          }
        : null,
  };
}

export type EditorSession = ReturnType<typeof createEditorSession>;
