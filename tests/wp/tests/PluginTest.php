<?php
/**
 * Plugin bootstrap, post type, translations and media lookup inside WordPress.
 *
 * @package sw-gallery
 */

use SWGallery\WordPress\Admin\AdminRegistrar;
use SWGallery\WordPress\Bootstrap\CoreRegistrar;
use SWGallery\WordPress\Bootstrap\Registrars;
use SWGallery\WordPress\Block\BlockRegistrar;
use SWGallery\WordPress\Cli\CliRegistrar;
use SWGallery\WordPress\Database\Schema;
use SWGallery\WordPress\Database\SchemaRegistrar;
use SWGallery\WordPress\Hooks\CacheInvalidationRegistrar;
use SWGallery\WordPress\Hooks\GalleryLifecycleRegistrar;
use SWGallery\WordPress\Hooks\ImportProvidersRegistrar;
use SWGallery\WordPress\Admin\MediaLibraryRegistrar;
use SWGallery\WordPress\Admin\PrivacyRegistrar;
use SWGallery\WordPress\Frontend\FrontendRegistrar;
use SWGallery\WordPress\Infrastructure\EventBridgeRegistrar;
use SWGallery\WordPress\Media\WpMediaRepository;
use SWGallery\WordPress\Plugin;
use SWGallery\WordPress\PostTypes;
use SWGallery\WordPress\Mcp\McpRegistrar;
use SWGallery\WordPress\Rest\RestRegistrar;
use SWGallery\WordPress\Shortcode\ShortcodeRegistrar;
use SWGallery\WordPress\Update\UpdateRegistrar;
use SWGallery\WordPress\Support\CoreMessages;

class PluginTest extends SwgTestCase {

	public function test_plugin_header_version_matches_the_constant(): void {
		$data = get_file_data( SWGALLERY_FILE, array( 'Version' => 'Version' ) );
		$this->assertSame( SWGALLERY_VERSION, $data['Version'] );
	}

	public function test_boot_registers_the_hooks_of_every_registrar(): void {
		$this->assertSame( array( AdminRegistrar::class, BlockRegistrar::class, CacheInvalidationRegistrar::class, CliRegistrar::class, CoreRegistrar::class, EventBridgeRegistrar::class, FrontendRegistrar::class, GalleryLifecycleRegistrar::class, ImportProvidersRegistrar::class, McpRegistrar::class, MediaLibraryRegistrar::class, PrivacyRegistrar::class, RestRegistrar::class, SchemaRegistrar::class, ShortcodeRegistrar::class, UpdateRegistrar::class ), array_map( static fn( $r ): string => $r::class, Registrars::all() ) );
		$this->assertSame( Plugin::instance(), Plugin::instance() );
		$this->assertNotFalse( has_action( 'init', array( PostTypes::class, 'register' ) ) );
		$this->assertNotFalse( has_action( 'plugins_loaded', array( Schema::class, 'maybe_migrate' ) ) );
	}

	public function test_the_gallery_post_type_is_private_and_registers_its_meta(): void {
		$type = get_post_type_object( PostTypes::GALLERY );

		$this->assertNotNull( $type );
		$this->assertFalse( $type->public );
		$this->assertFalse( $type->show_in_rest );
		$this->assertTrue( registered_meta_key_exists( 'post', PostTypes::META_REV, PostTypes::GALLERY ) );
		$this->assertSame( 'grid', get_post_meta( self::factory()->post->create( array( 'post_type' => PostTypes::GALLERY ) ), PostTypes::META_LAYOUT, true ) );
	}

	public function test_media_repository_returns_only_existing_image_attachments(): void {
		$image = self::factory()->attachment->create_object(
			'a.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			)
		);
		$pdf   = self::factory()->attachment->create_object(
			'a.pdf',
			0,
			array(
				'post_mime_type' => 'application/pdf',
				'post_type'      => 'attachment',
			)
		);
		$post  = self::factory()->post->create();
		$repo  = new WpMediaRepository();

		$this->assertSame( array(), $repo->existingImageIds( array() ) );
		$this->assertSame( array( $image ), $repo->existingImageIds( array( $pdf, $post, $image, 999999 ) ) );
	}

	public function test_core_messages_are_translated_from_the_catalog(): void {
		$this->assertSame( 'Unknown dynamic message', CoreMessages::translate( 'Unknown dynamic message' ) );
		$this->assertContains( 'The gallery was not found.', CoreMessages::catalog() );
	}
}
