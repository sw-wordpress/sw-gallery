<?php
/**
 * Image upload through native WordPress handling.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Rest;

use SWGallery\WordPress\Media\AllowedMimeTypes;

/**
 * Validates an uploaded file before anything is written (type from the bytes via `wp_check_filetype_and_ext`, only the
 * allowed raster types, size limit of WordPress, decodable by the image editor), moves it with `wp_handle_upload`,
 * creates the attachment and the sub-sizes the WordPress way. A failure after the file was stored removes it again.
 * Error messages never contain paths or file names.
 */
final class MediaUploader {

	/**
	 * @param array<string, mixed> $file    One `$_FILES`-style entry (name, tmp_name, error, size).
	 * @param int                  $user_id Uploader; becomes the attachment author.
	 * @param string               $title   Optional title (default: sanitized file name without extension).
	 * @param string               $alt     Optional alternative text.
	 * @return int Attachment id.
	 * @throws MediaUploadException When the upload is refused or fails.
	 */
	public function upload( array $file, int $user_id, string $title = '', string $alt = '' ): int {
		if ( ! user_can( $user_id, 'upload_files' ) ) {
			throw new MediaUploadException( MediaUploadException::FAILED, __( 'You are not allowed to upload files.', 'sw-gallery' ), 403 );
		}
		$file = $this->normalize( $file );
		$this->check_transfer( $file );
		$mimes = $this->allowed_mimes( $user_id );
		$this->check_type( $file, $mimes );
		$this->check_decodable( $file['tmp_name'] );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$overrides = array(
			'test_form' => false,
			'mimes'     => $mimes,
		);
		// Bypasses is_uploaded_file() when running unit tests, exactly as the core REST attachments controller does.
		if ( defined( 'DIR_TESTDATA' ) && DIR_TESTDATA ) {
			$overrides['action'] = 'wp_handle_mock_upload';
		}
		$name = $file['name'];
		/**
		 * Result of the core upload handler (or `array( 'error' => ... )`).
		 *
		 * @var array<string, mixed> $moved
		 */
		$moved = wp_handle_upload( $file, $overrides );
		if ( isset( $moved['error'] ) || ! is_string( $moved['file'] ?? null ) || ! is_string( $moved['url'] ?? null ) || ! is_string( $moved['type'] ?? null ) ) {
			// The core message may name directories; it is never passed on.
			throw new MediaUploadException( MediaUploadException::FAILED, __( 'The file could not be saved.', 'sw-gallery' ), 500 );
		}

		$ext      = pathinfo( $name, PATHINFO_EXTENSION );
		$title    = '' !== $title ? $title : sanitize_text_field( wp_basename( $name, '' === $ext ? '' : '.' . $ext ) );
		$attached = wp_insert_attachment(
			array(
				'post_mime_type' => (string) $moved['type'],
				'guid'           => (string) $moved['url'],
				'post_title'     => $title,
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_author'    => $user_id,
			),
			(string) $moved['file'],
			0,
			true
		);
		if ( is_wp_error( $attached ) ) {
			wp_delete_file( (string) $moved['file'] );
			throw new MediaUploadException( MediaUploadException::FAILED, __( 'The file could not be saved.', 'sw-gallery' ), 500 );
		}

		/**
		 * Generated sub-size data; empty (or without dimensions) when the image cannot be processed.
		 *
		 * @var array<string, mixed> $metadata
		 */
		$metadata = wp_generate_attachment_metadata( $attached, (string) $moved['file'] );
		if ( array() === $metadata || (int) ( $metadata['width'] ?? 0 ) < 1 || (int) ( $metadata['height'] ?? 0 ) < 1 ) {
			wp_delete_attachment( $attached, true );
			throw new MediaUploadException( MediaUploadException::INVALID_IMAGE, __( 'The file is not a valid image.', 'sw-gallery' ), 422 );
		}
		wp_update_attachment_metadata( $attached, $metadata );
		if ( '' !== $alt ) {
			update_post_meta( $attached, '_wp_attachment_image_alt', $alt );
		}

		return $attached;
	}

