<?php
/**
 * GET /swg/v1/media: permission-filtered image search (decision D11), paging, filters, resource shape, bounded queries.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Tests\Support\RestFixtures;
use SWGallery\WordPress\Database\Schema;
use SWGallery\WordPress\Infrastructure\Services;

class RestMediaTest extends SwgTestCase {

	use RestFixtures;

	private int $admin;
	private int $editor;
	private int $author;
	private int $author2;

	/** @var list<int> */
	private array $posts = array();

	public function set_up(): void {
		parent::set_up();
		$this->bootRest();
		$this->admin   = $this->makeUser( 'administrator' );
		$this->editor  = $this->makeUser( 'editor' );
		$this->author  = $this->makeUser( 'author' );
		$this->author2 = $this->makeUser( 'author' );
	}

	public function tear_down(): void {
		foreach ( $this->posts as $id ) {
			wp_delete_post( $id, true );
		}
		$this->posts = array();
		$this->shutdownRest();
		parent::tear_down();
	}

	public function test_role_matrix(): void {
		$expected = array(
			'administrator' => 200,
			'editor'        => 200,
			'author'        => 200,
			'contributor'   => 403,
			'subscriber'    => 403,
			'anonymous'     => 401,
		);
		foreach ( $expected as $role => $status ) {
			$user = 'anonymous' === $role ? 0 : $this->makeUser( $role );
			$this->assertSame( $status, $this->call( 'GET', '/media', array(), $user )->get_status(), $role );
		}
	}

	public function test_every_actor_sees_exactly_what_the_access_policy_allows(): void {
		$public  = $this->makePost( 'publish', $this->author2 );
		$private = $this->makePost( 'private', $this->author2 );
		$draft   = $this->makePost( 'draft', $this->author );
		$all     = array(
			$this->makeAttachment( $this->author ),
			$this->makeAttachment( $this->author ),
			$this->makeAttachment( $this->author2 ),
			$this->makeAttachment( $this->author2, 'image/png' ),
			$this->makeAttachment( $this->author2, 'image/jpeg', array( 'post_parent' => $public ) ),
			$this->makeAttachment( $this->author2, 'image/jpeg', array( 'post_parent' => $private ) ),
			$this->makeAttachment( $this->author2, 'image/jpeg', array( 'post_parent' => $draft ) ),
			$this->makeAttachment( $this->author, 'image/jpeg', array( 'post_parent' => $draft ) ),
		);
		// Never listed: wrong types and trashed uploads.
		$this->makeAttachment( $this->author, 'application/pdf' );
		$this->makeAttachment( $this->author, 'image/svg+xml' );
		$this->makeAttachment( $this->author, 'image/heic' );
		$trashed = $this->makeAttachment( $this->author );
		wp_update_post(
			array(
				'ID'          => $trashed,
				'post_status' => 'trash',
			)
		);

		$policy = Services::access();
		foreach ( array( $this->author, $this->author2, $this->editor, $this->admin ) as $user ) {
			$expected = array_values( array_filter( $all, static fn( int $id ): bool => $policy->canUseAttachment( new Actor( $user ), $id ) ) );
			$response = $this->call( 'GET', '/media', array( 'per_page' => 100 ), $user );
			$actual   = array_column( $this->data( $response ), 'id' );
			sort( $expected );
			sort( $actual );

			$this->assertSame( $expected, $actual, 'visible set of user ' . $user );
			$this->assertSame( (string) count( $expected ), $response->get_headers()['X-WP-Total'] );
		}
		// Authors: own uploads, unattached (public `inherit` status) and published-parent attachments; not private or draft parents.
		$this->assertCount( 6, $this->data( $this->call( 'GET', '/media', array(), $this->author ) ) );
		$this->assertCount( 8, $this->data( $this->call( 'GET', '/media', array(), $this->editor ) ) );
	}

	public function test_paging_headers_bounds_and_order(): void {
		foreach ( array( 'Charlie', 'alpha', 'Bravo', 'delta', 'Echo' ) as $title ) {
			$this->makeAttachment( $this->author, 'image/jpeg', array( 'post_title' => $title ) );
		}

		$page1 = $this->call( 'GET', '/media', array( 'per_page' => 2, 'page' => 1, 'orderby' => 'title', 'order' => 'asc' ), $this->author );
		$page3 = $this->call( 'GET', '/media', array( 'per_page' => 2, 'page' => 3, 'orderby' => 'title', 'order' => 'asc' ), $this->author );
		$past  = $this->call( 'GET', '/media', array( 'per_page' => 2, 'page' => 9 ), $this->author );

		$this->assertSame( array( 'alpha', 'Bravo' ), array_column( $this->data( $page1 ), 'title' ) );
		$this->assertSame( array( 'Echo' ), array_column( $this->data( $page3 ), 'title' ) );
		$this->assertSame( '5', $page1->get_headers()['X-WP-Total'] );
		$this->assertSame( '3', $page1->get_headers()['X-WP-TotalPages'] );
		$this->assertSame( 200, $past->get_status() );
		$this->assertSame( array(), $this->data( $past ) );
		$this->assertSame( '5', $past->get_headers()['X-WP-Total'] );

		$desc = $this->call( 'GET', '/media', array( 'orderby' => 'title', 'order' => 'desc' ), $this->author );
		$this->assertSame( 'Echo', $this->data( $desc )[0]['title'] );
		$this->assertCount( 5, $this->data( $this->call( 'GET', '/media', array(), $this->author ) ), 'default page size 40' );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function invalid_queries(): array {
		return array(
			'per_page too high' => array( array( 'per_page' => 101 ) ),
			'per_page zero'     => array( array( 'per_page' => 0 ) ),
			'page zero'         => array( array( 'page' => 0 ) ),
			'unknown orderby'   => array( array( 'orderby' => 'filename' ) ),
			'unknown order'     => array( array( 'order' => 'sideways' ) ),
			'uploadedBy zero'   => array( array( 'uploadedBy' => 0 ) ),
			'search too long'   => array( array( 'search' => str_repeat( 'a', 201 ) ) ),
		);
	}

	/**
	 * @dataProvider invalid_queries
	 * @param array<string, mixed> $query Query parameters.
	 */
	public function test_invalid_queries_are_schema_errors( array $query ): void {
		$response = $this->call( 'GET', '/media', $query, $this->admin );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->as_error()->get_error_code() );
	}

	public function test_search_matches_the_title(): void {
		$this->makeAttachment( $this->author, 'image/jpeg', array( 'post_title' => 'Sunset over the sea' ) );
		$this->makeAttachment( $this->author, 'image/jpeg', array( 'post_title' => 'Mountain' ) );

		$data = $this->data( $this->call( 'GET', '/media', array( 'search' => 'sunset' ), $this->author ) );

		$this->assertSame( array( 'Sunset over the sea' ), array_column( $data, 'title' ) );
	}

	public function test_uploaded_by_filters_for_privileged_users_and_is_forced_to_the_caller_for_others(): void {
		$mine   = $this->makeAttachment( $this->author );
		$theirs = $this->makeAttachment( $this->author2 );

		$as_editor = array_column( $this->data( $this->call( 'GET', '/media', array( 'uploadedBy' => $this->author2 ), $this->editor ) ), 'id' );
		$this->assertSame( array( $theirs ), $as_editor );

		$as_author = array_column( $this->data( $this->call( 'GET', '/media', array( 'uploadedBy' => $this->author2 ), $this->author ) ), 'id' );
		$this->assertSame( array( $mine ), $as_author, 'an author cannot browse another user through the filter' );
	}

	public function test_the_resource_shape_of_a_real_upload(): void {
		$id = $this->realAttachment( $this->author, 'Canola field' );
		update_post_meta( $id, '_wp_attachment_image_alt', 'Yellow flowers' );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_excerpt' => 'A caption',
			)
		);

		$response = $this->call( 'GET', '/media', array(), $this->author );
		$item     = $this->data( $response )[0];

		$this->assertSame(
			array( 'id', 'title', 'alt', 'caption', 'mimeType', 'width', 'height', 'thumbnailUrl', 'fullUrl', 'uploadedAt', 'uploadedBy', 'usedInGalleries' ),
			array_keys( $item )
		);
		$this->assertSame( $id, $item['id'] );
		$this->assertSame( 'Canola field', $item['title'] );
		$this->assertSame( 'Yellow flowers', $item['alt'] );
		$this->assertSame( 'A caption', $item['caption'] );
		$this->assertSame( 'image/jpeg', $item['mimeType'] );
		$this->assertGreaterThan( 0, $item['width'] );
		$this->assertGreaterThan( 0, $item['height'] );
		$this->assertStringStartsWith( 'http', $item['thumbnailUrl'] );
		$this->assertStringStartsWith( 'http', $item['fullUrl'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $item['uploadedAt'] );
		$this->assertSame( $this->author, $item['uploadedBy'] );
		$this->assertSame( 0, $item['usedInGalleries'] );

		$body = (string) wp_json_encode( $this->data( $response ) );
		foreach ( array( ABSPATH, WP_CONTENT_DIR, '_wp_attached_file', 'wp_posts' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $body );
		}
	}

	public function test_items_without_files_still_serialize_without_paths(): void {
		$this->makeAttachment( $this->author );

		$item = $this->data( $this->call( 'GET', '/media', array(), $this->author ) )[0];

		$this->assertSame( 0, $item['width'] );
		$this->assertSame( 0, $item['height'] );
		foreach ( array( $item['fullUrl'], $item['thumbnailUrl'] ) as $url ) {
			$this->assertTrue( null === $url || ( is_string( $url ) && ! str_contains( $url, ABSPATH ) ) );
		}
	}

	public function test_used_in_galleries_counts_live_galleries_only(): void {
		global $wpdb;
		$image   = $this->makeAttachment( $this->author );
		$unused  = $this->makeAttachment( $this->author );
		$one     = $this->makeGallery( $this->author );
		$two     = $this->makeGallery( $this->author2 );
		$trashed = $this->makeGallery( $this->author );
		wp_trash_post( $trashed );
		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( array( $one, $two, $trashed ) as $position => $gallery ) {
			$wpdb->insert(
				Schema::items_table(),
				array(
					'gallery_id'    => $gallery,
					'attachment_id' => $image,
					'position'      => $position + 1,
					'created_at'    => $now,
					'updated_at'    => $now,
				)
			);
		}

		$items = array();
		foreach ( $this->data( $this->call( 'GET', '/media', array(), $this->author ) ) as $item ) {
			$items[ $item['id'] ] = $item['usedInGalleries'];
		}

		$this->assertSame( 2, $items[ $image ] );
		$this->assertSame( 0, $items[ $unused ] );
	}

	public function test_the_number_of_queries_does_not_grow_with_the_page_size(): void {
		global $wpdb;
		$public = $this->makePost( 'publish', $this->author2 );
		$gallery = $this->makeGallery( $this->author );
		$now     = gmdate( 'Y-m-d H:i:s' );

		$measure = function ( int $per_page, int $user ) use ( $wpdb ): int {
			wp_cache_flush();
			$before = $wpdb->num_queries;
			$status = $this->call( 'GET', '/media', array( 'per_page' => $per_page ), $user )->get_status();
			$this->assertSame( 200, $status );
			return $wpdb->num_queries - $before;
		};

		for ( $i = 0; $i < 5; ++$i ) {
			$id = $this->makeAttachment( $this->author2, 'image/jpeg', array( 'post_parent' => $public ) );
			$this->relate( $gallery, $id, $i + 1, $now );
		}
		$small = array(
			$measure( 5, $this->author ),
			$measure( 5, $this->admin ),
		);
		for ( $i = 5; $i < 40; ++$i ) {
			$id = $this->makeAttachment( $i % 2 ? $this->author : $this->author2, 'image/jpeg', array( 'post_parent' => $i % 3 ? $public : 0 ) );
			$this->relate( $gallery, $id, $i + 1, $now );
		}
		$large = array(
			$measure( 40, $this->author ),
			$measure( 40, $this->admin ),
		);

		$this->assertSame( $small, $large, 'queries per request must not depend on the number of rows' );
		$this->assertLessThan( 30, $large[0] );
	}

	private function relate( int $gallery, int $attachment, int $position, string $now ): void {
		global $wpdb;
		$wpdb->insert(
			Schema::items_table(),
			array(
				'gallery_id'    => $gallery,
				'attachment_id' => $attachment,
				'position'      => $position,
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
	}

	private function makePost( string $status, int $author ): int {
		$id = wp_insert_post(
			array(
				'post_title'  => 'Parent',
				'post_status' => $status,
				'post_author' => $author,
			)
		);
		$this->assertIsInt( $id );
		$this->posts[] = $id;
		return $id;
	}

	/** An attachment with a real file and generated sub-sizes (WordPress test image). */
	private function realAttachment( int $owner, string $title ): int {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $id );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_author' => $owner,
				'post_title'  => $title,
			)
		);
		$this->posts[] = $id;
		return $id;
	}
}
