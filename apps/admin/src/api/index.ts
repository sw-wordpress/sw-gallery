import { getConfig } from '@/config';
import { ApiClient } from './client';

export * from './client';
export { errorMessage, invalidFields } from './errorMessage';
export * from './galleries';
export * from './items';
export * from './layouts';
export * from './media';
export * from './settings';
export * from './status';

let client: ApiClient | null = null;

/** App-wide client, built once from the server bootstrap. */
export function getApi(): ApiClient {
  if (client === null) {
    const config = getConfig();
    client = new ApiClient({ restRoot: config.restRoot, nonce: config.nonce });
  }
  return client;
}

/** For tests. */
export function resetApiForTests(): void {
  client = null;
}
