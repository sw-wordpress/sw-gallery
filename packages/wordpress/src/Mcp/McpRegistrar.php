<?php
/**
 * Registers the MCP abilities when the Abilities API and the mcp-adapter are present.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Mcp;

use SWGallery\WordPress\Bootstrap\RegistrarInterface;
use SWGallery\WordPress\Gallery\GalleryEditor;
use SWGallery\WordPress\Gallery\ItemEditor;
use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\Mcp\Ability\AssignItemsAbility;
use SWGallery\WordPress\Mcp\Ability\CreateGalleryAbility;
use SWGallery\WordPress\Mcp\Ability\DeleteGalleryAbility;
use SWGallery\WordPress\Mcp\Ability\DuplicateGalleryAbility;
use SWGallery\WordPress\Mcp\Ability\GetGalleryAbility;
use SWGallery\WordPress\Mcp\Ability\GetSettingsAbility;
use SWGallery\WordPress\Mcp\Ability\GetStatusAbility;
use SWGallery\WordPress\Mcp\Ability\ListGalleriesAbility;
use SWGallery\WordPress\Mcp\Ability\ListItemsAbility;
use SWGallery\WordPress\Mcp\Ability\ListLayoutsAbility;
use SWGallery\WordPress\Mcp\Ability\ListMediaAbility;
use SWGallery\WordPress\Mcp\Ability\RemoveItemsAbility;
use SWGallery\WordPress\Mcp\Ability\RenderGalleryAbility;
use SWGallery\WordPress\Mcp\Ability\ReorderItemsAbility;
use SWGallery\WordPress\Mcp\Ability\RestoreGalleryAbility;
use SWGallery\WordPress\Mcp\Ability\TrashGalleryAbility;
use SWGallery\WordPress\Mcp\Ability\UpdateGalleryAbility;
use SWGallery\WordPress\Mcp\Ability\UpdateItemAbility;
use SWGallery\WordPress\Mcp\Ability\UpdateSettingsAbility;
use SWGallery\WordPress\Rest\ErrorMapper;

/**
 * Only the two Abilities API actions are hooked (and only when the API exists); everything else is decided inside
 * by {@see McpAvailability}. No category, ability, option or UI exists without the adapter (docs/mcp-harness.md §2).
 */
final class McpRegistrar implements RegistrarInterface {

	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	public function register_category(): void {
		if ( ! McpAvailability::isAvailable() ) {
			return;
		}
		wp_register_ability_category(
			AbilityFactory::CATEGORY,
			array(
				'label'       => __( 'SW Gallery', 'sw-gallery' ),
				'description' => 'Create, read and edit galleries of the SW Gallery plugin.',
			)
		);
	}

	public function register_abilities(): void {
		if ( ! McpAvailability::isAvailable() ) {
			return;
		}
		$factory = new AbilityFactory( new ErrorMapper( Services::logger() ), new McpAuditLog() );
		foreach ( $this->definitions() as $definition ) {
			$factory->register( $definition );
		}
	}

	/** @return list<AbilityDefinition> */
	private function definitions(): array {
		$editor = static fn(): GalleryEditor => Services::gallery_editor();
		$items  = static fn(): ItemEditor => Services::item_editor();
		return array(
			new AssignItemsAbility( $items, $editor ),
			new CreateGalleryAbility( $editor ),
			new DeleteGalleryAbility( $editor ),
			new DuplicateGalleryAbility( $editor ),
			new GetGalleryAbility( $editor ),
			new GetSettingsAbility( Services::settings( ... ) ),
			new GetStatusAbility( Services::status_report( ... ) ),
			new ListGalleriesAbility( static fn() => Services::gallery_lister() ),
			new ListItemsAbility( static fn() => Services::item_reader() ),
			new ListLayoutsAbility( static fn() => Services::layouts() ),
			new ListMediaAbility( static fn() => Services::media_lister() ),
			new RemoveItemsAbility( $items, $editor ),
			new RenderGalleryAbility( $editor, Services::preview_renderer( ... ) ),
			new ReorderItemsAbility( $items, $editor ),
			new RestoreGalleryAbility( $editor ),
			new TrashGalleryAbility( $editor ),
			new UpdateGalleryAbility( $editor ),
			new UpdateItemAbility( $items, $editor ),
			new UpdateSettingsAbility( Services::settings( ... ) ),
		);
	}
}
