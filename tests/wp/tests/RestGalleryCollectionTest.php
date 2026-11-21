<?php
/**
 * GET/POST /swg/v1/galleries: listing (visibility, filters, pagination, one grouped count query), creation, publish rule.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\Tests\Support\RestFixtures;
use SWGallery\WordPress\Infrastructure\Services;

class RestGalleryCollectionTest extends SwgTestCase {

	use RestFixtures;

	private int $admin;
	private int $editor;
	private int $author;
	private int $author2;
	private int $contributor;
	private int $subscriber;

	public function set_up(): void {
		parent::set_up();
		$this->bootRest();
		$this->admin       = $this->makeUser( 'administrator' );
		$this->editor      = $this->makeUser( 'editor' );
		$this->author      = $this->makeUser( 'author' );
		$this->author2     = $this->makeUser( 'author' );
		$this->contributor = $this->makeUser( 'contributor' );
		$this->subscriber  = $this->makeUser( 'subscriber' );
	}

	public function tear_down(): void {
		$this->shutdownRest();
		parent::tear_down();
	}

	public function test_a_draft_is_created_with_revision_one_location_and_the_full_shape(): void {
		$response = $this->call(
			'POST',
			'/galleries',
			array(
				'title'       => '  Trip <b>2026</b> ',
				'description' => "Line one\nLine two <script>x</script>",
				'sortMode'    => 'title_asc',
				'perPage'     => 12,
				'hover'       => array( 'title' => true, 'animation' => 'slide-up' ),
				'settings'    => array( 'columns' => 3 ),
			),
			$this->author
		);
		$data     = $this->data( $response );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'Trip 2026', $data['title'] );
		$this->assertStringContainsString( "Line one\nLine two", $data['description'] );
		$this->assertStringNotContainsString( '<script>', $data['description'] );
		$this->assertSame( 'draft', $data['status'] );
		$this->assertSame( $this->author, $data['ownerId'] );
		$this->assertSame( 1, $data['revision'] );
		$this->assertSame( 0, $data['itemCount'] );
		$this->assertSame( 'grid', $data['layout'] );
		$this->assertSame( 'title_asc', $data['sortMode'] );
		$this->assertSame( 12, $data['perPage'] );
		$this->assertSame( array( 'title' => true, 'caption' => false, 'animation' => 'slide-up' ), $data['hover'] );
		$this->assertSame( 3, $data['settings']->columns );
		$this->assertNull( $data['coverAttachmentId'] );
		$this->assertNull( $data['coverUrl'] );
		$this->assertFalse( $data['passwordProtected'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $data['modified'] );
		$this->assertTrue( $data['canEdit'] );
		$this->assertTrue( $data['canDelete'] );
		$this->assertTrue( $data['canPublish'] );
		$this->assertSame( rest_url( 'swg/v1/galleries/' . $data['id'] ), $response->get_headers()['Location'] );
		$this->assertEqualsCanonicalizing(
			array( 'id', 'title', 'description', 'status', 'ownerId', 'coverAttachmentId', 'coverUrl', 'layout', 'sortMode', 'perPage', 'hover', 'settings', 'passwordProtected', 'modified', 'revision', 'itemCount', 'canEdit', 'canDelete', 'canPublish' ),
			array_keys( $data )
		);
	}

	public function test_the_owner_cannot_be_spoofed_on_create(): void {
		$data = $this->data( $this->call( 'POST', '/galleries', array( 'title' => 'x', 'ownerId' => $this->admin ), $this->author ) );

		$this->assertSame( $this->author, $data['ownerId'] );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function invalid_create_bodies(): array {
		return array(
			'missing title'      => array( array(), 'rest_missing_callback_param' ),
			'empty title'        => array( array( 'title' => '' ), 'rest_invalid_param' ),
			'title too long'     => array( array( 'title' => str_repeat( 'a', 201 ) ), 'rest_invalid_param' ),
			'title not a string' => array( array( 'title' => array( 'a' ) ), 'rest_invalid_param' ),
			'bad status'         => array( array( 'title' => 'x', 'status' => 'trash' ), 'rest_invalid_param' ),
			'future status'      => array( array( 'title' => 'x', 'status' => 'future' ), 'rest_invalid_param' ),
			'bad sort mode'      => array( array( 'title' => 'x', 'sortMode' => 'random' ), 'rest_invalid_param' ),
			'hover animation'    => array( array( 'title' => 'x', 'hover' => array( 'animation' => 'spin' ) ), 'rest_invalid_param' ),
			'hover title type'   => array( array( 'title' => 'x', 'hover' => array( 'title' => 'yes' ) ), 'rest_invalid_param' ),
			'hover unknown key'  => array( array( 'title' => 'x', 'hover' => array( 'blink' => true ) ), 'rest_invalid_param' ),
			'hover not object'   => array( array( 'title' => 'x', 'hover' => 'fade' ), 'rest_invalid_param' ),
			'per page zero'      => array( array( 'title' => 'x', 'perPage' => 0 ), 'rest_invalid_param' ),
			'per page too large' => array( array( 'title' => 'x', 'perPage' => 201 ), 'rest_invalid_param' ),
			'bad layout id'      => array( array( 'title' => 'x', 'layout' => 'Bad Layout!' ), 'rest_invalid_param' ),
			'settings not object' => array( array( 'title' => 'x', 'settings' => 'columns' ), 'rest_invalid_param' ),
		);
	}

	/**
	 * @dataProvider invalid_create_bodies
	 * @param array<string, mixed> $body Request body.
	 */
	public function test_malformed_create_requests_are_400( array $body, string $code ): void {
		$response = $this->call( 'POST', '/galleries', $body, $this->author );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( $code, $this->data( $response )['code'] );
		$this->assertSame( 0, $this->count_galleries() );
	}

	public function test_semantically_invalid_create_requests_are_422(): void {
		$unknown = $this->call( 'POST', '/galleries', array( 'title' => 'x', 'layout' => 'nope' ), $this->author );
		$this->assertSame( 422, $unknown->get_status() );
		$this->assertSame( 'swg_unknown_layout', $this->data( $unknown )['code'] );

		$invalid = $this->call( 'POST', '/galleries', array( 'title' => 'x', 'settings' => array( 'columns' => 'many' ) ), $this->author );
		$this->assertSame( 422, $invalid->get_status() );
		$data = $this->data( $invalid );
		$this->assertSame( 'swg_validation_failed', $data['code'] );
		$this->assertNotEmpty( $data['data']['errors'][0]['path'] );
		$this->assertNotEmpty( $data['data']['errors'][0]['code'] );
		$this->assertSame( 0, $this->count_galleries() );
	}

	public function test_malformed_json_is_400(): void {
		wp_set_current_user( $this->author );
		$request = new WP_REST_Request( 'POST', '/swg/v1/galleries' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( '{"title": ' );

		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}

	public function test_publish_and_private_need_the_publish_right_on_create(): void {
		foreach ( array( 'publish', 'private' ) as $status ) {
			$denied = $this->call( 'POST', '/galleries', array( 'title' => 'x', 'status' => $status ), $this->contributor );
			$this->assertSame( 403, $denied->get_status() );
			$this->assertSame( 'swg_cannot_publish', $this->data( $denied )['code'] );

			$allowed = $this->call( 'POST', '/galleries', array( 'title' => 'x', 'status' => $status ), $this->author );
			$this->assertSame( 201, $allowed->get_status() );
			$this->assertSame( $status, $this->data( $allowed )['status'] );
		}
		$this->assertSame( 2, $this->count_galleries() );

		$pending = $this->call( 'POST', '/galleries', array( 'title' => 'x', 'status' => 'pending' ), $this->contributor );
		$this->assertSame( 201, $pending->get_status() );
		$this->assertFalse( $this->data( $pending )['canPublish'] );
	}

	public function test_subscribers_and_anonymous_visitors_cannot_create(): void {
		$this->assertSame( 403, $this->call( 'POST', '/galleries', array( 'title' => 'x' ), $this->subscriber )->get_status() );
		$this->assertSame( 401, $this->call( 'POST', '/galleries', array( 'title' => 'x' ) )->get_status() );
		$this->assertSame( 0, $this->count_galleries() );
	}

	public function test_the_list_shows_every_non_trashed_gallery_to_editors_newest_first(): void {
		$first  = $this->makeGallery( $this->author, GalleryStatus::Draft, 'First' );
		$second = $this->makeGallery( $this->author2, GalleryStatus::Publish, 'Second' );
		$third  = $this->makeGallery( $this->admin, GalleryStatus::Private, 'Third' );
		$this->setModified( $first, '2026-01-01 10:00:00' );
		$this->setModified( $second, '2026-01-02 10:00:00' );
		$this->setModified( $third, '2026-01-03 10:00:00' );
		$trashed = $this->makeGallery( $this->author, GalleryStatus::Draft, 'Trashed' );
		Services::gallery_service()->trash( new Actor( $this->author ), $trashed, new Revision( 1 ) );

		$response = $this->call( 'GET', '/galleries', array(), $this->editor );
		$data     = $this->data( $response );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $third, $second, $first ), array_column( $data, 'id' ) );
		$this->assertSame( '3', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '1', $response->get_headers()['X-WP-TotalPages'] );
		$this->assertSame( 1, $data[0]['revision'] );
	}

	public function test_an_author_only_sees_own_galleries_whatever_the_owner_filter_says(): void {
		$own     = $this->makeGallery( $this->author, GalleryStatus::Draft, 'Own' );
		$foreign = $this->makeGallery( $this->author2, GalleryStatus::Publish, 'Foreign published' );
		$this->makeGallery( $this->author2, GalleryStatus::Draft, 'Foreign draft' );

		$default = $this->call( 'GET', '/galleries', array(), $this->author );
		$this->assertSame( array( $own ), array_column( $this->data( $default ), 'id' ) );
		$this->assertSame( '1', $default->get_headers()['X-WP-Total'] );

		$forced = $this->call( 'GET', '/galleries', array( 'owner' => $this->author2 ), $this->author );
		$this->assertSame( array( $own ), array_column( $this->data( $forced ), 'id' ) );
		$this->assertNotContains( $foreign, array_column( $this->data( $forced ), 'id' ) );

		$editor_filtered = $this->call( 'GET', '/galleries', array( 'owner' => $this->author2 ), $this->editor );
		$this->assertCount( 2, $this->data( $editor_filtered ) );
	}

	public function test_filters_search_and_status_lists(): void {
		$draft     = $this->makeGallery( $this->author, GalleryStatus::Draft, 'Alpine summit' );
		$published = $this->makeGallery( $this->author, GalleryStatus::Publish, 'Beach day' );
		$private   = $this->makeGallery( $this->author, GalleryStatus::Private, 'Alpine lake' );

		$search = $this->data( $this->call( 'GET', '/galleries', array( 'search' => 'Alpine' ), $this->admin ) );
		$this->assertEqualsCanonicalizing( array( $draft, $private ), array_column( $search, 'id' ) );

		$statuses = $this->data( $this->call( 'GET', '/galleries', array( 'status' => 'publish,private' ), $this->admin ) );
		$this->assertEqualsCanonicalizing( array( $published, $private ), array_column( $statuses, 'id' ) );

		$array_form = $this->data( $this->call( 'GET', '/galleries', array( 'status' => array( 'draft' ) ), $this->admin ) );
		$this->assertSame( array( $draft ), array_column( $array_form, 'id' ) );
	}

	public function test_the_trash_is_listed_only_on_request_and_needs_delete_rights(): void {
		$own     = $this->makeGallery( $this->author, GalleryStatus::Draft, 'Mine' );
		$foreign = $this->makeGallery( $this->author2, GalleryStatus::Draft, 'Theirs' );
		Services::gallery_service()->trash( new Actor( $this->author ), $own, new Revision( 1 ) );
		Services::gallery_service()->trash( new Actor( $this->author2 ), $foreign, new Revision( 1 ) );

		$this->assertSame( array(), $this->data( $this->call( 'GET', '/galleries', array(), $this->admin ) ) );

		$all = $this->data( $this->call( 'GET', '/galleries', array( 'status' => 'trash' ), $this->editor ) );
		$this->assertEqualsCanonicalizing( array( $own, $foreign ), array_column( $all, 'id' ) );

		$mine = $this->data( $this->call( 'GET', '/galleries', array( 'status' => 'trash' ), $this->author ) );
		$this->assertSame( array( $own ), array_column( $mine, 'id' ) );
	}

	public function test_contributors_may_list_the_trash_of_their_own_galleries_only(): void {
		$own     = $this->makeGallery( $this->contributor, GalleryStatus::Draft, 'Mine' );
		$foreign = $this->makeGallery( $this->author, GalleryStatus::Draft, 'Theirs' );
		Services::gallery_service()->trash( new Actor( $this->contributor ), $own, new Revision( 1 ) );
		Services::gallery_service()->trash( new Actor( $this->author ), $foreign, new Revision( 1 ) );

		$response = $this->call( 'GET', '/galleries', array( 'status' => 'trash' ), $this->contributor );

		$this->assertSame( array( $own ), array_column( $this->data( $response ), 'id' ) );
	}

	public function test_a_user_without_delete_rights_cannot_list_the_trash(): void {
		$role = add_role( 'swg_no_delete', 'No delete', array( 'read' => true, 'edit_posts' => true ) );
		$this->assertNotNull( $role );
		$user = $this->makeUser( 'swg_no_delete' );
		try {
			$this->assertSame( 403, $this->call( 'GET', '/galleries', array( 'status' => 'trash' ), $user )->get_status() );
			$this->assertSame( 200, $this->call( 'GET', '/galleries', array(), $user )->get_status() );
		} finally {
			remove_role( 'swg_no_delete' );
		}
	}

	public function test_pagination_is_bounded_and_reports_headers(): void {
		for ( $i = 1; $i <= 5; ++$i ) {
			$this->makeGallery( $this->author, GalleryStatus::Draft, 'G' . $i );
		}

		$page = $this->call( 'GET', '/galleries', array( 'per_page' => 2, 'page' => 3 ), $this->author );
		$this->assertSame( 200, $page->get_status() );
		$this->assertCount( 1, $this->data( $page ) );
		$this->assertSame( '5', $page->get_headers()['X-WP-Total'] );
		$this->assertSame( '3', $page->get_headers()['X-WP-TotalPages'] );

		$beyond = $this->call( 'GET', '/galleries', array( 'per_page' => 2, 'page' => 9 ), $this->author );
		$this->assertSame( 200, $beyond->get_status() );
		$this->assertSame( array(), $this->data( $beyond ) );
		$this->assertSame( '3', $beyond->get_headers()['X-WP-TotalPages'] );

		$this->assertCount( 5, $this->data( $this->call( 'GET', '/galleries', array( 'per_page' => 100 ), $this->author ) ) );
		foreach ( array(
			array( 'per_page' => 101 ),
			array( 'per_page' => 0 ),
			array( 'per_page' => -1 ),
			array( 'page' => 0 ),
			array( 'page' => 100001 ),
			array( 'page' => 'x' ),
			array( 'status' => 'bogus' ),
			array( 'search' => str_repeat( 'a', 201 ) ),
		) as $params ) {
			$this->assertSame( 400, $this->call( 'GET', '/galleries', $params, $this->author )->get_status(), (string) wp_json_encode( $params ) );
		}
	}

	public function test_default_page_size_is_twenty(): void {
		for ( $i = 1; $i <= 21; ++$i ) {
			$this->makeGallery( $this->author, GalleryStatus::Draft, 'G' . $i );
		}

		$response = $this->call( 'GET', '/galleries', array(), $this->author );

		$this->assertCount( 20, $this->data( $response ) );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );
	}

	public function test_item_counts_come_from_one_grouped_query(): void {
		$with_two = $this->makeGallery( $this->author, GalleryStatus::Draft, 'Two' );
		$with_one = $this->makeGallery( $this->author, GalleryStatus::Draft, 'One' );
		$empty    = $this->makeGallery( $this->author, GalleryStatus::Draft, 'Empty' );
		$actor    = new Actor( $this->author );
		$images   = array( $this->makeAttachment( $this->author ), $this->makeAttachment( $this->author ) );
		Services::item_service()->assign( $actor, $with_two, $images, new Revision( 1 ) );
		Services::item_service()->assign( $actor, $with_one, array( $images[0] ), new Revision( 1 ) );

		$count_queries = 0;
		$counter       = static function ( $sql ) use ( &$count_queries ) {
			if ( str_contains( (string) $sql, 'COUNT(*)' ) && str_contains( (string) $sql, 'swg_gallery_items' ) ) {
				++$count_queries;
			}
			return $sql;
		};
		add_filter( 'query', $counter );
		$response = $this->call( 'GET', '/galleries', array(), $this->author );
		remove_filter( 'query', $counter );

		$counts = array_column( $this->data( $response ), 'itemCount', 'id' );
		$this->assertEquals( array( $with_two => 2, $with_one => 1, $empty => 0 ), $counts );
		$this->assertSame( 1, $count_queries );
	}

	public function test_hints_reflect_the_rights_of_the_requesting_user(): void {
		$this->makeGallery( $this->author, GalleryStatus::Publish, 'Published by author' );

		$as_author      = $this->data( $this->call( 'GET', '/galleries', array(), $this->author ) )[0];
		$as_editor      = $this->data( $this->call( 'GET', '/galleries', array(), $this->editor ) )[0];
		$as_contributor = $this->data( $this->call( 'GET', '/galleries', array( 'search' => 'Published' ), $this->contributor ) );

		$this->assertTrue( $as_author['canEdit'] && $as_author['canDelete'] && $as_author['canPublish'] );
		$this->assertTrue( $as_editor['canEdit'] && $as_editor['canDelete'] && $as_editor['canPublish'] );
		$this->assertSame( array(), $as_contributor );
	}

	private function count_galleries(): int {
		return count(
			get_posts(
				array(
					'post_type'        => 'swg_gallery',
					'post_status'      => 'any',
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'suppress_filters' => true,
				)
			)
		);
	}

	private function setModified( int $id, string $when ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => $when,
				'post_modified_gmt' => $when,
			),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );
	}
}
