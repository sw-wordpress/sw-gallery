<?php
/**
 * Hostile input against the REST API (M5-04 c, d): object-level checks (IDOR through item ids of another gallery,
 * non-attachment ids, trashed galleries, private attachments), schema limits (sizes, counts, types, overflow, depth)
 * and stored-text sanitising. A refused request must change nothing.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\Tests\Support\RestItemFixtures;
use SWGallery\WordPress\Infrastructure\Services;

class InputValidationTest extends SwgTestCase {

	use RestItemFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->bootRest();
	}

	public function tear_down(): void {
		$this->shutdownRest();
		parent::tear_down();
	}

	/**
	 * Sends a raw JSON body (so values that PHP arrays cannot express, like a depth bomb, are possible).
	 */
	private function raw( string $method, string $route, string $json, int $user, array $query = array() ): WP_REST_Response {
		wp_set_current_user( $user );
		$request = new WP_REST_Request( $method, '/swg/v1' . $route );
		$request->set_query_params( $query );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( $json );
		return rest_do_request( $request );
	}

	// ---------------------------------------------------------------- object level (IDOR)

	public function test_item_ids_of_another_gallery_are_never_touched(): void {
		$owner = $this->makeUser( 'author' );
		$a     = $this->makeGallery( $owner, GalleryStatus::Draft, 'A' );
		$b     = $this->makeGallery( $owner, GalleryStatus::Draft, 'B' );
		$this->seedItems( $a, $owner, 2 );
		$this->seedItems( $b, $owner, 2 );
		$foreign = $this->itemIds( $b );
		$rev_a   = $this->revisionOf( $a );
		$rev_b   = $this->revisionOf( $b );

		$patch = $this->call( 'PATCH', "/galleries/{$a}/items/{$foreign[0]}", array( 'overrides' => array( 'title' => 'Hijacked' ), 'expectedRevision' => $rev_a ), $owner );
		$this->assertSame( 404, $patch->get_status() );
		$this->assertSame( 'swg_item_not_found', $patch->get_data()['code'] );
		$delete = $this->call( 'DELETE', "/galleries/{$a}/items/{$foreign[0]}", array(), $owner, array( 'expectedRevision' => $rev_a ) );
		$this->assertSame( 404, $delete->get_status() );
		$bulk = $this->call( 'POST', "/galleries/{$a}/items/bulk-remove", array( 'itemIds' => $foreign, 'expectedRevision' => $rev_a ), $owner );
		$this->assertSame( array(), $bulk->get_data()['removed'] );
		$order = $this->call( 'PUT', "/galleries/{$a}/order", array( 'itemIds' => $foreign, 'expectedRevision' => $rev_a ), $owner );
		$this->assertSame( 422, $order->get_status() );

		$this->assertSame( $foreign, $this->itemIds( $b ), 'the other gallery keeps its items' );
		$this->assertSame( $rev_b, $this->revisionOf( $b ) );
		$this->assertSame( $rev_a, $this->revisionOf( $a ) );
		$items = $this->call( 'GET', "/galleries/{$b}/items", array(), $owner )->get_data();
		$this->assertNull( $items[0]['overrides']['title'], 'no override was written' );
	}

	public function test_a_foreign_gallery_cannot_be_reached_through_a_visible_one(): void {
		$owner    = $this->makeUser( 'author' );
		$stranger = $this->makeUser( 'author' );
		$mine     = $this->makeGallery( $stranger, GalleryStatus::Draft, 'Mine' );
		$theirs   = $this->makeGallery( $owner, GalleryStatus::Draft, 'Theirs' );
		$this->seedItems( $theirs, $owner, 1 );
		$item = $this->itemIds( $theirs )[0];

		// The stranger addresses the foreign item through his own gallery id and through the foreign gallery id.
		$via_mine = $this->call( 'DELETE', "/galleries/{$mine}/items/{$item}", array(), $stranger, array( 'expectedRevision' => 1 ) );
		$this->assertSame( 404, $via_mine->get_status() );
		$via_theirs = $this->call( 'DELETE', "/galleries/{$theirs}/items/{$item}", array(), $stranger, array( 'expectedRevision' => 2 ) );
		$this->assertSame( 404, $via_theirs->get_status() );
		$this->assertSame( array( $item ), $this->itemIds( $theirs ) );
	}

	public function test_only_usable_image_attachments_can_be_assigned(): void {
		$owner     = $this->makeUser( 'author' );
		$other     = $this->makeUser( 'author' );
		$admin     = $this->makeUser( 'administrator' );
		$gallery   = $this->makeGallery( $owner );
		$page      = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$post      = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$secret    = self::factory()->post->create( array( 'post_status' => 'private', 'post_author' => $admin ) );
		$private   = $this->makeAttachment( $admin, 'image/jpeg', array( 'post_parent' => $secret ) );
		$trashed   = $this->makeAttachment( $other, 'image/jpeg', array( 'post_status' => 'trash' ) );
		$pdf       = $this->makeAttachment( $other, 'application/pdf' );
		$svg       = $this->makeAttachment( $other, 'image/svg+xml' );
		$ok        = $this->makeAttachment( $other );
		$ids       = array( $page, $post, $secret, $private, $trashed, $pdf, $svg, $ok, 999999 );

		$response = $this->call( 'POST', "/galleries/{$gallery}/items", array( 'attachmentIds' => $ids, 'expectedRevision' => 1 ), $owner );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( array( $ok ), $data['added'], 'only the readable image was assigned' );
		$this->assertEqualSets( array_diff( $ids, array( $ok ) ), $data['missing'], 'everything else is indistinguishably missing' );
		$this->assertCount( 1, $this->itemIds( $gallery ) );
	}

	public function test_cover_must_be_a_usable_attachment(): void {
		$owner   = $this->makeUser( 'author' );
		$admin   = $this->makeUser( 'administrator' );
		$gallery = $this->makeGallery( $owner );
		$post    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$secret  = self::factory()->post->create( array( 'post_status' => 'private', 'post_author' => $admin ) );
		$private = $this->makeAttachment( $admin, 'image/jpeg', array( 'post_parent' => $secret ) );
		foreach ( array( $post, $private, 999999 ) as $cover ) {
			$response = $this->call( 'PATCH', "/galleries/{$gallery}", array( 'coverAttachmentId' => $cover, 'expectedRevision' => 1 ), $owner );
			$this->assertSame( 422, $response->get_status(), 'cover ' . $cover );
			$this->assertSame( 'swg_invalid_cover', $response->get_data()['code'] );
		}
		$this->assertSame( 1, $this->revisionOf( $gallery ) );
	}

	public function test_item_routes_refuse_a_trashed_gallery(): void {
		$owner   = $this->makeUser( 'author' );
		$gallery = $this->makeGallery( $owner );
		$this->seedItems( $gallery, $owner, 1 );
		Services::gallery_service()->trash( new Actor( $owner ), $gallery, new Revision( $this->revisionOf( $gallery ) ) );
		$revision = $this->revisionOf( $gallery );
		$item     = $this->itemIds( $gallery )[0];
		$extra    = $this->makeAttachment( $owner );

		$attempts = array(
			$this->call( 'POST', "/galleries/{$gallery}/items", array( 'attachmentIds' => array( $extra ), 'expectedRevision' => $revision ), $owner ),
			$this->call( 'PATCH', "/galleries/{$gallery}/items/{$item}", array( 'overrides' => array( 'title' => 'x' ), 'expectedRevision' => $revision ), $owner ),
			$this->call( 'DELETE', "/galleries/{$gallery}/items/{$item}", array(), $owner, array( 'expectedRevision' => $revision ) ),
			$this->call( 'PATCH', "/galleries/{$gallery}", array( 'title' => 'Zombie', 'expectedRevision' => $revision ), $owner ),
		);
		$statuses = array_map( static fn( WP_REST_Response $response ): int => $response->get_status(), $attempts );
		$this->assertSame( array( 409, 409, 409, 409 ), $statuses, 'a trashed gallery is read-only until it is restored' );
		$this->assertSame( array( $item ), $this->itemIds( $gallery ) );
		$this->assertSame( $revision, $this->revisionOf( $gallery ) );
	}

	// ---------------------------------------------------------------- limits and types

	/**
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public static function invalid_gallery_bodies(): array {
		return array(
			'title too long'         => array( 'POST', '/galleries', array( 'title' => str_repeat( 'a', 201 ) ) ),
			'title empty'            => array( 'POST', '/galleries', array( 'title' => '' ) ),
			'title array'            => array( 'POST', '/galleries', array( 'title' => array( 'a' ) ) ),
			'title object'           => array( 'POST', '/galleries', array( 'title' => array( 'a' => 'b' ) ) ),
			'description too long'   => array( 'POST', '/galleries', array( 'title' => 'x', 'description' => str_repeat( 'a', 10001 ) ) ),
			'status trash'           => array( 'POST', '/galleries', array( 'title' => 'x', 'status' => 'trash' ) ),
			'status auto-draft'      => array( 'POST', '/galleries', array( 'title' => 'x', 'status' => 'auto-draft' ) ),
			'status array'           => array( 'POST', '/galleries', array( 'title' => 'x', 'status' => array( 'publish' ) ) ),
			'layout not a string'    => array( 'POST', '/galleries', array( 'title' => 'x', 'layout' => array( 'grid' ) ) ),
			'settings a list'        => array( 'POST', '/galleries', array( 'title' => 'x', 'settings' => 'grid' ) ),
			'sort mode unknown'      => array( 'POST', '/galleries', array( 'title' => 'x', 'sortMode' => 'random; DROP TABLE' ) ),
		);
	}

	/**
	 * @dataProvider invalid_gallery_bodies
	 * @param array<string, mixed> $body Body.
	 */
	public function test_invalid_gallery_bodies_are_refused_without_side_effects( string $method, string $route, array $body ): void {
		$author = $this->makeUser( 'author' );
		$before = count( get_posts( array( 'post_type' => 'swg_gallery', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) );
		$response = $this->call( $method, $route, $body, $author );
		$this->assertContains( $response->get_status(), array( 400, 422 ), wp_json_encode( $response->get_data() ) );
		$after = count( get_posts( array( 'post_type' => 'swg_gallery', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) );
		$this->assertSame( $before, $after );
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function invalid_patch_bodies(): array {
		return array(
			'owner zero'             => array( 'PATCH', array( 'ownerId' => 0, 'expectedRevision' => 1 ) ),
			'owner negative'         => array( 'PATCH', array( 'ownerId' => -5, 'expectedRevision' => 1 ) ),
			'owner overflow'         => array( 'PATCH', array( 'ownerId' => '99999999999999999999', 'expectedRevision' => 1 ) ),
			'owner array'            => array( 'PATCH', array( 'ownerId' => array( 1 ), 'expectedRevision' => 1 ) ),
			'revision negative'      => array( 'PATCH', array( 'title' => 'x', 'expectedRevision' => -1 ) ),
			'revision string'        => array( 'PATCH', array( 'title' => 'x', 'expectedRevision' => 'abc' ) ),
			'revision array'         => array( 'PATCH', array( 'title' => 'x', 'expectedRevision' => array( 1 ) ) ),
			'revision missing'       => array( 'PATCH', array( 'title' => 'x' ) ),
			'cover negative'         => array( 'PATCH', array( 'coverAttachmentId' => -1, 'expectedRevision' => 1 ) ),
			'unknown layout'         => array( 'PATCH', array( 'layout' => '../../etc/passwd', 'expectedRevision' => 1 ) ),
		);
	}

	/**
	 * @dataProvider invalid_patch_bodies
	 * @param array<string, mixed> $body Body.
	 */
	public function test_invalid_patch_bodies_change_nothing( string $method, array $body ): void {
		$author  = $this->makeUser( 'author' );
		$gallery = $this->makeGallery( $author );
		$response = $this->call( $method, "/galleries/{$gallery}", $body, $author );
		$this->assertContains( $response->get_status(), array( 400, 422 ), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 1, $this->revisionOf( $gallery ) );
		$this->assertSame( 'Gallery', get_post( $gallery )->post_title );
		$this->assertSame( $author, (int) get_post( $gallery )->post_author );
	}

	public function test_ids_in_the_path_are_bounded_digits(): void {
		$admin = $this->makeUser( 'administrator' );
		foreach ( array( '0', '-1', '01', 'abc', '99999999999', '1.5', '1e3', '%00', '1%20OR%201=1' ) as $id ) {
			$response = $this->call( 'GET', '/galleries/' . $id, array(), $admin );
			$this->assertSame( 404, $response->get_status(), $id );
			$this->assertSame( 'rest_no_route', $response->get_data()['code'], $id );
		}
	}

	public function test_collection_parameters_are_bounded(): void {
		$admin = $this->makeUser( 'administrator' );
		$gallery = $this->makeGallery( $admin );
		$cases   = array(
			array( '/galleries', array( 'per_page' => 101 ) ),
			array( '/galleries', array( 'per_page' => 0 ) ),
			array( '/galleries', array( 'per_page' => -1 ) ),
			array( '/galleries', array( 'per_page' => 'abc' ) ),
			array( '/galleries', array( 'page' => 100001 ) ),
			array( '/galleries', array( 'page' => 0 ) ),
			array( '/galleries', array( 'page' => array( 1 ) ) ),
			array( '/galleries', array( 'status' => 'publish; DROP TABLE x' ) ),
			array( '/galleries', array( 'owner' => -1 ) ),
			array( '/galleries', array( 'search' => str_repeat( 'a', 5000 ) ) ),
			array( "/galleries/{$gallery}/items", array( 'per_page' => 101 ) ),
			array( "/galleries/{$gallery}/items", array( 'page' => 2147483648 ) ),
			array( '/media', array( 'per_page' => 101 ) ),
			array( '/media', array( 'orderby' => 'ID);DROP TABLE x;--' ) ),
			array( '/media', array( 'order' => 'asc,rand()' ) ),
			array( '/media', array( 'search' => str_repeat( 'a', 201 ) ) ),
			array( '/media', array( 'uploadedBy' => 0 ) ),
		);
		foreach ( $cases as $case ) {
			$response = $this->call( 'GET', $case[0], $case[1], $admin );
			$this->assertSame( 400, $response->get_status(), $case[0] . ' ' . wp_json_encode( $case[1] ) . ' ' . wp_json_encode( $response->get_data() ) );
		}
	}

	public function test_sql_and_like_metacharacters_in_searches_are_data(): void {
		$admin = $this->makeUser( 'administrator' );
		$this->makeGallery( $admin, GalleryStatus::Draft, '100% real_title' );
		$this->makeGallery( $admin, GalleryStatus::Draft, 'plain' );
		foreach ( array( "' OR 1=1 -- ", '%', '_', '\\', '") UNION SELECT user_pass FROM wp_users -- ' ) as $needle ) {
			$response = $this->call( 'GET', '/galleries', array( 'search' => $needle ), $admin );
			$this->assertSame( 200, $response->get_status(), $needle );
			$this->assertStringNotContainsString( '$P$', (string) wp_json_encode( $response->get_data() ) );
			$media = $this->call( 'GET', '/media', array( 'search' => $needle ), $admin );
			$this->assertSame( 200, $media->get_status(), $needle );
		}
		// A lone `%` or `_` is a literal character, not a wildcard (WP_Query escapes it with esc_like).
		$percent = $this->call( 'GET', '/galleries', array( 'search' => '%' ), $admin )->get_data();
		$this->assertCount( 1, $percent, 'only the title containing a percent sign matches "%"' );
		$under = $this->call( 'GET', '/galleries', array( 'search' => '_' ), $admin )->get_data();
		$this->assertCount( 1, $under, 'only the title containing an underscore matches "_"' );
	}

	public function test_batch_and_order_sizes_are_capped(): void {
		$author  = $this->makeUser( 'author' );
		$gallery = $this->makeGallery( $author );
		$ids501  = range( 1, 501 );
		$cases   = array(
			array( 'POST', "/galleries/{$gallery}/items", array( 'attachmentIds' => $ids501, 'expectedRevision' => 1 ) ),
			array( 'POST', "/galleries/{$gallery}/items", array( 'attachmentIds' => array(), 'expectedRevision' => 1 ) ),
			array( 'POST', "/galleries/{$gallery}/items", array( 'attachmentIds' => array( -1 ), 'expectedRevision' => 1 ) ),
			array( 'POST', "/galleries/{$gallery}/items", array( 'attachmentIds' => array( 'a' ), 'expectedRevision' => 1 ) ),
			array( 'POST', "/galleries/{$gallery}/items", array( 'attachmentIds' => array( array( 1 ) ), 'expectedRevision' => 1 ) ),
			array( 'POST', "/galleries/{$gallery}/items/bulk-remove", array( 'itemIds' => $ids501, 'expectedRevision' => 1 ) ),
			array( 'PUT', "/galleries/{$gallery}/order", array( 'itemIds' => range( 1, 10001 ), 'expectedRevision' => 1 ) ),
			array( 'PUT', "/galleries/{$gallery}/order", array( 'itemIds' => array( 0 ), 'expectedRevision' => 1 ) ),
		);
		foreach ( $cases as $index => $case ) {
			$response = $this->call( $case[0], $case[1], $case[2], $author );
			$this->assertSame( 400, $response->get_status(), 'case ' . $index . ' ' . wp_json_encode( $response->get_data() ) );
		}
		$this->assertSame( 1, $this->revisionOf( $gallery ) );
	}

	public function test_json_depth_bombs_and_garbage_bodies_are_bad_requests(): void {
		$author  = $this->makeUser( 'author' );
		$gallery = $this->makeGallery( $author );
		$deep    = str_repeat( '[', 100000 ) . str_repeat( ']', 100000 );
		$bomb    = '{"title":"x","settings":' . str_repeat( '{"a":', 1000 ) . '1' . str_repeat( '}', 1000 ) . '}';
		foreach ( array( $deep, $bomb, '{"title":', "\x00\x01", '"just a string"', '[]' ) as $json ) {
			$response = $this->raw( 'POST', '/galleries', $json, $author );
			$this->assertContains( $response->get_status(), array( 400, 422 ), substr( $json, 0, 30 ) );
		}
		$response = $this->raw( 'PATCH', "/galleries/{$gallery}", $bomb, $author );
		$this->assertContains( $response->get_status(), array( 400, 422 ) );
		$this->assertSame( 1, $this->revisionOf( $gallery ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function deep( int $levels ): array {
		$value = array( 'leaf' => 1 );
		for ( $i = 0; $i < $levels; ++$i ) {
			$value = array( 'n' => $value );
		}
		return $value;
	}

	public function test_settings_are_bounded_in_size_depth_and_key_count(): void {
		$author = $this->makeUser( 'author' );
		$many   = array();
		for ( $i = 0; $i < 3000; ++$i ) {
			$many[ 'k' . $i ] = str_repeat( 'x', 100 );
		}
		foreach ( array( $many, array( 'columns' => str_repeat( 'x', 100000 ) ), array( 'columns' => 10 ** 15 ), array( 'gap' => array( 'base' => 'x' ) ), self::deep( 12 ) ) as $settings ) {
			$response = $this->call( 'POST', '/galleries', array( 'title' => 'x', 'settings' => $settings ), $author );
			$this->assertContains( $response->get_status(), array( 400, 422 ), substr( (string) wp_json_encode( $settings ), 0, 60 ) . ' ' . wp_json_encode( $response->get_data() ) );
		}
	}

	// ---------------------------------------------------------------- stored text

	public function test_markup_is_never_stored_in_titles_and_overrides(): void {
		$author  = $this->makeUser( 'author' );
		$gallery = $this->makeGallery( $author );
		$this->seedItems( $gallery, $author, 1 );
		$item    = $this->itemIds( $gallery )[0];
		$hostile = '<script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)">x</a>"\'';

		$response = $this->call( 'PATCH', "/galleries/{$gallery}", array( 'title' => $hostile, 'description' => $hostile, 'expectedRevision' => $this->revisionOf( $gallery ) ), $author );
		$this->assertSame( 200, $response->get_status() );
		$post = get_post( $gallery );
		foreach ( array( $post->post_title, $post->post_content, $post->post_excerpt ) as $stored ) {
			$this->assertStringNotContainsString( '<', $stored );
		}
		$response = $this->call( 'PATCH', "/galleries/{$gallery}/items/{$item}", array( 'overrides' => array( 'title' => $hostile, 'caption' => $hostile, 'alt' => $hostile ), 'expectedRevision' => $this->revisionOf( $gallery ) ), $author );
		$this->assertSame( 200, $response->get_status() );
		$overrides = $response->get_data()['item']['overrides'];
		foreach ( $overrides as $value ) {
			$this->assertStringNotContainsString( '<', (string) $value );
		}
	}

	public function test_a_contributor_cannot_publish_and_ownership_transfer_is_characterised(): void {
		$contributor = $this->makeUser( 'contributor' );
		$victim      = $this->makeUser( 'administrator' );
		$gallery     = $this->makeGallery( $contributor );

		$publish = $this->call( 'PATCH', "/galleries/{$gallery}", array( 'status' => 'publish', 'expectedRevision' => 1 ), $contributor );
		$this->assertSame( 403, $publish->get_status() );
		$create = $this->call( 'POST', '/galleries', array( 'title' => 'x', 'status' => 'private' ), $contributor );
		$this->assertSame( 403, $create->get_status() );

		// OPEN FINDING S-04 (docs/security.md): decision "changing the owner needs canEdit and canDelete" lets every
		// owner give a gallery to any existing user. Characterised, not changed: the owner needs a product decision.
		$give = $this->call( 'PATCH', "/galleries/{$gallery}", array( 'ownerId' => $victim, 'expectedRevision' => 1 ), $contributor );
		$this->assertSame( 200, $give->get_status() );
		$this->assertSame( $victim, (int) get_post( $gallery )->post_author );
	}
}
