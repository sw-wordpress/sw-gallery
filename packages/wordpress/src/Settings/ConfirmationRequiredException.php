<?php
/**
 * An irreversible setting was changed without the explicit confirmation.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Settings;

/** Maps to HTTP 422 `swg_confirmation_required`; the message is never shown to users. */
final class ConfirmationRequiredException extends \RuntimeException {
}
