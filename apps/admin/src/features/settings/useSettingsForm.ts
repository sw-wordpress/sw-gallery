import { computed, inject, onScopeDispose, reactive, ref, shallowRef } from 'vue';
import type { InjectionKey } from 'vue';
import { getApi } from '@/api';
import { ApiError, isApiError } from '@/api/client';
import { invalidFields } from '@/api/errorMessage';
import { createLayoutsApi } from '@/api/layouts';
import type { LayoutInfo, LayoutsApi } from '@/api/layouts';
import { createSettingsApi, ITEMS_PER_PAGE_MAX, ITEMS_PER_PAGE_MIN } from '@/api/settings';
import type { Settings, SettingsApi, SettingsPatch, SettingsValues } from '@/api/settings';

export interface SettingsFeatureApi {
  settings: SettingsApi;
  layouts: LayoutsApi;
}

/** Tests provide a fake; production builds everything from the shared client. */
export const SETTINGS_API: InjectionKey<SettingsFeatureApi> = Symbol('swg-settings-api');

export function useSettingsFeatureApi(): SettingsFeatureApi {
  const injected = inject(SETTINGS_API, null);
  if (injected !== null) return injected;
  const client = getApi();
  return { settings: createSettingsApi(client), layouts: createLayoutsApi(client) };
}

export type SettingsField = keyof SettingsValues;
export type FieldProblem = 'required' | 'integer' | 'min' | 'max' | 'layout' | 'server';

const KEYS: readonly SettingsField[] = [
  'defaultLayout',
  'itemsPerPage',
  'lightbox',
  'hoverTitle',
  'hoverCaption',
  'hoverAnimation',
  'deleteDataOnUninstall',
  'mcpAccessLevel',
];

function toApiError(failure: unknown): ApiError {
  return isApiError(failure) ? failure : new ApiError('swg_unknown', 'Unknown error', 0);
}

/** `null` while the number field is empty or not a number. */
function parseInteger(raw: string): number | null | 'fraction' {
  const text = raw.trim();
  if (text === '') return null;
  const value = Number(text);
  if (!Number.isFinite(value)) return null;
  return Number.isInteger(value) ? value : 'fraction';
}

/**
 * Settings form: the server values are the baseline, the draft holds the edits. Only changed
 * fields are sent (partial PATCH, last write wins). Turning `deleteDataOnUninstall` ON is a
 * two-step action (the page asks, `confirmDeleteData: true` goes along in the same request).
 */
