/** Data the PHP side hands to the editor script (`window.swgBlock`, see Block/BlockRegistrar). */
export interface BlockConfig {
  /** URL of the plugin's gallery screen; the Vue admin app lives behind its hash. */
  galleriesUrl: string;
}

/** The admin screen is a sibling of the editor inside wp-admin, so this relative URL works without data too. */
export const FALLBACK_GALLERIES_URL = 'admin.php?page=swg-galleries';

export function readConfig(source: unknown): BlockConfig {
  const value =
    typeof source === 'object' && source !== null
      ? (source as { swgBlock?: unknown }).swgBlock
      : undefined;
  const url =
    typeof value === 'object' && value !== null
      ? (value as { galleriesUrl?: unknown }).galleriesUrl
      : undefined;
  return {
    galleriesUrl:
      typeof url === 'string' && url !== '' && !url.includes('#') ? url : FALLBACK_GALLERIES_URL,
  };
}
