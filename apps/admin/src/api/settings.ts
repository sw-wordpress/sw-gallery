import type { ApiClient } from './client';

/** `GET`/`PATCH /settings` (docs/rest-api.md "Settings"). */
export interface SettingsValues {
  defaultLayout: string;
  itemsPerPage: number;
  lightbox: boolean;
  deleteDataOnUninstall: boolean;
}

export interface Settings extends SettingsValues {
  schemaVersion: number;
  defaults: SettingsValues;
  /** Plain statement of what deleting the plugin removes (and that attachments are never deleted). */
  deleteDataNotice: string;
}

export interface SettingsPatch extends Partial<SettingsValues> {
  /** Required in the same request when `deleteDataOnUninstall` is turned on. */
  confirmDeleteData?: boolean;
}

/** Server bounds of `itemsPerPage`. */
export const ITEMS_PER_PAGE_MIN = 1;
export const ITEMS_PER_PAGE_MAX = 200;

export function createSettingsApi(client: ApiClient) {
  return {
    get(signal?: AbortSignal): Promise<Settings> {
      return client.get<Settings>('settings', undefined, signal);
    },
    update(patch: SettingsPatch): Promise<Settings> {
      return client.patch<Settings>('settings', patch);
    },
  };
}

export type SettingsApi = ReturnType<typeof createSettingsApi>;
