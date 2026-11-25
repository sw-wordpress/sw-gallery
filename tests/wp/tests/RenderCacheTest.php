<?php
/**
 * Fragment cache: hits, key dimensions and the cases that must never be cached.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Core\Application\Render\RenderContext;
use SWGallery\Core\Application\Render\RenderResult;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Access\RenderAccess;
use SWGallery\Tests\Support\RenderFixtures;
use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\PostTypes;
use SWGallery\WordPress\Rendering\RenderCache;
use SWGallery\WordPress\Settings\PluginSettings;

class RenderCacheTest extends SwgTestCase {

	use RenderFixtures;

	private int $author;
	private int $admin;

	public function set_up(): void {
		parent::set_up();
		$this->purgeGalleries();
		Services::reset();
		$this->author = $this->makeUser( 'author' );
		$this->admin  = $this->makeUser( 'administrator' );
		$this->lockPassword();
		wp_cache_flush();
		foreach ( $this->cache_entries() as $name ) {
			delete_transient( $name );
		}
	}

	public function tear_down(): void {
		delete_option( PluginSettings::OPTION );
		wp_set_current_user( 0 );
		$this->lockPassword();
		$this->purgeGalleries();
		Services::reset();
		parent::tear_down();
	}

	private function gallery( string $status = 'publish', string $password = '', int $count = 3 ): int {
		$gallery = $this->makeGalleryPost( $this->author, $status, $password );
		$ids     = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$ids[] = $this->makeImage( "Image $i", 1600, 1200, array(), null, "Alt $i" );
		}
		$this->addItems( $gallery, $ids );
		return $gallery;
	}

	/**
	 * @param array<string, mixed> $options Render options.
	 */
	private function render( int $gallery, int $user = 0, EntryPoint $entry = EntryPoint::Shortcode, array $options = array() ): RenderResult {
		wp_set_current_user( $user );
		Services::reset();
		return Services::gallery_renderer()->render( $gallery, $entry, $options );
	}

	/** Number of queries a render needs, as a proxy for "served from cache". */
	private function queries_of( callable $render ): int {
		global $wpdb;
		$before = $wpdb->num_queries;
		$render();
		return $wpdb->num_queries - $before;
	}

	private function key( int $gallery, EntryPoint $entry = EntryPoint::Shortcode, int $page = 1, int $per_page = 24, array $overrides = array() ): string {
		Services::reset();
		$context = new RenderContext( $gallery, $entry, new Actor( 0 ), 'swg-1-1', $page, $per_page, null, $overrides );
		return Services::render_cache()->key( $context, $page );
	}

	public function test_a_second_render_is_a_hit_with_identical_html(): void {
		$gallery = $this->gallery();
		$fresh   = $this->render( $gallery );
		$warm    = $this->queries_of( fn() => $this->render( $gallery ) );
		$hit     = $this->render( $gallery );

		$this->assertSame( $fresh->html, $hit->html );
		$this->assertSame( $fresh->assets, $hit->assets );
		$this->assertSame( array( $fresh->page, $fresh->totalPages, $fresh->totalItems, $fresh->renderedItems, $fresh->layoutId ), array( $hit->page, $hit->totalPages, $hit->totalItems, $hit->renderedItems, $hit->layoutId ) );
		$this->assertSame( RenderAccess::Render, $hit->access );
		$this->assertStringContainsString( '<img', $hit->html );

		delete_transient( $this->transient_name( $this->key( $gallery ) ) );
		wp_cache_flush();
		$cold = $this->queries_of( fn() => $this->render( $gallery ) );
		$this->assertLessThan( $cold, $warm, 'a hit needs fewer queries than a cold render' );
	}

	public function test_a_hit_equals_a_fresh_render_for_logged_in_users_who_cannot_edit(): void {
		$gallery    = $this->gallery();
		$subscriber = $this->makeUser( 'subscriber' );

		$visitor = $this->render( $gallery );
		$user    = $this->render( $gallery, $subscriber );

		$this->assertSame( $visitor->html, $user->html );
		$this->assertNotFalse( get_transient( $this->transient_name( $this->key( $gallery ) ) ) );
	}

	public function test_instance_ids_stay_unique_per_request_on_cache_hits(): void {
		$gallery = $this->gallery();
		$this->render( $gallery );

		Services::reset();
		$renderer = Services::gallery_renderer();
		$first    = $renderer->render( $gallery, EntryPoint::Shortcode );
		$second   = $renderer->render( $gallery, EntryPoint::Shortcode );

		$this->assertStringContainsString( 'data-swg-instance="swg-' . $gallery . '-1"', $first->html );
		$this->assertStringContainsString( 'data-swg-instance="swg-' . $gallery . '-2"', $second->html );
		$this->assertStringNotContainsString( 'swg-instance-placeholder', $first->html . $second->html );
	}

	public function test_the_first_embed_priority_hint_is_part_of_the_key_not_leaked_to_later_embeds(): void {
		$gallery = $this->gallery();

		Services::reset();
		$renderer = Services::gallery_renderer();
		$first    = $renderer->render( $gallery, EntryPoint::Shortcode );
		$second   = $renderer->render( $gallery, EntryPoint::Shortcode );
		$this->assertStringContainsString( 'fetchpriority="high"', $first->html );
		$this->assertStringNotContainsString( 'fetchpriority="high"', $second->html );

		// New request: the cached entry of the "first embed" variant must give the same result again.
		Services::reset();
		$renderer = Services::gallery_renderer();
		$again    = $renderer->render( $gallery, EntryPoint::Shortcode );
		$this->assertSame( $first->html, $again->html );
	}

	public function test_the_key_changes_with_revision_settings_locale_page_entry_and_overrides(): void {
		$gallery = $this->gallery();
		$base    = $this->key( $gallery );

		$this->assertSame( $base, $this->key( $gallery ), 'stable' );
		$this->assertNotSame( $base, $this->key( $gallery, EntryPoint::Feed ), 'entry point' );
		$this->assertNotSame( $base, $this->key( $gallery, EntryPoint::Shortcode, 2 ), 'page' );
		$this->assertNotSame( $base, $this->key( $gallery, EntryPoint::Shortcode, 1, 10 ), 'page size' );
		$this->assertNotSame( $this->key( $gallery, EntryPoint::Shortcode, 1, 24, array( 'gap' => 4 ) ), $this->key( $gallery, EntryPoint::Shortcode, 1, 24, array( 'gap' => 5 ) ), 'overrides' );
		$this->assertSame( $this->key( $gallery, EntryPoint::Shortcode, 1, 24, array( 'a' => 1, 'b' => 2 ) ), $this->key( $gallery, EntryPoint::Shortcode, 1, 24, array( 'b' => 2, 'a' => 1 ) ), 'override order is irrelevant' );

		update_option( PluginSettings::OPTION, (string) wp_json_encode( array( 'itemsPerPage' => 7, 'schemaVersion' => 1 ) ) );
		$settings = $this->key( $gallery );
		$this->assertNotSame( $base, $settings, 'plugin settings' );

		add_filter( 'locale', static fn(): string => 'de_DE' );
		$locale = $this->key( $gallery );
		remove_all_filters( 'locale' );
		$this->assertNotSame( $settings, $locale, 'locale' );

		global $wp_locale;
		$rtl = $wp_locale->text_direction;
		$wp_locale->text_direction = 'rtl';
		$direction                 = $this->key( $gallery );
		$wp_locale->text_direction = $rtl;
		$this->assertNotSame( $settings, $direction, 'text direction' );

		Services::revisions()->compareAndIncrement( $gallery, Services::revisions()->current( $gallery ) );
		$this->assertNotSame( $settings, $this->key( $gallery ), 'gallery revision' );

		$before = $this->key( $gallery );
		Services::media_epoch()->bump();
		$this->assertNotSame( $before, $this->key( $gallery ), 'media epoch' );
	}

	public function test_page_size_precedence_is_embed_then_gallery_then_site(): void {
		$gallery = $this->gallery( 'publish', '', 5 );
		update_option( PluginSettings::OPTION, (string) wp_json_encode( array( 'itemsPerPage' => 4, 'schemaVersion' => 1 ) ) );
		$this->assertSame( 4, $this->render( $gallery )->renderedItems, 'site setting' );

		update_post_meta( $gallery, PostTypes::META_PER_PAGE, 2 );
		$this->assertSame( 2, $this->render( $gallery )->renderedItems, 'gallery setting' );

		$this->assertSame( 3, $this->render( $gallery, 0, EntryPoint::Shortcode, array( 'per_page' => 3 ) )->renderedItems, 'embed option' );
	}

	public function test_a_changed_revision_makes_the_next_render_fresh(): void {
		$gallery = $this->gallery( 'publish', '', 2 );
		$this->assertSame( 2, $this->render( $gallery )->renderedItems );

		Services::item_service()->assign( new Actor( $this->admin ), $gallery, array( $this->makeImage( 'Extra', 1600, 1200, array(), null, 'Extra' ) ), Services::revisions()->current( $gallery ) );

		$this->assertSame( 3, $this->render( $gallery )->renderedItems );
	}

	public function test_non_public_output_is_never_cached(): void {
		$cases = array(
			'draft'    => array( $this->gallery( 'draft' ), $this->author ),
			'private'  => array( $this->gallery( 'private' ), $this->author ),
			'password' => array( $this->gallery( 'publish', 'secret' ), 0 ),
			'editor'   => array( $this->gallery(), $this->admin ),
		);
		foreach ( $cases as $label => list( $gallery, $user ) ) {
			$result = $this->render( $gallery, $user );
			$this->assertSame( array(), $this->cache_entries(), $label . ' left nothing in the cache' );
			$this->assertNotSame( '', $result->html, $label );
		}
	}

	public function test_a_password_unlocked_visitor_does_not_fill_the_cache_either(): void {
		$gallery = $this->gallery( 'publish', 'secret' );
		$this->unlockPassword( 'secret' );

		$result = $this->render( $gallery );

		$this->assertSame( RenderAccess::Render, $result->access );
		$this->assertStringContainsString( '<img', $result->html );
		$this->assertSame( array(), $this->cache_entries() );
	}

	public function test_previews_and_diagnostics_are_never_cached_but_feeds_are(): void {
		$gallery = $this->gallery();

		$this->render( $gallery, $this->admin, EntryPoint::Preview );
		$this->assertSame( array(), $this->cache_entries(), 'preview' );

		$empty = $this->makeGalleryPost( $this->author );
		$this->render( $empty, $this->admin );
		$this->assertSame( array(), $this->cache_entries(), 'editor with diagnostics' );

		$feed = $this->render( $gallery, 0, EntryPoint::Feed );
		$this->assertNotSame( '', $feed->html );
		$this->assertCount( 1, $this->cache_entries(), 'feed has its own entry' );
		$this->assertSame( $feed->html, $this->render( $gallery, 0, EntryPoint::Feed )->html );
	}

	public function test_a_draft_that_was_published_before_is_not_served_from_the_cache(): void {
		$gallery = $this->gallery();
		$this->assertStringContainsString( '<img', $this->render( $gallery )->html );

		wp_update_post( array( 'ID' => $gallery, 'post_status' => 'draft' ) );

		$this->assertSame( '', $this->render( $gallery )->html );
	}

	public function test_a_password_added_later_wins_over_the_cache(): void {
		$gallery = $this->gallery();
		$this->render( $gallery );

		wp_update_post( array( 'ID' => $gallery, 'post_password' => 'secret' ) );

		$result = $this->render( $gallery );
		$this->assertSame( RenderAccess::PasswordForm, $result->access );
		$this->assertStringNotContainsString( '<img', $result->html );
	}

	public function test_a_page_past_the_end_is_stored_under_the_last_page_only(): void {
		$gallery = $this->gallery( 'publish', '', 5 );

		$this->render( $gallery, 0, EntryPoint::Shortcode, array( 'per_page' => 2, 'page' => 40 ) );
		$this->render( $gallery, 0, EntryPoint::Shortcode, array( 'per_page' => 2, 'page' => 41 ) );

		$this->assertCount( 1, $this->cache_entries() );
		$this->assertNotFalse( get_transient( $this->transient_name( $this->key( $gallery, EntryPoint::Shortcode, 3, 2 ) ) ) );
	}

	public function test_database_cache_survives_a_request_and_expires(): void {
		$this->assertFalse( (bool) wp_using_ext_object_cache() );
		$gallery = $this->gallery();
		$fresh   = $this->render( $gallery );
		$key     = $this->key( $gallery );
		$name    = $this->transient_name( $key );

		wp_cache_flush();
		Services::reset();
		$cached = Services::render_cache()->lookup( $key, 'swg-' . $gallery . '-1' );
		$this->assertSame( $fresh->html, $cached?->html );
		$this->assertArrayNotHasKey( '_transient_' . $name, wp_load_alloptions() );
		$this->assertGreaterThanOrEqual( time() + RenderCache::TTL - 5, (int) get_option( '_transient_timeout_' . $name ) );

		update_option( '_transient_timeout_' . $name, time() - 1 );
		wp_cache_flush();
		$this->assertNull( Services::render_cache()->lookup( $key, 'swg-' . $gallery . '-1' ) );
		$this->assertSame( $fresh->html, $this->render( $gallery )->html );
		$this->assertNotFalse( get_transient( $name ) );
	}

	public function test_a_slot_collision_replaces_the_entry_without_serving_another_fragment(): void {
		$cache = Services::render_cache();
		$first = 'fixture-6';
		$other = 'fixture-25';
		$this->assertSame( $this->transient_name( $first ), $this->transient_name( $other ) );
		$cache->store( $first, new RenderResult( 'First', RenderAccess::Render ), 'swg-1-1' );
		$this->assertSame( 'First', $cache->lookup( $first, 'swg-1-1' )?->html );

		$cache->store( $other, new RenderResult( 'Other', RenderAccess::Render ), 'swg-1-1' );
		$this->assertNull( $cache->lookup( $first, 'swg-1-1' ) );
		$this->assertSame( 'Other', $cache->lookup( $other, 'swg-1-1' )?->html );
		$this->assertCount( 1, $this->cache_entries() );
	}

	public function test_oversized_fragments_are_not_stored(): void {
		$cache = Services::render_cache();
		$cache->store( 'raw', new RenderResult( str_repeat( 'x', 140 * 1024 ), RenderAccess::Render ), 'swg-1-1' );
		$cache->store( 'encoded', new RenderResult( str_repeat( '"', 80 * 1024 ), RenderAccess::Render ), 'swg-1-1' );
		$this->assertSame( array(), $this->cache_entries() );
	}

	public function test_a_corrupt_cache_entry_is_ignored(): void {
		$gallery = $this->gallery();
		$fresh   = $this->render( $gallery );
		$key     = $this->key( $gallery );
		$name    = $this->transient_name( $key );
		set_transient( $name, wp_json_encode( array( 'key' => $key, 'html' => 5, 'page' => 1, 'totalPages' => 1, 'totalItems' => 3, 'renderedItems' => 3 ) ), RenderCache::TTL );
		$this->assertSame( $fresh->html, $this->render( $gallery )->html );

		set_transient( $name, 'junk', RenderCache::TTL );
		$this->assertSame( $fresh->html, $this->render( $gallery )->html );
	}

	private function transient_name( string $key ): string {
		return RenderCache::TRANSIENT_PREFIX . substr( hash( 'sha256', $key ), 0, 2 );
	}

	/** @return list<string> Render transients stored in the database. */
	private function cache_entries(): array {
		global $wpdb;
		$prefix  = '_transient_' . RenderCache::TRANSIENT_PREFIX;
		$options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB
		return array_map( static fn( string $name ): string => substr( $name, strlen( '_transient_' ) ), $options );
	}
}
