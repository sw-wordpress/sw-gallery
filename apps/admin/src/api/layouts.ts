import type { ApiClient } from './client';

/** `GET /layouts`: registered layouts with their (JSON-schema like) settings schema. */
export interface JsonSchema {
  type?: string | string[];
  title?: string;
  description?: string;
  default?: unknown;
  enum?: unknown[];
  minimum?: number;
  maximum?: number;
  minLength?: number;
  maxLength?: number;
  properties?: Record<string, JsonSchema>;
  'x-responsive'?: boolean;
  'x-item-settings'?: JsonSchema;
  [key: string]: unknown;
}

export interface LayoutInfo {
  id: string;
  label: string;
  settingsSchema: JsonSchema;
}

export interface LayoutsResponse {
  fallbackId: string;
  layouts: LayoutInfo[];
}

export function createLayoutsApi(client: ApiClient) {
  return {
    list(signal?: AbortSignal): Promise<LayoutsResponse> {
      return client.get<LayoutsResponse>('layouts', undefined, signal);
    },
  };
}

export type LayoutsApi = ReturnType<typeof createLayoutsApi>;
