import { getConfig, hasCapability } from '@/config';

/** UX-only capability gate (hides what the server would refuse anyway). The server stays authoritative. */
export function useCapabilities() {
  const config = getConfig();
  return {
    can: (capability: string): boolean => hasCapability(config, capability),
  };
}