export function useSettingsForm(api: SettingsFeatureApi) {
  const server = shallowRef<Settings | null>(null);
  const layouts = shallowRef<LayoutInfo[]>([]);
  const loading = ref(false);
  const loadError = shallowRef<ApiError | null>(null);
  const draft = reactive<SettingsValues>({
    defaultLayout: 'grid',
    itemsPerPage: 24,
    lightbox: true,
    hoverTitle: false,
    hoverCaption: false,
    hoverAnimation: 'fade',
    deleteDataOnUninstall: false,
  });
  /** Text of the number field: kept as typed so "abc" or "" can be reported. */
  const itemsPerPageText = ref('24');
  const deleteConfirmed = ref(false);
  const saving = ref(false);
  const saveError = shallowRef<ApiError | null>(null);
  const serverProblems = ref<Partial<Record<SettingsField, FieldProblem>>>({});

  let controller: AbortController | null = null;
  let sequence = 0;

  function applyServer(settings: Settings): void {
    server.value = settings;
    for (const key of KEYS) {
      (draft as Record<SettingsField, unknown>)[key] = settings[key];
    }
    itemsPerPageText.value = String(settings.itemsPerPage);
    deleteConfirmed.value = false;
    serverProblems.value = {};
  }

  async function load(): Promise<void> {
    controller?.abort();
    const own = new AbortController();
    controller = own;
    const mine = ++sequence;
    loading.value = true;
    loadError.value = null;
    try {
      const [settings, layoutList] = await Promise.all([
        api.settings.get(own.signal),
        api.layouts.list(own.signal),
      ]);
      if (mine !== sequence) return;
      layouts.value = layoutList.layouts;
      applyServer(settings);
    } catch (failure) {
      if (mine !== sequence) return;
      const error = toApiError(failure);
      if (error.isAborted) return;
      loadError.value = error;
    }
    if (mine === sequence) loading.value = false;
  }

  onScopeDispose(() => {
    sequence += 1;
    controller?.abort();
  });

  function setItemsPerPage(raw: string | number | null): void {
    itemsPerPageText.value = raw === null ? '' : String(raw);
    const parsed = parseInteger(itemsPerPageText.value);
    if (typeof parsed === 'number') draft.itemsPerPage = parsed;
    delete serverProblems.value.itemsPerPage;
  }

  function setLayout(id: string): void {
    draft.defaultLayout = id;
    delete serverProblems.value.defaultLayout;
  }

  /** Client-side mirror of the server bounds, plus the problems the last save reported. */
  const problems = computed<Partial<Record<SettingsField, FieldProblem>>>(() => {
    const out: Partial<Record<SettingsField, FieldProblem>> = { ...serverProblems.value };
    const parsed = parseInteger(itemsPerPageText.value);
    if (parsed === null) out.itemsPerPage = 'required';
    else if (parsed === 'fraction') out.itemsPerPage = 'integer';
    else if (parsed < ITEMS_PER_PAGE_MIN) out.itemsPerPage = 'min';
    else if (parsed > ITEMS_PER_PAGE_MAX) out.itemsPerPage = 'max';
    return out;
  });
  const valid = computed(() => Object.keys(problems.value).length === 0);

  /** Only what differs from the server values. */
  const changes = computed<SettingsPatch>(() => {
    const base = server.value;
    const patch: SettingsPatch = {};
    if (base === null) return patch;
    if (draft.defaultLayout !== base.defaultLayout) patch.defaultLayout = draft.defaultLayout;
    if (draft.itemsPerPage !== base.itemsPerPage) patch.itemsPerPage = draft.itemsPerPage;
    if (draft.lightbox !== base.lightbox) patch.lightbox = draft.lightbox;
    if (draft.hoverTitle !== base.hoverTitle) patch.hoverTitle = draft.hoverTitle;
    if (draft.hoverCaption !== base.hoverCaption) patch.hoverCaption = draft.hoverCaption;
    if (draft.hoverAnimation !== base.hoverAnimation) patch.hoverAnimation = draft.hoverAnimation;
    if (draft.mcpAccessLevel !== undefined && draft.mcpAccessLevel !== base.mcpAccessLevel) {
      patch.mcpAccessLevel = draft.mcpAccessLevel;
    }
    if (draft.deleteDataOnUninstall !== base.deleteDataOnUninstall) {
      patch.deleteDataOnUninstall = draft.deleteDataOnUninstall;
      if (draft.deleteDataOnUninstall && deleteConfirmed.value) patch.confirmDeleteData = true;
    }
    return patch;
  });
  const dirty = computed(() => Object.keys(changes.value).length > 0);
  /** An unconfirmed "on" can only come from a bug; it is never sent. */
  const sendable = computed(
    () =>
      !(
        draft.deleteDataOnUninstall &&
        !server.value?.deleteDataOnUninstall &&
        !deleteConfirmed.value
      ),
  );

  function discard(): void {
    if (server.value !== null) applyServer(server.value);
    saveError.value = null;
  }

  function resetToDefault(field: SettingsField): void {
    const defaults = server.value?.defaults;
    if (defaults === undefined) return;
    if (field === 'itemsPerPage') setItemsPerPage(defaults.itemsPerPage);
    else if (field === 'deleteDataOnUninstall')
      setDeleteData(defaults.deleteDataOnUninstall, false);
    else (draft as Record<SettingsField, unknown>)[field] = defaults[field];
    delete serverProblems.value[field];
  }

  /** `confirmed` is only meaningful when turning the setting on. */
  function setDeleteData(on: boolean, confirmed: boolean): void {
    draft.deleteDataOnUninstall = on;
    deleteConfirmed.value = on && confirmed;
  }

  async function save(): Promise<Settings | null> {
    if (!dirty.value || !valid.value || !sendable.value || saving.value) return null;
    saving.value = true;
    saveError.value = null;
    try {
      const saved = await api.settings.update(changes.value);
      applyServer(saved);
      return saved;
    } catch (failure) {
      const error = toApiError(failure);
      saveError.value = error;
      const next: Partial<Record<SettingsField, FieldProblem>> = {};
      if (error.code === 'swg_confirmation_required') {
        // Nothing changed on the server: fall back to the stored value and ask again on the next attempt.
        draft.deleteDataOnUninstall = server.value?.deleteDataOnUninstall ?? false;
        deleteConfirmed.value = false;
      } else if (error.code === 'swg_unknown_layout') {
        next.defaultLayout = 'layout';
        void refreshLayouts();
      } else {
        for (const name of invalidFields(error)) {
          if ((KEYS as readonly string[]).includes(name)) next[name as SettingsField] = 'server';
        }
      }
      serverProblems.value = next;
      return null;
    } finally {
      saving.value = false;
    }
  }

  async function refreshLayouts(): Promise<void> {
    try {
      layouts.value = (await api.layouts.list()).layouts;
    } catch {
      /* the previous list stays; the error of the save is already shown */
    }
  }

  return {
    server,
    layouts,
    loading,
    loadError,
    draft,
    itemsPerPageText,
    saving,
    saveError,
    problems,
    valid,
    dirty,
    changes,
    deleteConfirmed,
    load,
    save,
    discard,
    resetToDefault,
    setItemsPerPage,
    setLayout,
    setDeleteData,
  };
}

export type SettingsForm = ReturnType<typeof useSettingsForm>;
