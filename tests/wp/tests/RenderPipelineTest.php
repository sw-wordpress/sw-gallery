<?php
/**
 * Full-stack renders against the real database: access matrix, paging, sorting, escaping, instances, feed, fallback,
 * query budget and attachment immutability.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Core\Application\Render\RenderAsset;
use SWGallery\Core\Application\Render\RenderDiagnostic;
use SWGallery\Core\Domain\Access\RenderAccess;
use SWGallery\Core\Domain\Gallery\ItemOverrides;
use SWGallery\Tests\Support\RenderFixtures;
use SWGallery\WordPress\Infrastructure\Services;

class RenderPipelineTest extends SwgTestCase {

	use RenderFixtures;

	private int $author;
	private int $other_author;
	private int $editor;
	private int $admin;

	public function set_up(): void {
		parent::set_up();
		$this->purgeGalleries();
		Services::reset();
		$this->author       = $this->makeUser( 'author' );
		$this->other_author = $this->makeUser( 'author' );
		$this->editor       = $this->makeUser( 'editor' );
		$this->admin        = $this->makeUser( 'administrator' );
		$this->lockPassword();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->lockPassword();
		$this->purgeGalleries();
		Services::reset();
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $options Render options.
	 */
	private function render( int $gallery, int $user = 0, EntryPoint $entry = EntryPoint::Shortcode, array $options = array() ): \SWGallery\Core\Application\Render\RenderResult {
		wp_set_current_user( $user );
		Services::reset();
		return Services::gallery_renderer()->render( $gallery, $entry, $options );
	}

	/**
	 * @param int $count Images to add.
	 */
	private function gallery_with_images( int $count = 3, string $status = 'publish', string $password = '' ): int {
		$gallery = $this->makeGalleryPost( $this->author, $status, $password );
		$ids     = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$ids[] = $this->makeImage( "Image $i", 1600, 1200, array(), null, "Alt $i" );
		}
		$this->addItems( $gallery, $ids );
		return $gallery;
	}

	private function dom( string $html ): DOMXPath {
		$document = new DOMDocument();
		$document->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR );
		return new DOMXPath( $document );
	}

	/**
	 * @return iterable<string, array{string, string, int|string, string}>
	 */
	public static function access_matrix(): iterable {
		// status, password, user key, expected: render | password | diagnostic | nothing.
		foreach ( array( 'visitor', 'author', 'other', 'editor', 'admin' ) as $user ) {
			yield "publish $user" => array( 'publish', '', $user, 'render' );
			yield "publish password $user" => array( 'publish', 'secret', $user, 'password' );
			yield "draft $user" => array( 'draft', '', $user, in_array( $user, array( 'author', 'editor', 'admin' ), true ) ? 'diagnostic' : 'nothing' );
			yield "pending $user" => array( 'pending', '', $user, in_array( $user, array( 'author', 'editor', 'admin' ), true ) ? 'diagnostic' : 'nothing' );
			yield "trash $user" => array( 'trash', '', $user, in_array( $user, array( 'author', 'editor', 'admin' ), true ) ? 'diagnostic' : 'nothing' );
			yield "private $user" => array( 'private', '', $user, in_array( $user, array( 'author', 'editor', 'admin' ), true ) ? 'render' : 'nothing' );
		}
	}

	/**
	 * @dataProvider access_matrix
	 */
	public function test_the_access_matrix_is_identical_for_every_entry_point( string $status, string $password, string $user, string $expected ): void {
		$ids     = array(
			'visitor' => 0,
			'author'  => $this->author,
			'other'   => $this->other_author,
			'editor'  => $this->editor,
			'admin'   => $this->admin,
		);
		$gallery = $this->gallery_with_images( 2, $status, $password );

		foreach ( EntryPoint::cases() as $entry ) {
			if ( EntryPoint::Preview === $entry ) {
				continue; // The authorised preview has its own rule, see PreviewAccessTest.
			}
			$result = $this->render( $gallery, $ids[ $user ], $entry );
			$label  = "$status/$password/$user/{$entry->value}";
			switch ( $expected ) {
				case 'render':
					$this->assertSame( RenderAccess::Render, $result->access, $label );
					$this->assertStringContainsString( '<img', $result->html, $label );
					break;
				case 'password':
					$this->assertSame( RenderAccess::PasswordForm, $result->access, $label );
					$this->assertStringNotContainsString( '<img', $result->html, $label );
					if ( EntryPoint::Feed === $entry ) {
						$this->assertSame( '', $result->html, $label );
					} else {
						$this->assertStringContainsString( 'post_password', $result->html, $label );
					}
					break;
				case 'diagnostic':
					$this->assertSame( RenderAccess::Diagnostic, $result->access, $label );
					$this->assertStringNotContainsString( '<img', $result->html, $label );
					if ( EntryPoint::Feed === $entry ) {
						$this->assertSame( '', $result->html, $label );
					} else {
						$this->assertStringContainsString( 'swg-diagnostic', $result->html, $label );
					}
					break;
				default:
					$this->assertSame( RenderAccess::Nothing, $result->access, $label );
					$this->assertSame( '', $result->html, $label );
			}
		}
	}

	public function test_an_unlocked_password_renders_the_gallery_for_everyone(): void {
		$gallery = $this->gallery_with_images( 2, 'publish', 'secret' );
		$this->unlockPassword( 'secret' );

		foreach ( array( 0, $this->author, $this->other_author ) as $user ) {
			$result = $this->render( $gallery, $user );
			$this->assertSame( RenderAccess::Render, $result->access );
			$this->assertStringContainsString( '<img', $result->html );
		}
	}

	public function test_a_wrong_password_cookie_keeps_the_form(): void {
		$gallery = $this->gallery_with_images( 1, 'publish', 'secret' );
		$this->unlockPassword( 'not-the-password' );

		$this->assertSame( RenderAccess::PasswordForm, $this->render( $gallery )->access );
	}

	public function test_visitors_never_see_diagnostics_or_gallery_details(): void {
		$gallery = $this->gallery_with_images( 2, 'draft' );

		$result = $this->render( $gallery, 0 );

		$this->assertSame( '', $result->html );
		$this->assertSame( array(), $result->diagnostics );
		$this->assertSame( array(), $result->assets );
	}

	public function test_an_unknown_gallery_renders_nothing_for_visitors_and_a_note_for_authors(): void {
		$this->assertSame( '', $this->render( 999999 )->html );
		$this->assertSame( '', $this->render( 0 )->html );
		$this->assertStringContainsString( 'swg-diagnostic', $this->render( 999999, $this->author )->html );
	}

	public function test_a_published_gallery_emits_the_lightbox_markup_contract(): void {
		$gallery = $this->gallery_with_images( 3 );

		$result = $this->render( $gallery );
		$xp     = $this->dom( $result->html );

		$this->assertSame( array( RenderAsset::Lightbox ), $result->assets );
		$this->assertSame( 1, $xp->query( '//div[@data-swg-gallery][@data-swg-instance="swg-' . $gallery . '-1"]' )->length );
		$this->assertSame( 3, $xp->query( '//ul[@class="swg-items"]/li[@class="swg-item"]/a[@class="swg-link"]/img' )->length );
		$link = $xp->query( '//a[@class="swg-link"]' )->item( 0 );
		$this->assertMatchesRegularExpression( '#^https?://.+/photo-\d+\.jpg$#', $link->getAttribute( 'href' ) );
		$this->assertSame( '1600', $link->getAttribute( 'data-swg-full-width' ) );
		$this->assertSame( '1200', $link->getAttribute( 'data-swg-full-height' ) );
		$this->assertNotSame( '', $link->getAttribute( 'data-swg-full-srcset' ) );
		$img = $xp->query( '//a[@class="swg-link"]/img' )->item( 0 );
		$this->assertSame( 'Alt 1', $img->getAttribute( 'alt' ) );
		$this->assertNotSame( '', $img->getAttribute( 'width' ) );
		$this->assertNotSame( '', $img->getAttribute( 'height' ) );
		$this->assertNotSame( '', $img->getAttribute( 'srcset' ) );
		$this->assertNotSame( '', $img->getAttribute( 'sizes' ) );
		$labels = json_decode( $xp->query( '//div[@data-swg-gallery]' )->item( 0 )->getAttribute( 'data-swg-labels' ), true );
		$this->assertSame( array( 'dialog', 'close', 'previous', 'next', 'counter', 'error' ), array_keys( $labels ) );
	}

	public function test_the_first_row_is_eager_the_first_image_has_priority_and_the_rest_is_lazy(): void {
		$gallery = $this->gallery_with_images( 6 );

		$xp  = $this->dom( $this->render( $gallery )->html );
		$imgs = $xp->query( '//img' );

		$this->assertSame( 6, $imgs->length );
		$this->assertSame( 'eager', $imgs->item( 0 )->getAttribute( 'loading' ) );
		$this->assertSame( 'high', $imgs->item( 0 )->getAttribute( 'fetchpriority' ) );
		$this->assertSame( 'eager', $imgs->item( 2 )->getAttribute( 'loading' ), 'The default grid has three columns.' );
		$this->assertSame( '', $imgs->item( 2 )->getAttribute( 'fetchpriority' ) );
		$this->assertSame( 'lazy', $imgs->item( 3 )->getAttribute( 'loading' ) );
		$this->assertSame( 'lazy', $imgs->item( 5 )->getAttribute( 'loading' ) );
		foreach ( $imgs as $img ) {
			$this->assertSame( 'async', $img->getAttribute( 'decoding' ) );
		}
	}

	public function test_eager_loading_can_be_disabled_per_embed(): void {
		$gallery = $this->gallery_with_images( 4 );

		$imgs = $this->dom( $this->render( $gallery, 0, EntryPoint::Shortcode, array( 'eager' => false ) )->html )->query( '//img' );

		foreach ( $imgs as $img ) {
			$this->assertSame( 'lazy', $img->getAttribute( 'loading' ) );
			$this->assertFalse( $img->hasAttribute( 'fetchpriority' ) );
		}
	}

	public function test_only_the_first_embed_of_a_request_gets_the_priority_hint(): void {
		$first  = $this->gallery_with_images( 2 );
		$second = $this->gallery_with_images( 2 );
		wp_set_current_user( 0 );
		Services::reset();
		$renderer = Services::gallery_renderer();

		$a = $renderer->html( $first, EntryPoint::Block );
		$b = $renderer->html( $second, EntryPoint::Block );

		$this->assertSame( 1, substr_count( $a, 'fetchpriority="high"' ) );
		$this->assertSame( 0, substr_count( $b, 'fetchpriority' ) );
		$this->assertSame( 'eager', $this->dom( $b )->query( '//img' )->item( 0 )->getAttribute( 'loading' ) );
	}

	public function test_several_embeds_get_distinct_instance_ids(): void {
		$one = $this->gallery_with_images( 1 );
		$two = $this->gallery_with_images( 1 );
		wp_set_current_user( 0 );
		Services::reset();
		$renderer = Services::gallery_renderer();

		$html = $renderer->html( $one, EntryPoint::Shortcode ) . $renderer->html( $two, EntryPoint::Shortcode ) . $renderer->html( $one, EntryPoint::Shortcode );

		preg_match_all( '/data-swg-instance="([^"]+)"/', $html, $matches );
		$this->assertSame( array( "swg-$one-1", "swg-$two-1", "swg-$one-2" ), $matches[1] );
	}

	public function test_paging_bounds_and_clamping(): void {
		$gallery = $this->gallery_with_images( 5 );

		$second = $this->render( $gallery, 0, EntryPoint::Shortcode, array( 'page' => 2, 'per_page' => 2 ) );
		$past   = $this->render( $gallery, 0, EntryPoint::Shortcode, array( 'page' => 50, 'per_page' => 2 ) );
		$bad    = $this->render( $gallery, 0, EntryPoint::Shortcode, array( 'page' => 'x', 'per_page' => 99999 ) );

		$this->assertSame( 2, $second->renderedItems );
		$this->assertSame( array( 2, 3, 5 ), array( $second->page, $second->totalPages, $second->totalItems ) );
		$this->assertSame( 1, $past->renderedItems );
		$this->assertSame( 3, $past->page );
		$this->assertSame( 5, $bad->renderedItems, 'Garbage options fall back to the defaults.' );
		$this->assertSame( 1, $bad->page );
	}

	public function test_sort_modes_change_the_dom_order_but_manual_order_stays_stored(): void {
		$gallery = $this->makeGalleryPost( $this->author, 'publish', '', 'grid', 'title_desc' );
		$ids     = array();
		foreach ( array( 'Alpha', 'Beta', 'Gamma' ) as $title ) {
			$ids[ $title ] = $this->makeImage( $title, 800, 600, array(), null, $title );
		}
		$this->addItems( $gallery, array_values( $ids ) );

		$alts = array_map( static fn( $n ) => $n->getAttribute( 'alt' ), iterator_to_array( $this->dom( $this->render( $gallery )->html )->query( '//img' ) ) );

		$this->assertSame( array( 'Gamma', 'Beta', 'Alpha' ), $alts );
		$this->assertSame( array_values( $ids ), array_map( static fn( $i ) => $i->attachmentId, Services::items()->findPage( $gallery, 0, 10 ) ) );
	}

	public function test_missing_attachments_are_skipped_but_every_relation_is_kept(): void {
		$gallery = $this->makeGalleryPost( $this->author );
		$good    = $this->makeImage( 'Good', 800, 600, array(), null, 'Good' );
		$trashed = $this->makeImage( 'Trashed', 800, 600, array( 'post_status' => 'trash' ) );
		$this->addItems( $gallery, array( $trashed, $good, 987654 ) );

		$visitor = $this->render( $gallery );
		$author  = $this->render( $gallery, $this->author );

		$this->assertSame( 1, $visitor->renderedItems );
		$this->assertSame( 1, substr_count( $visitor->html, '<img' ) );
		$this->assertSame( 3, $this->itemRowCount( $gallery ) );
		$this->assertSame( array(), $visitor->diagnostics );
		$this->assertSame( array(), $author->diagnostics, 'The SQL join already excludes unusable attachments.' );
	}

	public function test_an_image_whose_file_data_is_broken_is_skipped_and_editors_are_told(): void {
		$gallery = $this->makeGalleryPost( $this->author );
		$good    = $this->makeImage( 'Good' );
		$broken  = $this->makeImage( 'Broken', 0, 0, array(), array() );
		$this->addItems( $gallery, array( $broken, $good ) );

		$visitor = $this->render( $gallery );
		$author  = $this->render( $gallery, $this->author );

		$this->assertSame( 1, $visitor->renderedItems );
		$this->assertStringNotContainsString( 'swg-diagnostic', $visitor->html );
		$this->assertSame( RenderDiagnostic::IMAGES_UNAVAILABLE, $author->diagnostics[0]->code );
		$this->assertStringContainsString( 'swg-diagnostic', $author->html );
		$this->assertSame( 2, $this->itemRowCount( $gallery ) );
	}

	public function test_an_empty_gallery_renders_nothing_for_visitors_and_a_note_for_editors(): void {
		$gallery = $this->makeGalleryPost( $this->author );

		$this->assertSame( '', $this->render( $gallery )->html );
		$this->assertStringContainsString( 'no images yet', $this->render( $gallery, $this->author )->html );
	}

	public function test_local_overrides_beat_attachment_texts_and_a_blank_override_stays_blank(): void {
		$gallery = $this->makeGalleryPost( $this->author );
		$a       = $this->makeImage( 'Global A', 800, 600, array( 'post_excerpt' => 'Global caption A' ), null, 'Global alt A' );
		$b       = $this->makeImage( 'Global B', 800, 600, array( 'post_excerpt' => 'Global caption B' ), null, 'Global alt B' );
		$c       = $this->makeImage( 'Global C', 800, 600, array( 'post_excerpt' => 'Global caption C' ), null, 'Global alt C' );
		$items   = $this->addItems( $gallery, array( $a, $b, $c ) );
		Services::items()->saveOverrides( $gallery, $items[0], new ItemOverrides( null, 'Local caption A', 'Local alt A' ) );
		Services::items()->saveOverrides( $gallery, $items[1], new ItemOverrides( null, '', '' ) );

		$xp = $this->dom( $this->render( $gallery )->html );

		$this->assertSame( 'Local alt A', $xp->query( '//li[1]//img' )->item( 0 )->getAttribute( 'alt' ) );
		$this->assertSame( 'Local caption A', $xp->query( '//li[1]/p[@class="swg-caption"]' )->item( 0 )->textContent );
		$this->assertSame( '', $xp->query( '//li[2]//img' )->item( 0 )->getAttribute( 'alt' ) );
		$this->assertSame( 0, $xp->query( '//li[2]/p[@class="swg-caption"]' )->length );
		$this->assertSame( 'Global alt C', $xp->query( '//li[3]//img' )->item( 0 )->getAttribute( 'alt' ) );
		$this->assertSame( 'Global caption C', $xp->query( '//li[3]/p[@class="swg-caption"]' )->item( 0 )->textContent );
	}

	public function test_rendering_never_mutates_attachments(): void {
		$gallery = $this->makeGalleryPost( $this->author );
		$images  = array();
		foreach ( array( 'A', 'B' ) as $title ) {
			$images[] = $this->makeImage( $title, 1600, 1200, array( 'post_excerpt' => "Caption $title" ), null, "Alt $title" );
		}
		$items = $this->addItems( $gallery, $images );
		Services::items()->saveOverrides( $gallery, $items[0], new ItemOverrides( 'Local', 'Local caption', '' ) );
		$snapshot = static function () use ( $images ): string {
			$state = array();
			foreach ( $images as $id ) {
				clean_post_cache( $id );
				wp_cache_delete( $id, 'post_meta' );
				$state[ $id ] = array( get_post( $id )->to_array(), get_post_meta( $id ) );
			}
			return serialize( $state );
		};
		$before = $snapshot();

		foreach ( EntryPoint::cases() as $entry ) {
			$this->render( $gallery, $this->author, $entry );
		}

		$this->assertSame( $before, $snapshot() );
	}

	public function test_hostile_text_is_escaped_for_its_context(): void {
		$gallery = $this->makeGalleryPost( $this->author );
		$image   = $this->makeImage( '"><script>alert(1)</script>', 800, 600, array( 'post_excerpt' => '<script>alert(2)</script><strong onclick="x()">bold</strong> <a href="javascript:alert(3)">l</a><img src=x onerror=alert(4)>' ), null, '"><script>alert(5)</script>' );
		$this->addItems( $gallery, array( $image ) );

		foreach ( array( EntryPoint::Shortcode, EntryPoint::Feed ) as $entry ) {
			$html = $this->render( $gallery, 0, $entry )->html;

			$this->assertStringNotContainsString( '<script', $html, $entry->value );
			$this->assertStringNotContainsString( 'onerror', $html, $entry->value );
			$this->assertStringNotContainsString( 'onclick', $html, $entry->value );
			$this->assertStringNotContainsString( 'javascript:', $html, $entry->value );
			$this->assertSame( '"><script>alert(5)</script>', $this->dom( $html )->query( '//img' )->item( 0 )->getAttribute( 'alt' ), 'The alt text round-trips as text.' );
		}
	}

	public function test_the_feed_markup_is_a_plain_list_without_data_attributes_scripts_or_classes(): void {
		$gallery = $this->makeGalleryPost( $this->author );
		$a       = $this->makeImage( 'A', 1600, 1200, array( 'post_excerpt' => 'A <em>nice</em> caption' ), null, 'Alt A' );
		$b       = $this->makeImage( 'B', 1600, 1200, array(), null, 'Alt B' );
		$this->addItems( $gallery, array( $a, $b ) );

		$result = $this->render( $gallery, 0, EntryPoint::Feed );
		$xp     = $this->dom( $result->html );

		$this->assertSame( array(), $result->assets );
		$this->assertStringStartsWith( '<ul>', $result->html );
		$this->assertStringNotContainsString( 'data-', $result->html );
		$this->assertStringNotContainsString( 'class=', $result->html );
		$this->assertStringNotContainsString( 'style=', $result->html );
		$this->assertStringNotContainsString( 'srcset', $result->html );
		$this->assertStringNotContainsString( 'loading=', $result->html );
		$this->assertSame( 2, $xp->query( '//ul/li/a[@href]/img[@alt][@width][@height]' )->length );
		$this->assertSame( 'A nice caption', $xp->query( '//li[1]/p' )->item( 0 )->textContent );
		$this->assertSame( 0, $xp->query( '//li[1]/p/em' )->length, 'Feed captions are plain text.' );
		$this->assertSame( 0, $xp->query( '//li[2]/p' )->length );
	}

	public function test_an_unknown_layout_falls_back_to_grid_with_an_editor_only_diagnostic(): void {
		$gallery = $this->makeGalleryPost( $this->author, 'publish', '', 'mosaic' );
		$this->addItems( $gallery, array( $this->makeImage() ) );

		$visitor = $this->render( $gallery );
		$editor  = $this->render( $gallery, $this->editor );

		$this->assertStringContainsString( 'swg-layout-grid', $visitor->html );
		$this->assertStringNotContainsString( 'swg-diagnostic', $visitor->html );
		$this->assertSame( array(), $visitor->diagnostics );
		$this->assertStringContainsString( 'swg-layout-grid', $editor->html );
		$this->assertStringContainsString( 'swg-diagnostic', $editor->html );
		$this->assertStringContainsString( '&quot;mosaic&quot;', $editor->html );
		$this->assertSame( 'grid', $editor->layoutId );
	}

	public function test_per_embed_layout_and_settings_overrides(): void {
		$gallery = $this->makeGalleryPost( $this->author, 'publish', '', 'grid', 'manual', array( 'columns' => 2 ) );
		$this->addItems( $gallery, array( $this->makeImage() ) );

		$merged  = $this->render( $gallery, 0, EntryPoint::Shortcode, array( 'settings' => array( 'columns' => 5 ) ) );
		$invalid = $this->render( $gallery, $this->author, EntryPoint::Shortcode, array( 'settings' => array( 'columns' => 99 ) ) );
		$bad_id  = $this->render( $gallery, 0, EntryPoint::Shortcode, array( 'layout' => '../../x' ) );

		$this->assertStringContainsString( '--swg-columns:5', $merged->html );
		$this->assertStringContainsString( '--swg-columns:2', $invalid->html );
		$this->assertStringContainsString( 'swg-diagnostic', $invalid->html );
		$this->assertStringContainsString( 'swg-layout-grid', $bad_id->html, 'An invalid layout id is dropped.' );
	}

	public function test_the_query_count_stays_within_a_small_constant_for_12_and_100_images(): void {
		global $wpdb;
		$counts = array();
		foreach ( array( 12, 100 ) as $size ) {
			$gallery = $this->gallery_with_images( $size );
			wp_set_current_user( 0 );
			Services::reset();
			wp_cache_flush();
			$before = $wpdb->num_queries;
			$result = Services::gallery_renderer()->render( $gallery, EntryPoint::Shortcode, array( 'per_page' => 100 ) );
			$counts[ $size ] = $wpdb->num_queries - $before;
			$this->assertSame( $size, $result->renderedItems );
		}

		$this->assertLessThanOrEqual( 2, abs( $counts[100] - $counts[12] ), 'Queries for 12 and 100 images: ' . wp_json_encode( $counts ) );
		$this->assertLessThanOrEqual( 25, $counts[100], 'Queries for 100 images: ' . wp_json_encode( $counts ) );
	}
}
