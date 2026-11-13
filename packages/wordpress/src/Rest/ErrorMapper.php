<?php
/**
 * Maps core exceptions and unexpected failures to REST errors.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Rest;

use SWGallery\Core\Contract\LoggerInterface;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Exception\AccessDeniedException;
use SWGallery\Core\Exception\GalleryException;
use SWGallery\Core\Exception\GalleryNotFoundException;
use SWGallery\Core\Exception\InvalidOrderException;
use SWGallery\Core\Exception\InvalidStateException;
use SWGallery\Core\Exception\ItemNotFoundException;
use SWGallery\Core\Exception\OperationVetoedException;
use SWGallery\Core\Exception\RevisionConflictException;
use SWGallery\Core\Exception\UnknownLayoutException;
use SWGallery\Core\Exception\ValidationFailedException;
use SWGallery\Core\Logging\LogLevel;
use SWGallery\WordPress\Support\CoreMessages;

/**
 * The only place that knows HTTP statuses of domain failures (CODE-STYLE 3.1). Domain errors keep the stable code of
 * the exception and a translated, safe message. Anything else becomes a generic 500: the details go to the logger
 * with a safe code and the exception class, never into the response (no messages, paths or SQL).
 */
final class ErrorMapper {

	public const CODE_INVALID_REQUEST = 'swg_invalid_request';
	public const CODE_INTERNAL        = 'swg_internal_error';

	public function __construct( private readonly LoggerInterface $logger ) {
	}

	/**
	 * @param \Throwable $error The failure.
	 * @param Actor      $actor The requesting actor (anonymous visitors get 401 instead of 403).
	 */
	public function map( \Throwable $error, Actor $actor ): \WP_Error {
		if ( $error instanceof GalleryException ) {
			return $this->map_domain( $error, $actor );
		}
		if ( $error instanceof \InvalidArgumentException ) {
			// Domain validation text is developer English and may echo input; the response stays generic.
			return self::unprocessable( self::CODE_INVALID_REQUEST, __( 'The request contains invalid values.', 'sw-gallery' ) );
		}
		return $this->internal( $error );
	}

	/**
	 * A well-formed request that is semantically invalid (HTTP 422).
	 *
	 * @param string               $code    Stable error code.
	 * @param string               $message Translated message.
	 * @param array<string, mixed> $data    Extra safe data.
	 */
	public static function unprocessable( string $code, string $message, array $data = array() ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => 422 ) + $data );
	}

	/**
	 * A malformed request (HTTP 400).
	 *
	 * @param string $code    Stable error code.
	 * @param string $message Translated message.
	 */
	public static function bad_request( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => 400 ) );
	}

	private function map_domain( GalleryException $error, Actor $actor ): \WP_Error {
		$code    = $error->errorCode();
		$message = CoreMessages::translate( $error->getMessage() );

		if ( $error instanceof AccessDeniedException ) {
			return new \WP_Error( $code, $message, array( 'status' => $actor->isAnonymous() ? 401 : 403 ) );
		}
		if ( $error instanceof GalleryNotFoundException || $error instanceof ItemNotFoundException ) {
			return new \WP_Error( $code, $message, array( 'status' => 404 ) );
		}
		if ( $error instanceof RevisionConflictException ) {
			return new \WP_Error(
				$code,
				$message,
				array(
					'status'           => 409,
					'expectedRevision' => $error->expected,
					'currentRevision'  => $error->actual,
				)
			);
		}
		if ( $error instanceof InvalidStateException ) {
			return new \WP_Error( $code, $message, array( 'status' => 409 ) );
		}
		if ( $error instanceof OperationVetoedException ) {
			return new \WP_Error(
				$code,
				$message,
				array(
					'status' => 403,
					'reason' => $error->reason,
				)
			);
		}
		if ( $error instanceof InvalidOrderException || $error instanceof UnknownLayoutException ) {
			return self::unprocessable( $code, $message );
		}
		if ( $error instanceof ValidationFailedException ) {
			$errors = array();
			foreach ( $error->errors as $failure ) {
				$entry            = $failure->toArray();
				$entry['message'] = CoreMessages::translate_validation( $entry['code'], $entry['message'] );
				$errors[]         = $entry;
			}
			return self::unprocessable( $code, $message, array( 'errors' => $errors ) );
		}
		return $this->internal( $error );
	}

	private function internal( \Throwable $error ): \WP_Error {
		$this->logger->log( LogLevel::Error, 'swg_rest_unexpected_error', array( 'class' => $error::class ) );
		return new \WP_Error(
			self::CODE_INTERNAL,
			__( 'An unexpected error occurred.', 'sw-gallery' ),
			array( 'status' => 500 )
		);
	}
}
