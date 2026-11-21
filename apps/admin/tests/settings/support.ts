import { vi } from 'vitest';
import type { Settings } from '../../src/api/settings';
import type { SettingsFeatureApi } from '../../src/features/settings/useSettingsForm';

export const NOTICE =
  'When the plugin is deleted, all galleries and their image relations are removed. Images in the Media Library are never deleted.';

export function makeSettings(overrides: Partial<Settings> = {}): Settings {
  return {
    defaultLayout: 'grid',
    itemsPerPage: 24,
    lightbox: true,
    hoverTitle: false,
    hoverCaption: false,
    hoverAnimation: 'fade',
    deleteDataOnUninstall: false,
    schemaVersion: 1,
    defaults: {
      defaultLayout: 'grid',
      itemsPerPage: 24,
      lightbox: true,
      hoverTitle: false,
      hoverCaption: false,
      hoverAnimation: 'fade',
      deleteDataOnUninstall: false,
    },
    deleteDataNotice: NOTICE,
    ...overrides,
  };
}

/** Stateful fake: `update` merges the patch like the server does and answers with the full object. */
export function fakeSettingsApi(initial: Settings = makeSettings()) {
  let current = initial;
  const api = {
    settings: {
      get: vi.fn(async () => current),
      update: vi.fn(async (patch: Record<string, unknown>) => {
        const { confirmDeleteData: _confirm, ...values } = patch;
        void _confirm;
        current = { ...current, ...values } as Settings;
        return current;
      }),
    },
    layouts: {
      list: vi.fn(async () => ({
        fallbackId: 'grid',
        layouts: [
          { id: 'grid', label: 'Grid', settingsSchema: {} },
          { id: 'masonry', label: 'Masonry', settingsSchema: {} },
        ],
      })),
    },
  };
  return api satisfies SettingsFeatureApi;
}
