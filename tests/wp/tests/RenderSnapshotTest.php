<?php
/**
 * M3-20: snapshot per layout (grid, justified, masonry, feed), stale-cache behaviour end to end, several instances on one
 * page and the feed context, plus the unusable-attachment variants. The snapshots live in
 * tests/wp/fixtures/render-snapshots/ with ids normalised (attachment, item and gallery ids become tokens).
 *
 * Update the snapshots after an intended markup change:
 *   UPDATE_SNAPSHOTS=1 composer test:wp -- --filter RenderSnapshotTest
 * (refused when the CI environment variable is set; review the diff before committing).
 *
 * @package sw-gallery
 */

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Core\Application\Render\RenderAsset;
use SWGallery\Tests\Support\RenderFixtures;
use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\Settings\PluginSettings;

class RenderSnapshotTest extends SwgTestCase {

	use RenderFixtures;

	private const DIR = __DIR__ . '/../fixtures/render-snapshots/';

	private int $author;

	public function set_up(): void {
		parent::set_up();
		$this->purgeGalleries();
		Services::reset();
		$this->author = $this->makeUser( 'author' );
		$this->lockPassword();
		wp_cache_flush();
		$_SERVER['REQUEST_URI'] = '/sample-page/';
		$_GET                   = array();
	}

	public function tear_down(): void {
		$_GET = array();
		wp_set_current_user( 0 );
		$this->lockPassword();
		$this->purgeGalleries();
		Services::reset();
		parent::tear_down();
	}

