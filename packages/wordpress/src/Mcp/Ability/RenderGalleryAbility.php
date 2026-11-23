<?php
/**
 * MCP ability `sw-gallery/render-gallery`.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Core\Application\Render\RenderDiagnostic;
use SWGallery\Core\Domain\Gallery\Gallery;
use SWGallery\Core\Exception\AccessDeniedException;
use SWGallery\WordPress\Gallery\GalleryEditor;
use SWGallery\WordPress\Mcp\AbilityDefinition;
use SWGallery\WordPress\Mcp\GallerySchema;
use SWGallery\WordPress\Rendering\GalleryRenderer;

final class RenderGalleryAbility implements AbilityDefinition {

	use ReadAbilityTrait;

	/**
	 * @param \Closure $editor   Lazy provider of the {@see GalleryEditor}.
	 * @param \Closure $renderer Lazy factory of the preview renderer for a gallery.
	 * @phpstan-param \Closure():GalleryEditor $editor
	 * @phpstan-param \Closure(Gallery):GalleryRenderer $renderer
	 */
	public function __construct( private readonly \Closure $editor, private readonly \Closure $renderer ) {
	}

	public function name(): string {
		return 'render-gallery';
	}

	public function label(): string {
		return __( 'Render gallery', 'sw-gallery' );
	}

	public function description(): string {
		return 'Renders the stored state of a gallery the user may edit through the normal renderer and reports only the outcome: layout id, page counts and diagnostic codes (for example missing layout or assets). The markup itself is not returned. Use it to verify a gallery renders without problems.';
	}

	public function inputSchema(): array {
		return GallerySchema::idInput();
	}

	public function outputSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'layoutId'    => array( 'type' => 'string' ),
				'page'        => array( 'type' => 'integer' ),
				'totalPages'  => array( 'type' => 'integer' ),
				'totalItems'  => array( 'type' => 'integer' ),
				'diagnostics' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
		);
	}

	public function permission( array $input ): true|\WP_Error {
		if ( ! current_user_can( 'edit_posts' ) ) {
			throw new AccessDeniedException();
		}
		( $this->editor )()->editable( $this->actor(), self::int( $input, 'id' ) );
		return true;
	}

	public function execute( array $input ): array {
		$gallery = ( $this->editor )()->editable( $this->actor(), self::int( $input, 'id' ) );
		$result  = ( $this->renderer )( $gallery )->render( $gallery->id, EntryPoint::Preview, array() );
		return array(
			'layoutId'    => $result->layoutId ?? $gallery->settings->layout,
			'page'        => $result->page,
			'totalPages'  => $result->totalPages,
			'totalItems'  => $result->totalItems,
			'diagnostics' => array_map( static fn( RenderDiagnostic $diagnostic ): string => $diagnostic->code, $result->diagnostics ),
		);
	}
}
