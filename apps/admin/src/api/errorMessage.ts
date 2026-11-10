import { isApiError } from './client';

type Translate = (key: string) => string;

/**
 * Translate any thrown value into a user-facing message from the i18n catalogs.
 * Server-provided message text is never shown verbatim (it may be English-only or contain
 * internals); the mapping uses the error code and HTTP status.
 */
export function errorMessage(error: unknown, t: Translate): string {
  if (!isApiError(error)) return t('errors.generic');
  if (error.isAborted) return t('errors.aborted');
  if (error.isNetwork) return t('errors.network');
  if (error.code === 'swg_invalid_response') return t('errors.invalidResponse');
  switch (error.status) {
    case 400:
    case 422:
      return t('errors.validation');
    case 401:
    case 403:
      return t('errors.forbidden');
    case 404:
      return t('errors.notFound');
    case 409:
      return t('errors.conflict');
    default:
      return error.status >= 500 ? t('errors.server') : t('errors.generic');
  }
}