	/**
	 * A fixed gallery: five images with different ratios, titles, alt texts and one caption, one local override.
	 *
	 * @return array{gallery: int, attachments: list<int>, items: list<int>}
	 */
	private function fixed_gallery( string $layout = 'grid', array $settings = array() ): array {
		$gallery = $this->makeGalleryPost( $this->author, 'publish', '', $layout, 'manual', $settings );
		$shapes  = array( array( 1600, 1200 ), array( 1200, 1600 ), array( 2000, 1000 ), array( 1600, 1200 ), array( 900, 900 ) );
		$images  = array();
		foreach ( $shapes as $index => [ $w, $h ] ) {
			$n        = $index + 1;
			$images[] = $this->makeImage( "Fixture $n", $w, $h, 1 === $n ? array( 'post_excerpt' => 'A <em>fine</em> caption' ) : array(), null, "Alt $n" );
		}
		$items = $this->addItems( $gallery, $images );
		Services::items()->saveOverrides( $gallery, $items[1], new \SWGallery\Core\Domain\Gallery\ItemOverrides( 'Own title', null, null ) );
		return array(
			'gallery'     => $gallery,
			'attachments' => $images,
			'items'       => $items,
		);
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
	 * Ids only appear in a few shapes (file names `photo-<id>`, instance ids `swg-<gallery>-<n>`, page arguments), so
	 * they are replaced there and nowhere else (plain numbers such as columns or alt texts must stay readable).
	 *
	 * @param array{gallery: int, attachments: list<int>, items: list<int>} $fixture Ids to replace.
	 */
	private function normalise( string $html, array $fixture ): string {
		$attachments = array_flip( $fixture['attachments'] );
		$html        = (string) preg_replace_callback(
			'/photo-(\d+)/',
			static fn( array $m ): string => isset( $attachments[ (int) $m[1] ] ) ? 'photo-{A' . ( $attachments[ (int) $m[1] ] + 1 ) . '}' : $m[0],
			$html
		);
		$html        = str_replace( array( 'swg-' . $fixture['gallery'] . '-', 'swg_page_' . $fixture['gallery'] ), array( 'swg-{G}-', 'swg_page_{G}' ), $html );
		return rtrim( (string) preg_replace( '/>\s*</', ">\n<", $html ) ) . "\n";
	}

	private function assert_snapshot( string $name, string $actual ): void {
		$file = self::DIR . $name . '.html';
		if ( '' !== (string) getenv( 'UPDATE_SNAPSHOTS' ) ) {
			if ( false !== getenv( 'CI' ) && '' !== (string) getenv( 'CI' ) ) {
				$this->fail( 'UPDATE_SNAPSHOTS is refused in CI.' );
			}
			if ( ! is_dir( self::DIR ) ) {
				mkdir( self::DIR, 0777, true );
			}
			file_put_contents( $file, $actual );
			$this->addToAssertionCount( 1 );
			return;
		}
		$this->assertFileExists( $file, "Missing snapshot $name: UPDATE_SNAPSHOTS=1 composer test:wp -- --filter RenderSnapshotTest" );
		$this->assertSame( (string) file_get_contents( $file ), $actual, "Snapshot $name changed (intended? update with UPDATE_SNAPSHOTS=1)." );
	}

	public function test_snapshot_grid(): void {
		$fixture = $this->fixed_gallery( 'grid', array( 'columns' => 3, 'gap' => 12 ) );
		$this->assert_snapshot( 'grid', $this->normalise( $this->render( $fixture['gallery'] )->html, $fixture ) );
	}

	public function test_snapshot_grid_page_two_with_pagination(): void {
		$fixture = $this->fixed_gallery( 'grid', array( 'columns' => 2 ) );
		$_GET[ 'swg_page_' . $fixture['gallery'] ] = '2';
		$this->assert_snapshot( 'grid-page-2', $this->normalise( $this->render( $fixture['gallery'], 0, EntryPoint::Shortcode, array( 'per_page' => 2 ) )->html, $fixture ) );
	}

	public function test_snapshot_justified(): void {
		$fixture = $this->fixed_gallery( 'justified', array( 'rowHeight' => 180, 'gap' => 8 ) );
		$this->assert_snapshot( 'justified', $this->normalise( $this->render( $fixture['gallery'] )->html, $fixture ) );
	}

	public function test_snapshot_masonry(): void {
		$fixture = $this->fixed_gallery( 'masonry', array( 'columns' => 3, 'gap' => 10 ) );
		$this->assert_snapshot( 'masonry', $this->normalise( $this->render( $fixture['gallery'] )->html, $fixture ) );
	}

	public function test_snapshot_carousel(): void {
		$fixture = $this->fixed_gallery( 'carousel', array( 'height' => 240, 'gap' => 10 ) );
		$result  = $this->render( $fixture['gallery'] );
		$this->assertSame( array( RenderAsset::Lightbox, RenderAsset::Carousel ), $result->assets );
		$this->assert_snapshot( 'carousel', $this->normalise( $result->html, $fixture ) );
	}

	public function test_snapshot_feed(): void {
		$fixture = $this->fixed_gallery();
		$this->assert_snapshot( 'feed', $this->normalise( $this->render( $fixture['gallery'], 0, EntryPoint::Feed )->html, $fixture ) );
	}

	public function test_the_feed_uses_absolute_urls_and_no_interactive_markup(): void {
		$fixture = $this->fixed_gallery();
		$html    = $this->render( $fixture['gallery'], 0, EntryPoint::Feed )->html;

		preg_match_all( '/(?:href|src)="([^"]+)"/', $html, $matches );
		$this->assertNotEmpty( $matches[1] );
		foreach ( $matches[1] as $url ) {
			$this->assertMatchesRegularExpression( '#^https?://#', $url, 'Feed readers have no base URL: ' . $url );
		}
		foreach ( array( '<script', 'data-swg', 'onclick', 'swg-', 'loading=', 'srcset', 'style=' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $html );
		}
		$this->assertSame( array(), $this->render( $fixture['gallery'], 0, EntryPoint::Feed )->assets );
	}

	public function test_stale_cache_is_dropped_when_the_media_changes_or_items_change(): void {
		$fixture = $this->fixed_gallery();
		$gallery = $fixture['gallery'];
		$first   = $this->render( $gallery )->html;
		$this->assertStringContainsString( 'Alt 3', $first );
		$this->assertSame( $first, $this->render( $gallery )->html, 'Served from the cache.' );

		// An alt text edited in the media library (epoch bump through the core hook).
		update_post_meta( $fixture['attachments'][2], '_wp_attachment_image_alt', 'Alt three edited' );
		$second = $this->render( $gallery )->html;
		$this->assertStringContainsString( 'Alt three edited', $second );
		$this->assertStringNotContainsString( 'Alt 3"', $second );

		// A deleted attachment disappears without touching the relation.
		wp_delete_attachment( $fixture['attachments'][4], true );
		$third = $this->render( $gallery )->html;
		$this->assertSame( 4, substr_count( $third, '<img' ) );
		$this->assertSame( 4, $this->itemRowCount( $gallery ), 'A really deleted attachment loses its relation (lifecycle hook).' );

		// An item added through the service bumps the revision.
		$extra = $this->makeImage( 'Extra', 1600, 1200, array(), null, 'Alt extra' );
		Services::item_service()->assign( new \SWGallery\Core\Domain\Access\Actor( $this->author ), $gallery, array( $extra ), Services::revisions()->current( $gallery ) );
		$this->assertStringContainsString( 'Alt extra', $this->render( $gallery )->html );

		// The public action also invalidates (any listener may bump the media epoch or the revision).
		$before = $this->render( $gallery )->html;
		Services::media_epoch()->bump();
		update_post_meta( $fixture['attachments'][0], '_wp_attachment_image_alt', 'Alt one late' );
		$this->assertNotSame( $before, $this->render( $gallery )->html );
	}

	public function test_several_instances_have_unique_ids_one_asset_set_and_independent_paging(): void {
		$one = $this->fixed_gallery( 'grid', array( 'columns' => 2 ) );
		$two = $this->fixed_gallery( 'grid', array( 'columns' => 2 ) );
		update_option( PluginSettings::OPTION, (string) wp_json_encode( array( 'itemsPerPage' => 2, 'schemaVersion' => 1 ) ), false );
		wp_set_current_user( 0 );
		Services::reset();
		$_GET[ 'swg_page_' . $one['gallery'] ] = '2';

		$html = do_shortcode( '[sw_gallery id="' . $one['gallery'] . '"][sw_gallery id="' . $two['gallery'] . '"][sw_gallery id="' . $one['gallery'] . '" page="1"]' );
		delete_option( PluginSettings::OPTION );

		preg_match_all( '/data-swg-instance="([^"]+)"/', $html, $ids );
		$this->assertCount( 3, array_unique( $ids[1] ) );
		$this->assertSame( array( 'swg-' . $one['gallery'] . '-1', 'swg-' . $two['gallery'] . '-1', 'swg-' . $one['gallery'] . '-2' ), $ids[1] );
		$this->assertStringContainsString( 'Alt 3', $html, 'Gallery one shows its page 2 (the request argument).' );
		$this->assertStringContainsString( 'swg_page_' . $two['gallery'] . '=2', $html, 'Gallery two links to ITS page 2 ...' );
		$document = new DOMDocument();
		$document->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR );
		$current = array();
		foreach ( ( new DOMXPath( $document ) )->query( '//nav//*[@aria-current="page"]' ) as $node ) {
			$current[] = trim( $node->textContent );
		}
		$this->assertSame( array( '2', '1', '2' ), $current, 'Gallery one is on page 2 (both embeds: the request argument wins), gallery two stays on page 1.' );
		$collector = Services::asset_collector();
		$this->assertSame( array( $one['gallery'], $two['gallery'] ), $collector->gallery_ids(), 'Each gallery is recorded once, in render order.' );
		$this->assertTrue( $collector->needs( RenderAsset::Lightbox ) );
		$this->assertFalse( $collector->needs( RenderAsset::Masonry ) );
	}