	/**
	 * @param array<string, mixed> $file Submitted entry.
	 * @return array{name: string, tmp_name: string, error: int, size: int, type: string}
	 * @throws MediaUploadException When the entry is malformed.
	 */
	private function normalize( array $file ): array {
		$name = $file['name'] ?? null;
		$tmp  = $file['tmp_name'] ?? null;
		if ( ! is_string( $name ) || ! is_string( $tmp ) || ! is_numeric( $file['error'] ?? null ) || ! is_numeric( $file['size'] ?? null ) ) {
			throw new MediaUploadException( MediaUploadException::MISSING_FILE, __( 'No file was uploaded.', 'sw-gallery' ), 400 );
		}
		return array(
			'name'     => $name,
			'tmp_name' => $tmp,
			'error'    => (int) $file['error'],
			'size'     => (int) $file['size'],
			'type'     => is_string( $file['type'] ?? null ) ? (string) $file['type'] : '',
		);
	}

	/**
	 * @param array{name: string, tmp_name: string, error: int, size: int, type: string} $file Entry.
	 * @throws MediaUploadException When the transfer failed or the file is too large or empty.
	 */
	private function check_transfer( array $file ): void {
		switch ( $file['error'] ) {
			case UPLOAD_ERR_OK:
				break;
			case UPLOAD_ERR_NO_FILE:
				throw new MediaUploadException( MediaUploadException::MISSING_FILE, __( 'No file was uploaded.', 'sw-gallery' ), 400 );
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				throw $this->too_large();
			case UPLOAD_ERR_PARTIAL:
				throw new MediaUploadException( MediaUploadException::FAILED, __( 'The file was only partially uploaded. Please try again.', 'sw-gallery' ), 400 );
			default:
				throw new MediaUploadException( MediaUploadException::FAILED, __( 'The file could not be saved.', 'sw-gallery' ), 500 );
		}
		if ( $file['size'] > wp_max_upload_size() ) {
			throw $this->too_large();
		}
		if ( $file['size'] < 1 || '' === $file['tmp_name'] || ! is_readable( $file['tmp_name'] ) ) {
			throw new MediaUploadException( MediaUploadException::MISSING_FILE, __( 'The uploaded file is empty.', 'sw-gallery' ), 400 );
		}
	}

	private function too_large(): MediaUploadException {
		return new MediaUploadException( MediaUploadException::TOO_LARGE, __( 'The file is larger than the maximum upload size.', 'sw-gallery' ), 413 );
	}

	/**
	 * @param int $user_id Uploader.
	 * @return array<string, string> Extension pattern => MIME type, restricted to the gallery image types.
	 */
	private function allowed_mimes( int $user_id ): array {
		$allowed = AllowedMimeTypes::all();
		return array_filter(
			get_allowed_mime_types( $user_id ),
			static fn( string $mime ): bool => in_array( $mime, $allowed, true )
		);
	}

	/**
	 * @param array{name: string, tmp_name: string, error: int, size: int, type: string} $file  Entry.
	 * @param array<string, string>                                                      $mimes Allowed types.
	 * @throws MediaUploadException When the content is not an allowed image type.
	 */
	private function check_type( array $file, array $mimes ): void {
		$checked = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $mimes );
		$type    = $checked['type'];
		if ( ! is_string( $type ) || '' === $type || ! is_string( $checked['ext'] ) || '' === $checked['ext'] || ! AllowedMimeTypes::is_allowed( $type ) ) {
			throw new MediaUploadException( MediaUploadException::UNSUPPORTED_TYPE, __( 'This file type is not supported. Use a JPEG, PNG, GIF, WebP or AVIF image.', 'sw-gallery' ), 422 );
		}
	}

	/**
	 * @param string $tmp_name Temporary file.
	 * @throws MediaUploadException When the image cannot be decoded.
	 */
	private function check_decodable( string $tmp_name ): void {
		wp_raise_memory_limit( 'image' );
		$size = wp_getimagesize( $tmp_name );
		if ( ! is_array( $size ) || $size[0] < 1 || $size[1] < 1 || is_wp_error( wp_get_image_editor( $tmp_name ) ) ) {
			throw new MediaUploadException( MediaUploadException::INVALID_IMAGE, __( 'The file is not a valid image.', 'sw-gallery' ), 422 );
		}
	}
}
