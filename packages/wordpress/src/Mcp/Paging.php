<?php
/**
 * Page envelope of the list abilities: bounded pages, a byte budget and exact continuation (docs/mcp-abilities.md §3).
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Mcp;

use SWGallery\WordPress\Rest\AbstractController;

/**
 * A reply never exceeds the byte budget: whole records only, the rest follows through `next` (`{page, offset}`, same
 * filters and page size). A single record that does not fit alone is an explicit error, never a truncation.
 */
final class Paging {

	public const CODE_RECORD_TOO_LARGE = 'swg_mcp_record_too_large';

	/** Bytes reserved for the envelope (`total`, `totalPages`, `next`, extra fields). */
	private const ENVELOPE_RESERVE = 2048;

	/**
	 * @param int                                 $default_per_page Default page size.
	 * @param array<string, array<string, mixed>> $filters Additional filter properties.
	 * @return array<string, mixed>
	 */
	public static function inputSchema( int $default_per_page, array $filters = array() ): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $filters + self::inputProperties( $default_per_page ),
		);
	}

	/**
	 * @param string $description Search scope shown to the MCP client.
	 * @return array<string, mixed>
	 */
	public static function searchProperty( string $description ): array {
		return array(
			'description' => $description,
			'type'        => 'string',
			'maxLength'   => 200,
		);
	}

	/**
	 * Input schema properties `page`, `per_page`, `offset`.
	 *
	 * @param int $default_per_page Default page size.
	 * @return array<string, array<string, mixed>>
	 */
	public static function inputProperties( int $default_per_page ): array {
		return array(
			'page'     => array(
				'description' => 'Page number, from 1.',
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => AbstractController::MAX_PAGE,
				'default'     => 1,
			),
			'per_page' => array(
				'description' => 'Records per page.',
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => AbstractController::MAX_PER_PAGE,
				'default'     => $default_per_page,
			),
			'offset'   => array(
				'description' => 'Continuation: index of the first record inside the page. Only use the value from the previous reply\'s next field, together with its page and unchanged filters.',
				'type'        => 'integer',
				'minimum'     => 0,
				'maximum'     => AbstractController::MAX_PER_PAGE - 1,
				'default'     => 0,
			),
		);
	}

	/**
	 * Output schema properties of the envelope (without the records list).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function outputProperties(): array {
		return array(
			'total'      => array( 'type' => 'integer' ),
			'totalPages' => array( 'type' => 'integer' ),
			'next'       => array(
				'type'                 => array( 'object', 'null' ),
				'additionalProperties' => false,
				'properties'           => array(
					'page'   => array( 'type' => 'integer' ),
					'offset' => array( 'type' => 'integer' ),
				),
			),
		);
	}

	/**
	 * @param string                     $key      Name of the records list in the reply (`items`, `layouts`).
	 * @param list<array<string, mixed>> $records  All records of the requested page.
	 * @param int                        $total    Total records over all pages.
	 * @param int                        $page     Requested page.
	 * @param int                        $per_page Page size.
	 * @param int                        $offset   First record to return inside the page.
	 * @param int                        $budget   Byte cap of the whole reply.
	 * @param array<string, mixed>       $extra    Fixed fields added to the reply.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function build( string $key, array $records, int $total, int $page, int $per_page, int $offset, int $budget, array $extra = array() ): array|\WP_Error {
		$remaining = array_slice( $records, min( $offset, count( $records ) ) );
		$room      = max( 0, $budget - self::ENVELOPE_RESERVE - strlen( (string) wp_json_encode( $extra ) ) );
		$taken     = array();
		$used      = 0;
		foreach ( $remaining as $record ) {
			$size = strlen( (string) wp_json_encode( $record ) ) + 1;
			if ( $used + $size > $room ) {
				break;
			}
			$taken[] = $record;
			$used   += $size;
		}
		if ( array() === $taken && array() !== $remaining ) {
			return new \WP_Error( self::CODE_RECORD_TOO_LARGE, __( 'A single record is too large to return.', 'sw-gallery' ), array( 'status' => 413 ) );
		}
		$total_pages = (int) ceil( $total / max( 1, $per_page ) );
		$next        = null;
		if ( count( $taken ) < count( $remaining ) ) {
			$next = array(
				'page'   => $page,
				'offset' => $offset + count( $taken ),
			);
		} elseif ( $page < $total_pages ) {
			$next = array(
				'page'   => $page + 1,
				'offset' => 0,
			);
		}
		return $extra + array(
			$key         => $taken,
			'total'      => $total,
			'totalPages' => $total_pages,
			'next'       => $next,
		);
	}
}