	public function test_unusable_attachments_are_skipped_without_notices_and_editors_are_told_once(): void {
		$gallery = $this->makeGalleryPost( $this->author );
		$good    = $this->makeImage( 'Good', 1600, 1200, array(), null, 'Good alt' );
		$deleted = $this->makeImage( 'Deleted' );
		$trashed = $this->makeImage( 'Trashed', 1600, 1200, array( 'post_status' => 'trash' ) );
		$pdf     = $this->makeAttachment( 0, 'application/pdf' );
		$nometa  = $this->makeAttachment( 0, 'image/jpeg' );
		$this->addItems( $gallery, array( $deleted, $trashed, $pdf, $nometa, $good, 424242 ) );
		// A row whose attachment vanished behind the plugin's back (an import, a manual SQL delete): no hook ran.
		global $wpdb;
		$wpdb->delete( $wpdb->posts, array( 'ID' => $deleted ) );
		clean_post_cache( $deleted );

		$visitor = $this->render( $gallery );
		$editor  = $this->render( $gallery, $this->author );

		$this->assertSame( 1, $visitor->renderedItems );
		$this->assertSame( 1, substr_count( $visitor->html, '<img' ) );
		$this->assertStringContainsString( 'Good alt', $visitor->html );
		$this->assertStringNotContainsString( 'swg-diagnostic', $visitor->html, 'Visitors see no diagnostics.' );
		$codes = array_map( static fn( $d ): string => $d->code, $editor->diagnostics );
		$this->assertSame( array( \SWGallery\Core\Application\Render\RenderDiagnostic::IMAGES_UNAVAILABLE ), $codes, 'Editors get one note for the image without file data.' );
		$this->assertStringContainsString( 'swg-diagnostic', $editor->html );
		$this->assertSame( 6, $this->itemRowCount( $gallery ), 'No relation is ever deleted by a render.' );
	}
}
