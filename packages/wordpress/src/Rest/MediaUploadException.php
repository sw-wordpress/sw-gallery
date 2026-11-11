<?php
/**
 * A rejected upload with a stable code, an HTTP status and a safe, translated message.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Rest;

final class MediaUploadException extends \RuntimeException {

	public const MISSING_FILE     = 'swg_missing_file';
	public const TOO_LARGE        = 'swg_file_too_large';
	public const UNSUPPORTED_TYPE = 'swg_unsupported_media_type';
	public const INVALID_IMAGE    = 'swg_invalid_image';
	public const FAILED           = 'swg_upload_failed';

	/**
	 * @param string $error_code Stable code (one of the constants).
	 * @param string $message    Translated message without paths or file names.
	 * @param int    $status     HTTP status.
	 */
	public function __construct( public readonly string $error_code, string $message, public readonly int $status ) {
		parent::__construct( $message );
	}
}
