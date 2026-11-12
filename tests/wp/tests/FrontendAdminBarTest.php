<?php
/**
 * Admin bar "Edit gallery" entries: rendered galleries only, editable ones only, frontend only, at most five.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Application\Render\RenderAsset;
use SWGallery\Core\Application\Render\RenderResult;
use SWGallery\Core\Domain\Access\RenderAccess;
use SWGallery\Tests\Support\RenderFixtures;
use SWGallery\WordPress\Frontend\AdminBar;
use SWGallery\WordPress\Frontend\AssetCollector;
use SWGallery\WordPress\Frontend\FrontendRegistrar;
use SWGallery\WordPress\Infrastructure\Services;

class FrontendAdminBarTest extends SwgTestCase {

	use RenderFixtures;

	private AssetCollector $collector;
	private int $author;
	private int $other_author;
	private int $editor;

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$this->purgeGalleries();
		Services::reset();
		$this->collector    = new AssetCollector();
		$this->author       = $this->makeUser( 'author' );
		$this->other_author = $this->makeUser( 'author' );
		$this->editor       = $this->makeUser( 'editor' );
	}

	public function tear_down(): void {
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
		$this->purgeGalleries();
		Services::reset();
		parent::tear_down();
	}

	private function rendered( int $gallery ): void {
		$this->collector->record( $gallery, new RenderResult( '<div></div>', RenderAccess::Render, array(), array( RenderAsset::Lightbox ), 1, 1, 1, 1, 'grid' ) );
	}

	/**
	 * @return array<string, \stdClass>
	 */
	private function nodes( int $user ): array {
		wp_set_current_user( $user );
		Services::reset();
		$bar = new WP_Admin_Bar();
		( new AdminBar( $this->collector, Services::galleries(), Services::access() ) )->add_nodes( $bar );
		$found = array();
		foreach ( $bar->get_nodes() ?? array() as $node ) {
			$found[ $node->id ] = $node;
		}
		return $found;
	}

	public function test_an_editor_gets_one_node_per_rendered_gallery_with_a_deep_link(): void {
		$gallery = $this->makeGalleryPost( $this->author );
		$this->rendered( $gallery );
		$this->rendered( $gallery );

		$nodes = $this->nodes( $this->author );

		$this->assertSame( array( 'swg-edit-gallery-' . $gallery ), array_keys( $nodes ) );
		$node = $nodes[ 'swg-edit-gallery-' . $gallery ];
		$this->assertSame( 'Edit gallery', $node->title );
		$this->assertSame( admin_url( 'admin.php?page=swg-galleries#/galleries/' . $gallery ), $node->href );
	}

	public function test_only_galleries_the_user_may_edit_are_listed(): void {
		$own     = $this->makeGalleryPost( $this->author );
		$foreign = $this->makeGalleryPost( $this->other_author );
		$this->rendered( $own );
		$this->rendered( $foreign );

		$this->assertSame( array( 'swg-edit-gallery-' . $own ), array_keys( $this->nodes( $this->author ) ) );
		$this->assertCount( 2, $this->nodes( $this->editor ) );
		$this->assertSame( array(), $this->nodes( $this->makeUser( 'subscriber' ) ) );
		$this->assertSame( array(), $this->nodes( 0 ) );
	}

	public function test_galleries_that_were_not_rendered_are_not_listed(): void {
		$this->makeGalleryPost( $this->author );

		$this->assertSame( array(), $this->nodes( $this->author ) );
	}

	public function test_at_most_five_nodes_and_the_titles_are_escaped(): void {
		for ( $i = 0; $i < 7; $i++ ) {
			$id = $this->makeGalleryPost( $this->author );
			// Direct write: kses would strip the markup before it reaches the admin bar.
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_title' => '<img src=x onerror=alert(1)>Title ' . $i ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			clean_post_cache( $id );
			$this->rendered( $id );
		}

		$nodes = $this->nodes( $this->author );

		$this->assertCount( AdminBar::MAX_NODES, $nodes );
		foreach ( $nodes as $node ) {
			$this->assertStringStartsWith( 'Edit gallery: ', $node->title );
			$this->assertStringNotContainsString( '<img', $node->title );
			$this->assertStringNotContainsString( 'onerror', $node->title );
			$this->assertStringNotContainsString( '<', $node->title );
		}
	}

	public function test_special_characters_in_titles_are_html_escaped(): void {
		$one = $this->makeGalleryPost( $this->author );
		$two = $this->makeGalleryPost( $this->author );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_title' => 'Fish & "Chips"' ), array( 'ID' => $one ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $one );
		$this->rendered( $one );
		$this->rendered( $two );

		$this->assertSame( 'Edit gallery: Fish &amp; &quot;Chips&quot;', $this->nodes( $this->author )[ 'swg-edit-gallery-' . $one ]->title );
	}

	public function test_nothing_is_added_on_admin_screens(): void {
		$gallery = $this->makeGalleryPost( $this->author );
		$this->rendered( $gallery );
		set_current_screen( 'dashboard' );

		$this->assertSame( array(), $this->nodes( $this->author ) );
	}

	public function test_the_registrar_builds_the_nodes_from_the_shared_collector(): void {
		$gallery = $this->makeGalleryPost( $this->author );
		wp_set_current_user( $this->author );
		Services::reset();
		$this->rendered( $gallery );
		Services::asset_collector()->record( $gallery, new RenderResult( '<div></div>', RenderAccess::Render, array(), array( RenderAsset::Lightbox ) ) );

		$bar = new WP_Admin_Bar();
		( new FrontendRegistrar() )->admin_bar( $bar );

		$this->assertNotNull( $bar->get_node( 'swg-edit-gallery-' . $gallery ) );
	}
}
