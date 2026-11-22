<?php
/**
 * MCP ability `sw-gallery/list-media`.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\Core\Exception\AccessDeniedException;
use SWGallery\WordPress\Media\MediaLister;
use SWGallery\WordPress\Mcp\AbilityDefinition;
use SWGallery\WordPress\Mcp\GallerySchema;
use SWGallery\WordPress\Mcp\Paging;
use SWGallery\WordPress\Mcp\TierPolicy;

final class ListMediaAbility implements AbilityDefinition {

	use ReadAbilityTrait;

	private const PER_PAGE = 40;

	/** Fields of a media record that are returned (no URLs). */
	private const FIELDS = array( 'id', 'title', 'alt', 'caption', 'mimeType', 'width', 'height', 'uploadedAt', 'uploadedBy', 'usedInGalleries' );

	/**
	 * @param \Closure $lister Lazy provider of the {@see MediaLister}.
	 * @phpstan-param \Closure():MediaLister $lister
	 */
	public function __construct( private readonly \Closure $lister ) {
	}

	public function name(): string {
		return 'list-media';
	}

	public function tier(): string {
		return TierPolicy::READ;
	}

	public function label(): string {
		return __( 'List media', 'sw-gallery' );
	}

	public function description(): string {
		return 'Searches the images of the media library the user may use and reports in how many galleries each is used. Use the returned ids with assign-items or as a cover. Follow the next field to continue.';
	}

	public function inputSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'search'     => array(
					'description' => 'Search text (title, caption, description).',
					'type'        => 'string',
					'maxLength'   => 200,
				),
				'orderby'    => array(
					'description' => 'Sort field.',
					'type'        => 'string',
					'enum'        => array( 'date', 'title' ),
					'default'     => 'date',
				),
				'order'      => array(
					'description' => 'Sort direction.',
					'type'        => 'string',
					'enum'        => array( 'asc', 'desc' ),
					'default'     => 'desc',
				),
				'uploadedBy' => array(
					'description' => 'Only images uploaded by this user id (users without the right to see others\' uploads always get their own).',
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => GallerySchema::ID_MAX,
				),
			) + Paging::inputProperties( self::PER_PAGE ),
		);
	}

	public function outputSchema(): array {
		$int = array( 'type' => 'integer' );
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'items' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'id'              => $int,
							'title'           => array( 'type' => 'string' ),
							'alt'             => array( 'type' => 'string' ),
							'caption'         => array( 'type' => 'string' ),
							'mimeType'        => array( 'type' => 'string' ),
							'width'           => $int,
							'height'          => $int,
							'uploadedAt'      => array( 'type' => 'string' ),
							'uploadedBy'      => $int,
							'usedInGalleries' => $int,
						),
					),
				),
			) + Paging::outputProperties(),
		);
	}

	public function permission( array $input ): true|\WP_Error {
		if ( ! current_user_can( 'upload_files' ) ) {
			throw new AccessDeniedException();
		}
		return true;
	}

	public function execute( array $input ): array|\WP_Error {
		$found = ( $this->lister )()->list(
			$this->actor(),
			self::text( $input, 'search' ),
			self::int( $input, 'page', 1 ),
			self::int( $input, 'per_page', self::PER_PAGE ),
			'title' === ( $input['orderby'] ?? '' ) ? 'title' : 'date',
			'asc' === ( $input['order'] ?? '' ) ? 'asc' : 'desc',
			self::int( $input, 'uploadedBy' )
		);
		$items = array_map( static fn( array $item ): array => array_intersect_key( $item, array_flip( self::FIELDS ) ), $found['items'] );
		return self::paged( $input, 'items', $items, $found['total'], self::PER_PAGE );
	}
}
