<?php
/**
 * GET/POST /swg/v1/galleries/{id}/items: paged item list and bulk assign.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Gallery\ItemOverrides;
use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\Tests\Support\RestItemFixtures;
use SWGallery\WordPress\Infrastructure\Services;

class RestItemTest extends SwgTestCase {

	use RestItemFixtures;

	private int $admin;
	private int $author;
	private int $author2;
	private int $contributor;

	public function set_up(): void {
		parent::set_up();
		$this->bootRest();
		$this->admin       = $this->makeUser( 'administrator' );
		$this->author      = $this->makeUser( 'author' );
		$this->author2     = $this->makeUser( 'author' );
		$this->contributor = $this->makeUser( 'contributor' );
	}

	public function tear_down(): void {
		$this->shutdownRest();
		parent::tear_down();
	}

	// List.

	public function test_list_returns_items_in_stored_order_with_the_documented_shape(): void {
		$gallery     = $this->makeGallery( $this->author );
		$attachments = $this->seedItems( $gallery, $this->author, 3 );
		$ids         = $this->itemIds( $gallery );
		Services::items()->saveOverrides( $gallery, $ids[0], new ItemOverrides( 'Own title', '', null ) );
		Services::items()->saveItemSettings( $gallery, $ids[0], array( 'focus' => 'top' ) );
		wp_update_post(
			array(
				'ID'         => $attachments[0],
				'post_title' => 'Global title',
			)
		);
		update_post_meta( $attachments[0], '_wp_attachment_image_alt', 'Global alt' );

		$response = $this->call( 'GET', '/galleries/' . $gallery . '/items', array(), $this->author );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 3, $data );
		$this->assertSame( $ids, array_column( $data, 'id' ), 'ids are item ids' );		$this->assertSame( $attachments, array_column( $data, 'attachmentId' ) );
		$this->assertSame( array( 1, 2, 3 ), array_column( $data, 'position' ) );
		$first = $data[0];
		$this->assertSame( array( 'title' => 'Own title', 'caption' => '', 'alt' => null ), $first['overrides'] );
		$this->assertSame( array( 'title' => 'Own title', 'caption' => '', 'alt' => 'Global alt' ), $first['effective'] );
		$this->assertSame( 'Global title', $first['global']['title'] );
		$this->assertSame( 'Caption 0', $first['global']['caption'] );
		$this->assertSame( 'top', $first['settings']->focus );
		$this->assertFalse( $first['missing'] );
		$this->assertSame( 'image/jpeg', $first['media']['mimeType'] );
		$this->assertSame( array( 'mimeType', 'width', 'height', 'thumbnailUrl', 'fullUrl' ), array_keys( $first['media'] ) );
		$this->assertEquals( (object) array(), $data[1]['settings'] );
		$this->assertSame( array( 'title' => null, 'caption' => null, 'alt' => null ), $data[1]['overrides'] );
	}

	public function test_list_has_pagination_headers_and_the_gallery_revision(): void {
		$gallery = $this->makeGallery( $this->author );
		$this->seedItems( $gallery, $this->author, 5 );

		$response = $this->call( 'GET', '/galleries/' . $gallery . '/items', array( 'per_page' => 2, 'page' => 2 ), $this->author );
		$headers  = $response->get_headers();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $response->get_data() );
		$this->assertSame( array( 3, 4 ), array_column( $response->get_data(), 'position' ) );
		$this->assertSame( '5', $headers['X-WP-Total'] );
		$this->assertSame( '3', $headers['X-WP-TotalPages'] );
		$this->assertSame( (string) $this->revisionOf( $gallery ), $headers['X-SWG-Revision'] );
	}

	public function test_list_defaults_to_fifty_per_page_and_bounds_the_paging_arguments(): void {
		$gallery = $this->makeGallery( $this->author );
		$this->seedItems( $gallery, $this->author, 52 );

		$default = $this->call( 'GET', '/galleries/' . $gallery . '/items', array(), $this->author );
		$this->assertCount( 50, $default->get_data() );
		$this->assertSame( '2', $default->get_headers()['X-WP-TotalPages'] );
		foreach ( array(
			array( 'per_page' => 0 ),
			array( 'per_page' => 101 ),
			array( 'page' => 0 ),
			array( 'page' => 'x' ),
		) as $params ) {
			$this->assertSame( 400, $this->call( 'GET', '/galleries/' . $gallery . '/items', $params, $this->author )->get_status(), (string) wp_json_encode( $params ) );
		}
		$max = $this->call( 'GET', '/galleries/' . $gallery . '/items', array( 'per_page' => 100 ), $this->author );
		$this->assertCount( 52, $max->get_data() );
		$past = $this->call( 'GET', '/galleries/' . $gallery . '/items', array( 'page' => 9 ), $this->author );
		$this->assertSame( 200, $past->get_status() );
		$this->assertSame( array(), $past->get_data() );
		$this->assertSame( '52', $past->get_headers()['X-WP-Total'] );
	}

	public function test_an_empty_gallery_lists_nothing(): void {
		$gallery  = $this->makeGallery( $this->author );
		$response = $this->call( 'GET', '/galleries/' . $gallery . '/items', array(), $this->author );

		$this->assertSame( array(), $response->get_data() );
		$this->assertSame( '0', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '0', $response->get_headers()['X-WP-TotalPages'] );
	}

	public function test_rows_of_vanished_attachments_stay_listed_as_missing_and_are_not_deleted(): void {
		$gallery = $this->makeGallery( $this->author );
		$real    = $this->seedItems( $gallery, $this->author, 1 );
		Services::items()->append( $gallery, 987654 );
		$svg = $this->makeAttachment( $this->author, 'image/svg+xml' );
		Services::items()->append( $gallery, $svg );

		$data = $this->call( 'GET', '/galleries/' . $gallery . '/items', array(), $this->author )->get_data();

		$this->assertCount( 3, $data );
		$this->assertSame( array( false, true, true ), array_column( $data, 'missing' ) );
		$this->assertSame( $real[0], $data[0]['attachmentId'] );
		$this->assertNull( $data[1]['media'] );
		$this->assertNull( $data[1]['global'] );
		$this->assertSame( array( 'title' => '', 'caption' => '', 'alt' => '' ), $data[1]['effective'] );
		$this->assertSame( 3, Services::items()->count( $gallery ) );
	}

	public function test_attachments_the_actor_may_not_use_are_reported_as_missing_without_metadata(): void {
		$gallery = $this->makeGallery( $this->author );
		$private = $this->factory()->post->create(
			array(
				'post_author' => $this->author2,
				'post_status' => 'private',
			)
		);
		$hidden  = $this->makeAttachment( $this->author2, 'image/jpeg', array( 'post_parent' => $private, 'post_title' => 'Secret title' ) );
		Services::item_service()->assign( new Actor( $this->admin ), $gallery, array( $hidden ), new Revision( 1 ) );

		$as_admin  = $this->call( 'GET', '/galleries/' . $gallery . '/items', array(), $this->admin )->get_data();
		$as_author = $this->call( 'GET', '/galleries/' . $gallery . '/items', array(), $this->author );

		$this->assertFalse( $as_admin[0]['missing'] );
		$this->assertSame( 'Secret title', $as_admin[0]['global']['title'] );
		$this->assertTrue( $as_author->get_data()[0]['missing'] );
		$this->assertStringNotContainsString( 'Secret title', (string) wp_json_encode( $as_author->get_data() ) );
		$this->assertNull( $as_author->get_data()[0]['media'] );
	}

	public function test_contributors_cannot_use_attachments_so_their_items_read_as_missing(): void {
		$gallery = $this->makeGallery( $this->contributor );
		$image   = $this->makeAttachment( $this->admin );
		Services::item_service()->assign( new Actor( $this->admin ), $gallery, array( $image ), new Revision( 1 ) );

		$data = $this->call( 'GET', '/galleries/' . $gallery . '/items', array(), $this->contributor )->get_data();

		$this->assertTrue( $data[0]['missing'] );
		$this->assertNull( $data[0]['media'] );
	}

	public function test_responses_never_expose_file_paths(): void {
		$gallery = $this->makeGallery( $this->author );
		$this->seedItems( $gallery, $this->author, 2 );
		$body = (string) wp_json_encode( $this->call( 'GET', '/galleries/' . $gallery . '/items', array(), $this->author )->get_data() );

		foreach ( array( ABSPATH, '/var/www', '_wp_attached_file', '"file"' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $body );
		}
	}

	public function test_the_query_count_does_not_grow_with_the_page_size(): void {
		global $wpdb;
		$small = $this->makeGallery( $this->author );
		$this->seedItems( $small, $this->author, 5 );
		$large = $this->makeGallery( $this->author );
		$this->seedItems( $large, $this->author, 50 );

		$counts = array();
		foreach ( array( 5 => $small, 50 => $large ) as $size => $gallery ) {
			wp_cache_flush();
			// User and option loading is cache warm-up of the test setup, not of the item routes: not counted.
			$statements = array();
			$ignored    = '/\b(' . preg_quote( $wpdb->prefix, '/' ) . '(users|usermeta|options))\b/';
			$collector  = static function ( $query ) use ( &$statements, $ignored ) {
				if ( ! preg_match( $ignored, (string) $query ) ) {
					$statements[] = (string) $query;
				}
				return $query;
			};
			add_filter( 'query', $collector );
			$response = $this->call( 'GET', '/galleries/' . $gallery . '/items', array( 'per_page' => 100 ), $this->author );
			remove_filter( 'query', $collector );
			$counts[ $size ] = count( $statements );
			$this->assertCount( $size, $response->get_data() );
		}

		$this->assertSame( $counts[5], $counts[50], 'Query count must not depend on the number of items: ' . wp_json_encode( $counts ) );
		$this->assertLessThan( 40, $counts[50] );
	}

	// Assign.

	public function test_assign_reports_added_duplicates_and_missing_honestly(): void {
		$gallery = $this->makeGallery( $this->author );
		$have    = $this->seedItems( $gallery, $this->author, 1 );
		$new     = $this->makeAttachment( $this->author );
		$revision = $this->revisionOf( $gallery );

		$response = $this->call(
			'POST',
			'/galleries/' . $gallery . '/items',
			array(
				'attachmentIds'    => array( $new, $have[0], 424242, $new ),
				'expectedRevision' => $revision,
			),
			$this->author
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'added'           => array( $new ),
				'alreadyAssigned' => array( $have[0] ),
				'missing'         => array( 424242 ),
				'revision'        => $revision + 1,
			),
			$response->get_data()
		);
		$this->assertSame( 2, Services::items()->count( $gallery ) );
	}

	public function test_assigning_only_duplicates_keeps_the_revision(): void {
		$gallery = $this->makeGallery( $this->author );
		$have    = $this->seedItems( $gallery, $this->author, 1 );
		$revision = $this->revisionOf( $gallery );

		$data = $this->call( 'POST', '/galleries/' . $gallery . '/items', array( 'attachmentIds' => $have, 'expectedRevision' => $revision ), $this->author )->get_data();

		$this->assertSame( array(), $data['added'] );
		$this->assertSame( $have, $data['alreadyAssigned'] );
		$this->assertSame( $revision, $data['revision'] );
		$this->assertSame( $revision, $this->revisionOf( $gallery ) );
	}

	public function test_attachments_the_actor_may_not_use_are_reported_as_missing_on_assign(): void {
		$gallery = $this->makeGallery( $this->author );
		$private = $this->factory()->post->create(
			array(
				'post_author' => $this->author2,
				'post_status' => 'private',
			)
		);
		$hidden  = $this->makeAttachment( $this->author2, 'image/jpeg', array( 'post_parent' => $private ) );

		$hidden_result  = $this->call( 'POST', '/galleries/' . $gallery . '/items', array( 'attachmentIds' => array( $hidden ), 'expectedRevision' => 1 ), $this->author );
		$missing_result = $this->call( 'POST', '/galleries/' . $gallery . '/items', array( 'attachmentIds' => array( 999999 ), 'expectedRevision' => 1 ), $this->author );

		$this->assertSame( 200, $hidden_result->get_status() );
		$this->assertSame( array( $hidden ), $hidden_result->get_data()['missing'] );
		$this->assertSame( array(), $hidden_result->get_data()['added'] );
		$this->assertSame( array( 999999 ), $missing_result->get_data()['missing'] );
		$this->assertSame( 0, Services::items()->count( $gallery ) );
	}

	public function test_assign_validates_the_batch(): void {
		$gallery = $this->makeGallery( $this->author );
		$base    = '/galleries/' . $gallery . '/items';

		$too_many = $this->call( 'POST', $base, array( 'attachmentIds' => range( 1, 501 ), 'expectedRevision' => 1 ), $this->author );
		$this->assertSame( 400, $too_many->get_status() );
		$this->assertSame( 'rest_invalid_param', $too_many->get_data()['code'] );
		$exact = $this->call( 'POST', $base, array( 'attachmentIds' => range( 1, 500 ), 'expectedRevision' => 1 ), $this->author );
		$this->assertSame( 200, $exact->get_status() );
		$this->assertCount( 500, $exact->get_data()['missing'] );

		foreach ( array(
			array( 'attachmentIds' => array(), 'expectedRevision' => 1 ),
			array( 'attachmentIds' => array( 'a' ), 'expectedRevision' => 1 ),
			array( 'attachmentIds' => array( 0 ), 'expectedRevision' => 1 ),
			array( 'attachmentIds' => array( -3 ), 'expectedRevision' => 1 ),
			array( 'attachmentIds' => 'abc', 'expectedRevision' => 1 ),
			array( 'expectedRevision' => 1 ),
			array( 'attachmentIds' => array( 1 ) ),
		) as $body ) {
			$this->assertSame( 400, $this->call( 'POST', $base, $body, $this->author )->get_status(), (string) wp_json_encode( $body ) );
		}
	}

	public function test_assign_with_a_stale_revision_answers_409_and_writes_nothing(): void {
		$gallery = $this->makeGallery( $this->author );
		$image   = $this->makeAttachment( $this->author );

		$response = $this->call( 'POST', '/galleries/' . $gallery . '/items', array( 'attachmentIds' => array( $image ), 'expectedRevision' => 7 ), $this->author );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'swg_revision_conflict', $response->get_data()['code'] );
		$this->assertSame( 1, $response->get_data()['data']['currentRevision'] );
		$this->assertSame( 0, Services::items()->count( $gallery ) );
		$this->assertSame( 1, $this->revisionOf( $gallery ) );
	}

	public function test_assigning_into_a_gallery_the_user_may_not_edit(): void {
		$draft     = $this->makeGallery( $this->author2, GalleryStatus::Draft );
		$published = $this->makeGallery( $this->author2, GalleryStatus::Publish );
		$image     = $this->makeAttachment( $this->author );
		$body      = array( 'attachmentIds' => array( $image ), 'expectedRevision' => 1 );

		$hidden    = $this->call( 'POST', '/galleries/' . $draft . '/items', $body, $this->author );
		$missing   = $this->call( 'POST', '/galleries/999999/items', $body, $this->author );
		$forbidden = $this->call( 'POST', '/galleries/' . $published . '/items', $body, $this->author );

		$this->assertSame( 404, $hidden->get_status() );
		$this->assertSame( $missing->get_data(), $hidden->get_data() );
		$this->assertSame( 403, $forbidden->get_status() );
		$this->assertSame( 0, Services::items()->count( $draft ) + Services::items()->count( $published ) );
	}

	public function test_a_foreign_published_gallery_is_listable_and_a_foreign_draft_is_not(): void {
		$draft     = $this->makeGallery( $this->author2, GalleryStatus::Draft );
		$published = $this->makeGallery( $this->author2, GalleryStatus::Publish );
		$this->seedItems( $published, $this->author2, 1 );

		$this->assertSame( 404, $this->call( 'GET', '/galleries/' . $draft . '/items', array(), $this->author )->get_status() );
		$this->assertSame( 200, $this->call( 'GET', '/galleries/' . $published . '/items', array(), $this->author )->get_status() );
	}
}
