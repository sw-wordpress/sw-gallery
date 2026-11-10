<?php
/**
 * Logger sink of the WordPress adapter: `error_log` with WP_DEBUG_LOG plus a bounded ring buffer of errors.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Infrastructure;

use SWGallery\Core\Contract\LoggerInterface;
use SWGallery\Core\Logging\LogLevel;

final class WpLogger implements LoggerInterface {

	/** Non-autoloaded option holding the last errors (read by the System Status page). */
	public const OPTION      = 'swg_error_log';
	public const MAX_ENTRIES = 50;

	private const MAX_VALUE_LENGTH = 120;
	private const SENSITIVE_KEY    = '/token|secret|passw|authoriz|nonce|cookie|key|signature|url|uri|path|file|message/i';

	/** @var callable(string): mixed */
	private $sink;

	/**
	 * @param bool|null     $debug_log Whether to write to the PHP error log (default: WP_DEBUG_LOG).
	 * @param callable|null $sink      Receives the formatted line (default: error_log).
	 */
	public function __construct( private readonly ?bool $debug_log = null, ?callable $sink = null ) {
		$this->sink = $sink ?? 'error_log';
	}

	public function log( LogLevel $level, string $code, array $context = array() ): void {
		$code    = $this->safe_code( $code );
		$context = $this->sanitize_context( $context );

		if ( $this->debug_log_enabled() ) {
			( $this->sink )( 'sw-gallery ' . $level->value . ' ' . $code . ' ' . wp_json_encode( $context ) );
		}
		if ( LogLevel::Error === $level ) {
			$this->remember( $code, $context );
		}
	}

	/**
	 * The most recent error entries, newest first.
	 *
	 * @return list<array{time: string, code: string, context: array<string, scalar|null>}>
	 */
	public function recent(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$entries = array();
		foreach ( $stored as $entry ) {
			if ( is_array( $entry ) && isset( $entry['time'], $entry['code'] ) && is_string( $entry['time'] ) && is_string( $entry['code'] ) ) {
				$context   = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $this->sanitize_context( $entry['context'] ) : array();
				$entries[] = array(
					'time'    => $entry['time'],
					'code'    => $entry['code'],
					'context' => $context,
				);
			}
		}
		return array_reverse( $entries );
	}

	public function clear(): void {
		delete_option( self::OPTION );
	}

	private function debug_log_enabled(): bool {
		return $this->debug_log ?? ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG );
	}

	private function safe_code( string $code ): string {
		return 1 === preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $code ) ? $code : 'swg_invalid_log_code';
	}

	/**
	 * Keeps only scalar values under short snake_case keys; secrets, URLs and paths are never recorded.
	 *
	 * @param array<mixed> $context
	 * @return array<string, scalar|null>
	 */
	private function sanitize_context( array $context ): array {
		$safe = array();
		foreach ( $context as $key => $value ) {
			$key = (string) $key;
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $key ) || 1 === preg_match( self::SENSITIVE_KEY, $key ) ) {
				continue;
			}
			if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$safe[ $key ] = $value;
			} elseif ( is_string( $value ) ) {
				$safe[ $key ] = $this->safe_string( $value );
			}
		}
		return $safe;
	}

	private function safe_string( string $value ): string {
		if ( str_contains( $value, '://' ) || str_contains( $value, '/' ) || str_contains( $value, '\\' ) ) {
			return '[redacted]';
		}
		$value = (string) preg_replace( '/\p{C}+/u', ' ', $value );
		return strlen( $value ) > self::MAX_VALUE_LENGTH ? substr( $value, 0, self::MAX_VALUE_LENGTH ) : $value;
	}

	/**
	 * @param string                     $code    Safe log code.
	 * @param array<string, scalar|null> $context Sanitized context.
	 */
	private function remember( string $code, array $context ): void {
		$stored = get_option( self::OPTION, array() );
		$list   = is_array( $stored ) ? array_values( $stored ) : array();
		$list[] = array(
			'time'    => gmdate( 'Y-m-d H:i:s' ),
			'code'    => $code,
			'context' => $context,
		);
		$list   = array_slice( $list, -self::MAX_ENTRIES );
		update_option( self::OPTION, $list, false );
	}
}
