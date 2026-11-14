import { isApiError } from './client';

type Translate = (key: string) => string;

/** Server error codes (docs/rest-api.md) with their own catalog message. Everything else falls back to the HTTP status. */
const CODE_MESSAGES: Record<string, string> = {
  swg_cannot_publish: 'errors.cannotPublish',
  swg_invalid_cover: 'errors.invalidCover',
  swg_invalid_request: 'errors.invalidRequest',
  swg_no_changes: 'errors.noChanges',
  swg_revision_conflict: 'errors.revisionConflict',
  swg_invalid_state: 'errors.invalidState',
  swg_operation_vetoed: 'errors.vetoed',
  swg_gallery_not_found: 'errors.galleryNotFound',
};

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
  const byCode = CODE_MESSAGES[error.code];
  if (byCode !== undefined) return t(byCode);
  switch (error.status) {
    case 400:
    case 422:
      return t('errors.validation');
    case 401:
      return t('errors.sessionExpired');
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

/**
 * Names of the request parameters a 400 (`rest_invalid_param`) or 422 (`swg_validation_failed`) points at.
 * Only the names are used; the server's wording is never displayed.
 */
export function invalidFields(error: unknown): string[] {
  if (!isApiError(error) || (error.status !== 400 && error.status !== 422)) return [];
  const names = new Set(Object.keys(error.params));
  const details = error.details;
  if (details && typeof details === 'object') {
    const list = (details as { errors?: unknown }).errors;
    if (Array.isArray(list)) {
      for (const entry of list) {
        const path = (entry as { path?: unknown } | null)?.path;
        if (typeof path === 'string' && path !== '') {
          // `title`, `settings.columns[0]` and JSON pointers (`/columns/md`) all name their first segment.
          const first = path.split(/[./[]/).find((part) => part !== '');
          if (first !== undefined) names.add(first);
        }
      }
    }
  }
  return [...names];
}
