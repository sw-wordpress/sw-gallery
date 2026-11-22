<?php
/**
 * MCP ability `sw-gallery/list-galleries`.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Exception\AccessDeniedException;
use SWGallery\WordPress\Gallery\GalleryLister;
use SWGallery\WordPress\Mcp\AbilityDefinition;
use SWGallery\WordPress\Mcp\GallerySchema;
use SWGallery\WordPress\Mcp\Paging;
use SWGallery\WordPress\Mcp\TierPolicy;

final class ListGalleriesAbility implements AbilityDefinition {

	use ReadAbilityTrait;

	private const PER_PAGE = 20;

	/**
	 * @param \Closure $lister Lazy provider of the {@see GalleryLister}.
	 * @phpstan-param \Closure():GalleryLister $lister
	 */
	public function __construct( private readonly \Closure $lister ) {
	}

	public function name(): string {
		return 'list-galleries';
	}

	public function tier(): string {
		return TierPolicy::READ;
	}

	public function label(): string {
		return __( 'List galleries', 'sw-gallery' );
	}

	public function description(): string {
		return 'Lists galleries the user may view, newest first, with revision and item count. Users who may not see everybody\'s galleries only get their own. Follow the next field to continue.';
	}

	public function inputSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'search' => array(
					'description' => 'Search in title and description.',
					'type'        => 'string',
					'maxLength'   => 200,
				),
				'status' => array(
					'description' => 'Statuses to list (default: all except trash). trash needs the right to delete galleries.',
					'type'        => 'array',
					'maxItems'    => count( GalleryLister::LIST_STATUSES ),
					'items'       => array(
						'type' => 'string',
						'enum' => GalleryLister::LIST_STATUSES,
					),
				),
				'owner'  => array(
					'description' => 'Only galleries of this user id (ignored for users who may only see their own).',
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => GallerySchema::ID_MAX,
				),
			) + Paging::inputProperties( self::PER_PAGE ),
		);
	}

	public function outputSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'items' => array(
					'type'  => 'array',
					'items' => GallerySchema::output(),
				),
			) + Paging::outputProperties(),
		);
	}

	public function permission( array $input ): true|\WP_Error {
		if ( ! current_user_can( 'edit_posts' ) ) {
			throw new AccessDeniedException();
		}
		if ( in_array( GalleryStatus::Trash, GalleryLister::parse_statuses( $input['status'] ?? array() ), true ) && ! current_user_can( 'delete_posts' ) ) {
			throw new AccessDeniedException();
		}
		return true;
	}

	public function execute( array $input ): array|\WP_Error {
		$owner = self::int( $input, 'owner' );
		$found = ( $this->lister )()->list(
			$this->actor(),
			self::text( $input, 'search' ),
			GalleryLister::parse_statuses( $input['status'] ?? array() ),
			$owner > 0 ? $owner : null,
			self::int( $input, 'page', 1 ),
			self::int( $input, 'per_page', self::PER_PAGE )
		);
		return self::paged( $input, 'items', array_map( array( GallerySchema::class, 'project' ), $found['items'] ), $found['total'], self::PER_PAGE );
	}
}
