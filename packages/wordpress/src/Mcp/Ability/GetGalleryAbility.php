<?php
/**
 * MCP ability `sw-gallery/get-gallery`.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Exception\AccessDeniedException;
use SWGallery\WordPress\Gallery\GalleryEditor;
use SWGallery\WordPress\Mcp\AbilityDefinition;
use SWGallery\WordPress\Mcp\GallerySchema;
use SWGallery\WordPress\Mcp\TierPolicy;

final class GetGalleryAbility implements AbilityDefinition {

	/**
	 * @param \Closure $editor Lazy provider: registering an ability must not build the services (and so must not
	 *                         announce the layout registry early).
	 * @phpstan-param \Closure():GalleryEditor $editor
	 */
	public function __construct( private readonly \Closure $editor ) {
	}

	public function name(): string {
		return 'get-gallery';
	}

	public function tier(): string {
		return TierPolicy::READ;
	}

	public function label(): string {
		return __( 'Get gallery', 'sw-gallery' );
	}

	public function description(): string {
		return 'Returns one gallery the user may view, with its settings and current revision. Call this before any write and pass the returned revision as expectedRevision.';
	}

	public function inputSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'id' ),
			'properties'           => array( 'id' => GallerySchema::id() ),
		);
	}

	public function outputSchema(): array {
		return GallerySchema::output();
	}

	public function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	public function permission( array $input ): true|\WP_Error {
		if ( ! current_user_can( 'edit_posts' ) ) {
			throw new AccessDeniedException();
		}
		( $this->editor )()->viewable( $this->actor(), self::id( $input ) );
		return true;
	}

	public function execute( array $input ): array {
		return GallerySchema::project( ( $this->editor )()->view( $this->actor(), self::id( $input ) ) );
	}

	private function actor(): Actor {
		return new Actor( max( 0, get_current_user_id() ) );
	}

	/** @param array<string, mixed> $input Validated input. */
	private static function id( array $input ): int {
		return is_numeric( $input['id'] ?? null ) ? (int) $input['id'] : 0;
	}
}
