import type { ApiClient } from './client';
import type { HoverAnimation } from './galleries';

export const MCP_ACCESS_LEVELS = ['off', 'read', 'write', 'destructive', 'admin'] as const;
export type McpAccessLevel = (typeof MCP_ACCESS_LEVELS)[number];

/** `GET`/`PATCH /settings` (docs/rest-api.md "Settings"). */
export interface SettingsValues {
  defaultLayout: string;
  itemsPerPage: number;
  lightbox: boolean;
  /** Hover defaults for galleries without their own hover setting. */
  hoverTitle: boolean;
  hoverCaption: boolean;
  hoverAnimation: HoverAnimation;
  deleteDataOnUninstall: boolean;
  mcpAccessLevel?: McpAccessLevel;
}

export interface Settings extends SettingsValues {
  schemaVersion: number;
  defaults: SettingsValues;
  /** Plain statement of what deleting the plugin removes (and that attachments are never deleted). */
  deleteDataNotice: string;
  mcpEffectiveLevel?: McpAccessLevel;
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
