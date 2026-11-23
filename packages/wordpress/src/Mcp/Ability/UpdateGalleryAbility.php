<?php
/**
 * MCP ability `sw-gallery/update-gallery`.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\Core\Exception\AccessDeniedException;
use SWGallery\WordPress\Gallery\GalleryEditor;
use SWGallery\WordPress\Mcp\AbilityDefinition;
use SWGallery\WordPress\Mcp\GallerySchema;
use SWGallery\WordPress\Rest\AbstractController;
use SWGallery\WordPress\Rest\ErrorMapper;

final class UpdateGalleryAbility implements AbilityDefinition {

	use WriteAbilityTrait;

	/**
	 * @param \Closure $editor Lazy provider: registering an ability must not build the services (and so must not
	 *                         announce the layout registry early).
	 * @phpstan-param \Closure():GalleryEditor $editor
	 */
	public function __construct( private readonly \Closure $editor ) {
	}

	public function name(): string {
		return 'update-gallery';
	}

	public function label(): string {
		return __( 'Update gallery', 'sw-gallery' );
	}

	public function description(): string {
		return 'Partially updates a gallery the user may edit. Send only the fields to change plus expectedRevision from get-gallery. settings replaces the whole layout settings map. Returns the updated gallery with its new revision. The owner can not be changed.';
	}

	public function inputSchema(): array {
		return GallerySchema::revisionInput( GallerySchema::writableFields() );
	}

	public function outputSchema(): array {
		return GallerySchema::output();
	}

	public function permission( array $input ): true|\WP_Error {
		if ( ! current_user_can( 'edit_posts' ) ) {
			throw new AccessDeniedException();
		}
		( $this->editor )()->editable( $this->actor(), self::int( $input, 'id' ) );
		return true;
	}

	public function execute( array $input ): array|\WP_Error {
		$fields = GallerySchema::updateFields( $input );
		if ( isset( $fields['settings'] ) && is_array( $fields['settings'] ) && ! AbstractController::settings_within_limits( $fields['settings'] ) ) {
			return ErrorMapper::bad_request(
				'rest_invalid_param',
				/* translators: %s: argument name. */
				sprintf( __( '%s is too large or nested too deeply.', 'sw-gallery' ), 'settings' )
			);
		}
		$result = ( $this->editor )()->update( $this->actor(), self::int( $input, 'id' ), self::int( $input, 'expectedRevision' ), $fields );
		return $result instanceof \WP_Error ? $result : GallerySchema::project( $result );
	}
}
