<?php
/**
 * Bounded audit trail of MCP ability calls.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Mcp;

/**
 * Records who called which ability with which outcome, never inputs or outputs. Persistent (independent of debug
 * logging), bounded, non-autoloaded and separate from the error ring buffer. The ability is stored as its verb-noun
 * name because the logger convention redacts anything with a slash.
 */
final class McpAuditLog {

	public const OPTION      = 'swg_mcp_audit';
	public const MAX_ENTRIES = 50;
	public const ACTION      = 'swg_mcp_ability_called';

	public const OK     = 'ok';
	public const DENIED = 'denied';
	public const ERROR  = 'error';

	/**
	 * @param string $ability Verb-noun ability name.
	 * @param int    $user_id Acting user (0 = anonymous).
	 * @param string $outcome `ok`, `denied` or `error`.
	 * @param string $code    Stable result code (`ok` or an error code).
	 */
	public function record( string $ability, int $user_id, string $outcome, string $code ): void {
		$entry  = array(
			'time'    => gmdate( 'Y-m-d H:i:s' ),
			'ability' => 1 === preg_match( '/^[a-z][a-z0-9-]{0,39}$/', $ability ) ? $ability : 'invalid',
			'user'    => max( 0, $user_id ),
			'outcome' => in_array( $outcome, array( self::OK, self::DENIED, self::ERROR ), true ) ? $outcome : self::ERROR,
			'code'    => 1 === preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $code ) ? $code : 'swg_invalid_code',
		);
		$stored = get_option( self::OPTION, array() );
		$list   = is_array( $stored ) ? array_values( $stored ) : array();
		$list[] = $entry;
		update_option( self::OPTION, array_slice( $list, -self::MAX_ENTRIES ), false );

		// Observers are informational: a failing listener never changes the outcome (docs/hooks.md, fail open).
		try {
			do_action( 'swg_mcp_ability_called', $entry['ability'], $entry['user'], $entry['outcome'], $entry['code'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- "swg_" is the documented plugin prefix (see phpcs-wordpress.xml.dist).
		} catch ( \Throwable $error ) {
			unset( $error );
		}
	}

	/** @return list<array<string, mixed>> Newest first. */
	public function recent(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? array_reverse( array_values( $stored ) ) : array();
	}
}
