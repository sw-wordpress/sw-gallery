<?php
/**
 * Bounded uninstall, multisite handling and schema creation for new sites.
 *
 * @package sw-gallery
 */

use SWGallery\WordPress\Database\Migrator;
use SWGallery\WordPress\Database\Schema;
use SWGallery\WordPress\Database\SchemaRegistrar;
use SWGallery\WordPress\Infrastructure\WpLogger;
use SWGallery\WordPress\Lifecycle;
use SWGallery\WordPress\PostTypes;
use SWGallery\WordPress\Rendering\MediaEpoch;
use SWGallery\WordPress\Rendering\RenderCache;
use SWGallery\WordPress\Settings\PluginSettings;
use SWGallery\WordPress\Repository\WpdbGalleryItemRepository;

class UninstallTest extends SwgTestCase {

	/** @var list<int> */
	private array $blogs = array();

	public function tear_down(): void {
		foreach ( $this->blogs as $blog ) {
			wp_delete_site( $blog );
		}
		$this->blogs = array();
		delete_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL );
		parent::tear_down();
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB
	}

	private function create_galleries( int $count ): array {
		$ids = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_type'   => PostTypes::GALLERY,
					'post_status' => 0 === $i % 2 ? 'publish' : 'draft',
				)
			);
		}
		return $ids;
	}

	public function test_uninstall_deletes_more_galleries_than_one_batch_and_cleans_plugin_options(): void {
		global $wpdb;
		$ids        = $this->create_galleries( Lifecycle::BATCH_SIZE + 5 );
		$attachment = self::factory()->attachment->create_object( 'a.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		( new WpdbGalleryItemRepository() )->append( $ids[0], $attachment );
		update_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL, '1' );
		update_option( WpLogger::OPTION, array( 'x' ), false );
		add_option( Migrator::LOCK_OPTION, time() + 60, '', false );
		update_option( PluginSettings::OPTION, '{"schemaVersion":1,"itemsPerPage":60}', false );
		update_option( MediaEpoch::OPTION, 'abc123', false );
		set_transient( 'swg_test_transient', 'v', 600 );
		set_transient( 'other_transient', 'keep', 600 );
		$this->use_real_ddl();

		Lifecycle::uninstall();

		foreach ( $ids as $id ) {
			$this->assertNull( get_post( $id ), 'Every gallery of every status is deleted.' );
		}
		$this->assertFalse( $this->table_exists( Schema::items_table() ) );
		foreach ( array( Schema::OPTION, WpLogger::OPTION, Migrator::LOCK_OPTION, PluginSettings::OPTION, MediaEpoch::OPTION, Lifecycle::OPTION_DELETE_ON_UNINSTALL ) as $option ) {
			$this->assertFalse( get_option( $option ), $option );
		}
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%swg\\_%'" ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 'keep', get_transient( 'other_transient' ) );
		$this->assertInstanceOf( WP_Post::class, get_post( $attachment ), 'Attachments are never deleted.' );
		delete_transient( 'other_transient' );
	}

	public function test_opted_in_uninstall_clears_external_render_transients(): void {
		update_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL, '1' );
		$this->use_real_ddl();
		$previous = (bool) wp_using_ext_object_cache();
		$slot     = RenderCache::TRANSIENT_PREFIX . 'ff';
		wp_using_ext_object_cache( true );
		try {
			set_transient( $slot, 'render fragment', RenderCache::TTL );
			set_transient( 'other_render', 'keep', RenderCache::TTL );

			Lifecycle::uninstall_site();

			$this->assertFalse( get_transient( $slot ) );
			$this->assertSame( 'keep', get_transient( 'other_render' ) );
		} finally {
			delete_transient( $slot );
			delete_transient( 'other_render' );
			wp_using_ext_object_cache( $previous );
		}
	}

	public function test_uninstall_stops_when_a_gallery_cannot_be_deleted(): void {
		$ids = $this->create_galleries( 2 );
		update_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL, '1' );
		add_filter( 'pre_delete_post', '__return_false' );
		$this->use_real_ddl();

		Lifecycle::uninstall();

		remove_filter( 'pre_delete_post', '__return_false' );
		$this->assertInstanceOf( WP_Post::class, get_post( $ids[0] ), 'No progress ends the loop instead of spinning.' );
	}

	public function test_default_uninstall_keeps_options_transients_and_the_ring_buffer(): void {
		$ids = $this->create_galleries( 1 );
		update_option( WpLogger::OPTION, array( 'x' ), false );
		set_transient( 'swg_test_transient', 'v', 600 );

		Lifecycle::uninstall();

		$this->assertInstanceOf( WP_Post::class, get_post( $ids[0] ) );
		$this->assertSame( array( 'x' ), get_option( WpLogger::OPTION ) );
		$this->assertSame( 'v', get_transient( 'swg_test_transient' ) );
		$this->assertTrue( $this->items_table_exists() );
	}

	public function test_network_activation_creates_the_table_of_every_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		global $wpdb;
		$this->use_real_ddl();
		$blog          = self::factory()->blog->create();
		$this->blogs[] = $blog;
		$blog_table    = $wpdb->get_blog_prefix( $blog ) . 'swg_gallery_items';
		$wpdb->query( "DROP TABLE IF EXISTS `{$blog_table}`" ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DROP TABLE IF EXISTS `' . Schema::items_table() . '`' ); // phpcs:ignore WordPress.DB

		Lifecycle::activate( true );

		$this->assertTrue( $this->table_exists( $blog_table ), 'Own table per site prefix.' );
		$this->assertTrue( $this->items_table_exists() );
		$this->assertNotSame( $blog_table, Schema::items_table() );
		$this->assertSame( get_current_blog_id(), (int) $wpdb->blogid, 'The site context is restored.' );
	}

	public function test_a_single_site_activation_on_multisite_only_touches_the_current_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		global $wpdb;
		$this->use_real_ddl();
		$blog          = self::factory()->blog->create();
		$this->blogs[] = $blog;
		$blog_table    = $wpdb->get_blog_prefix( $blog ) . 'swg_gallery_items';
		$wpdb->query( "DROP TABLE IF EXISTS `{$blog_table}`" ); // phpcs:ignore WordPress.DB

		Lifecycle::activate( false );

		$this->assertFalse( $this->table_exists( $blog_table ) );
	}

	public function test_new_sites_get_their_table_when_the_plugin_is_network_active(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		global $wpdb;
		$this->use_real_ddl();
		$registrar = new SchemaRegistrar();
		$registrar->register();
		$this->assertNotFalse( has_action( 'wp_initialize_site', array( $registrar, 'initialize_site' ) ) );
		$blog          = self::factory()->blog->create();
		$this->blogs[] = $blog;
		$blog_table    = $wpdb->get_blog_prefix( $blog ) . 'swg_gallery_items';
		$wpdb->query( "DROP TABLE IF EXISTS `{$blog_table}`" ); // phpcs:ignore WordPress.DB
		$site = get_site( $blog );

		// Not network active: nothing happens.
		$registrar->initialize_site( $site );
		$this->assertFalse( $this->table_exists( $blog_table ) );

		// Network active.
		update_site_option( 'active_sitewide_plugins', array( plugin_basename( SWGALLERY_FILE ) => time() ) );
		$registrar->initialize_site( $site );
		delete_site_option( 'active_sitewide_plugins' );

		$this->assertTrue( $this->table_exists( $blog_table ) );
		$this->assertSame( get_current_blog_id(), (int) $wpdb->blogid );
	}

	public function test_deleting_a_site_drops_its_item_table(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		global $wpdb;
		$this->use_real_ddl();
		( new SchemaRegistrar() )->register();
		$blog       = self::factory()->blog->create();
		$blog_table = $wpdb->get_blog_prefix( $blog ) . 'swg_gallery_items';
		switch_to_blog( $blog );
		Schema::migrate();
		restore_current_blog();
		$this->assertTrue( $this->table_exists( $blog_table ) );

		wp_delete_site( $blog );

		$this->assertFalse( $this->table_exists( $blog_table ) );
	}

	public function test_multisite_uninstall_honours_the_opt_in_of_each_site_and_keeps_attachments(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		global $wpdb;
		$this->use_real_ddl();
		$opted_in      = self::factory()->blog->create();
		$preserved     = self::factory()->blog->create();
		$this->blogs[] = $opted_in;
		$this->blogs[] = $preserved;
		$attachments   = array();
		$galleries     = array();
		foreach ( array( $opted_in, $preserved ) as $blog ) {
			switch_to_blog( $blog );
			Schema::migrate();
			$galleries[ $blog ]   = self::factory()->post->create( array( 'post_type' => PostTypes::GALLERY ) );
			$attachments[ $blog ] = self::factory()->attachment->create_object( 'm.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
			( new WpdbGalleryItemRepository() )->append( $galleries[ $blog ], $attachments[ $blog ] );
			if ( $blog === $opted_in ) {
				update_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL, '1' );
			}
			restore_current_blog();
		}

		Lifecycle::uninstall();

		switch_to_blog( $opted_in );
		$this->assertNull( get_post( $galleries[ $opted_in ] ) );
		$this->assertFalse( $this->table_exists( Schema::items_table() ) );
		$this->assertInstanceOf( WP_Post::class, get_post( $attachments[ $opted_in ] ), 'Attachments survive.' );
		restore_current_blog();

		switch_to_blog( $preserved );
		$this->assertInstanceOf( WP_Post::class, get_post( $galleries[ $preserved ] ) );
		$this->assertTrue( $this->table_exists( Schema::items_table() ) );
		restore_current_blog();
		$this->assertSame( get_current_blog_id(), (int) $wpdb->blogid );
	}
}
