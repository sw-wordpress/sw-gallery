<?php
/**
 * PATCH/DELETE /swg/v1/galleries/{id}/items/{itemId}, POST .../items/bulk-remove and PUT .../order.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Tests\Support\RestItemFixtures;
use SWGallery\Tests\Support\StubLayout;
use SWGallery\WordPress\Infrastructure\Services;

class RestItemEditTest extends SwgTestCase {

	use RestItemFixtures;

	private int $admin;
	private int $author;
	private int $author2;

	public function set_up(): void {
		parent::set_up();
		$this->bootRest();
		$this->admin   = $this->makeUser( 'administrator' );
		$this->author  = $this->makeUser( 'author' );
		$this->author2 = $this->makeUser( 'author' );
	}

	public function tear_down(): void {
		$this->shutdownRest();
		parent::tear_down();
	}

	/**
	 * A gallery with $count items; returns the gallery id, the item ids and the attachment ids.
	 *
	 * @return array{0: int, 1: list<int>, 2: list<int>}
	 */
	private function gallery_with_items( int $count, int $owner = 0 ): array {
		$owner       = 0 === $owner ? $this->author : $owner;
		$gallery     = $this->makeGallery( $owner );
		$attachments = $this->seedItems( $gallery, $owner, $count );

		return array( $gallery, $this->itemIds( $gallery ), $attachments );
	}

	/** @return array<string, mixed> The listed item as the admin app would see it. */
	private function listed( int $gallery, int $item, int $user = 0 ): array {
		$rows = $this->call( 'GET', '/galleries/' . $gallery . '/items', array( 'per_page' => 100 ), 0 === $user ? $this->author : $user )->get_data();
		foreach ( $rows as $row ) {
			if ( $row['id'] === $item ) {
				return $row;
			}
		}
		$this->fail( 'Item not listed.' );
	}

	private function patch( int $gallery, int $item, array $body, ?int $revision = null, int $user = 0 ): WP_REST_Response {
		$body['expectedRevision'] ??= $revision ?? $this->revisionOf( $gallery );

		return $this->call( 'PATCH', '/galleries/' . $gallery . '/items/' . $item, $body, 0 === $user ? $this->author : $user );
	}

	// Overrides.

	public function test_patch_sets_overrides_and_returns_the_item_and_revision(): void {
		list( $gallery, $items ) = $this->gallery_with_items( 2 );
		$revision                = $this->revisionOf( $gallery );

		$response = $this->patch( $gallery, $items[0], array( 'overrides' => array( 'title' => 'Mine' ) ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $revision + 1, $data['revision'] );
		$this->assertSame( $revision + 1, $this->revisionOf( $gallery ) );
		$this->assertSame( $items[0], $data['item']['id'] );
		$this->assertSame( array( 'title' => 'Mine', 'caption' => null, 'alt' => null ), $data['item']['overrides'] );
		$this->assertSame( 'Mine', $data['item']['effective']['title'] );
		$this->assertNull( $this->listed( $gallery, $items[1] )['overrides']['title'], 'the other item is untouched' );
	}

	public function test_partial_patch_keeps_fields_that_are_not_sent_and_null_differs_from_an_empty_string(): void {
		list( $gallery, $items, $attachments ) = $this->gallery_with_items( 1 );
		$item                                  = $items[0];
		update_post_meta( $attachments[0], '_wp_attachment_image_alt', 'Global alt' );
		$this->patch( $gallery, $item, array( 'overrides' => array( 'title' => 'T', 'caption' => 'C' ) ) );

		// Only alt is sent: title and caption keep their stored values; '' is an intentional blank.
		$data = $this->patch( $gallery, $item, array( 'overrides' => array( 'alt' => '' ) ) )->get_data()['item'];
		$this->assertSame( array( 'title' => 'T', 'caption' => 'C', 'alt' => '' ), $data['overrides'] );
		$this->assertSame( '', $data['effective']['alt'], 'an intentional blank beats the global alt text' );

		// null resets exactly that field to "inherit".
		$data = $this->patch( $gallery, $item, array( 'overrides' => array( 'title' => null ) ) )->get_data()['item'];
		$this->assertSame( array( 'title' => null, 'caption' => 'C', 'alt' => '' ), $data['overrides'] );
		$this->assertSame( $data['global']['title'], $data['effective']['title'] );

		$data = $this->patch( $gallery, $item, array( 'overrides' => array( 'alt' => null ) ) )->get_data()['item'];
		$this->assertSame( 'Global alt', $data['effective']['alt'] );

		$stored = $this->listed( $gallery, $item );
		$this->assertSame( array( 'title' => null, 'caption' => 'C', 'alt' => null ), $stored['overrides'] );
		$this->assertSame( 'Global alt', get_post_meta( $attachments[0], '_wp_attachment_image_alt', true ), 'the attachment is never changed' );
	}

	public function test_hostile_text_is_stored_as_plain_text(): void {
		list( $gallery, $items ) = $this->gallery_with_items( 1 );

		$data = $this->patch(
			$gallery,
			$items[0],
			array(
				'overrides' => array(
					'title'   => '<script>alert(1)</script>Title <b>bold</b>',
					'caption' => "Line one\n<img src=x onerror=alert(1)>Line two",
					'alt'     => '"><svg onload=alert(1)>',
				),
			)
		)->get_data()['item']['overrides'];

		$this->assertSame( 'Title bold', $data['title'] );
		$this->assertSame( "Line one\nLine two", $data['caption'] );
		foreach ( $data as $text ) {
			$this->assertStringNotContainsString( '<', (string) $text );
			$this->assertStringNotContainsString( 'alert(1)', (string) $text );
		}
		$this->assertSame( $data, $this->listed( $gallery, $items[0] )['overrides'] );
	}

	public function test_overrides_are_validated(): void {
		list( $gallery, $items ) = $this->gallery_with_items( 1 );
		$revision                = $this->revisionOf( $gallery );

		foreach ( array(
			array( 'overrides' => array( 'title' => str_repeat( 'a', 201 ) ) ),
			array( 'overrides' => array( 'caption' => str_repeat( 'a', 5001 ) ) ),
			array( 'overrides' => array( 'alt' => str_repeat( 'a', 501 ) ) ),
			array( 'overrides' => array( 'title' => 5 ) ),
			array( 'overrides' => array( 'unknown' => 'x' ) ),
			array( 'overrides' => 'x' ),
			array( 'settings' => 'x' ),
			array( 'settings' => array( 'a', 'b' ) ),
			array( 'overrides' => array( 'title' => 'x' ), 'expectedRevision' => -1 ),
		) as $body ) {
			$this->assertSame( 400, $this->patch( $gallery, $items[0], $body )->get_status(), (string) wp_json_encode( $body ) );
		}
		$this->assertSame( $revision, $this->revisionOf( $gallery ) );
		$at_limit = array( 'title' => str_repeat( 'a', 200 ), 'caption' => str_repeat( 'b', 5000 ), 'alt' => str_repeat( 'c', 500 ) );
		$this->assertSame( 200, $this->patch( $gallery, $items[0], array( 'overrides' => $at_limit ) )->get_status() );
	}

	public function test_a_patch_without_changes_is_rejected(): void {
		list( $gallery, $items ) = $this->gallery_with_items( 1 );
		$revision                = $this->revisionOf( $gallery );

		foreach ( array( array(), array( 'overrides' => array() ) ) as $body ) {
			$response = $this->patch( $gallery, $items[0], $body );
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'swg_no_changes', $response->get_data()['code'] );
		}
		$this->assertSame( $revision, $this->revisionOf( $gallery ) );
	}

	public function test_patch_with_a_stale_revision_answers_409_and_writes_nothing(): void {
		list( $gallery, $items ) = $this->gallery_with_items( 1 );

		$response = $this->patch( $gallery, $items[0], array( 'overrides' => array( 'title' => 'Late' ) ), 99 );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'swg_revision_conflict', $response->get_data()['code'] );
		$this->assertNull( $this->listed( $gallery, $items[0] )['overrides']['title'] );
	}

	public function test_unknown_items_and_items_of_other_galleries_answer_404(): void {
		list( $gallery, $items )     = $this->gallery_with_items( 1 );
		list( $other, $other_items ) = $this->gallery_with_items( 1 );

		foreach ( array( 987654, $other_items[0] ) as $item ) {
			$patch = $this->patch( $gallery, $item, array( 'overrides' => array( 'title' => 'x' ) ) );
			$this->assertSame( 404, $patch->get_status() );
			$this->assertSame( 'swg_item_not_found', $patch->get_data()['code'] );
			$delete = $this->call( 'DELETE', '/galleries/' . $gallery . '/items/' . $item, array(), $this->author, array( 'expectedRevision' => $this->revisionOf( $gallery ) ) );
			$this->assertSame( 404, $delete->get_status() );
		}
		$this->assertNull( $this->listed( $other, $other_items[0] )['overrides']['title'] );
		$this->assertSame( 1, Services::items()->count( $other ) );
		$this->assertSame( $items, $this->itemIds( $gallery ) );
	}

	// Settings.

	private function gallery_with_item_schema(): int {
		Services::layouts()->register(
			new StubLayout(
				'itemtest',
				array(
					'type'            => 'object',
					'x-item-settings' => array(
						'type'       => 'object',
						'properties' => array(
							'focus' => array(
								'type'    => 'string',
								'enum'    => array( 'center', 'top' ),
								'default' => 'center',
							),
						),
					),
				)
			)
		);
		$created = $this->call( 'POST', '/galleries', array( 'title' => 'Schema', 'layout' => 'itemtest' ), $this->author );
		$this->assertSame( 201, $created->get_status() );
		$gallery = $created->get_data()['id'];
		$this->seedItems( $gallery, $this->author, 1 );

		return $gallery;
	}

	public function test_settings_are_validated_against_the_layout_item_schema(): void {
		$gallery = $this->gallery_with_item_schema();
		$item    = $this->itemIds( $gallery )[0];

		$ok = $this->patch( $gallery, $item, array( 'settings' => array( 'focus' => 'top' ) ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( 'top', $ok->get_data()['item']['settings']->focus );
		$this->assertSame( 'top', $this->listed( $gallery, $item )['settings']->focus );

		$revision = $this->revisionOf( $gallery );
		$bad      = $this->patch( $gallery, $item, array( 'settings' => array( 'focus' => 'sideways' ) ) );
		$this->assertSame( 422, $bad->get_status() );
		$this->assertSame( 'swg_validation_failed', $bad->get_data()['code'] );
		$this->assertSame( 'top', $this->listed( $gallery, $item )['settings']->focus );
		$this->assertSame( $revision, $this->revisionOf( $gallery ) );

		$reset = $this->patch( $gallery, $item, array( 'settings' => (object) array() ) );
		$this->assertSame( 'center', $reset->get_data()['item']['settings']->focus, 'schema defaults are applied' );
	}

	public function test_a_layout_without_an_item_schema_stores_settings_untouched(): void {
		list( $gallery, $items ) = $this->gallery_with_items( 1 );
		update_post_meta( $gallery, \SWGallery\WordPress\PostTypes::META_LAYOUT, 'masonry' );

		$data = $this->patch( $gallery, $items[0], array( 'settings' => array( 'anything' => array( 'nested' => 1 ) ) ) )->get_data()['item'];

		$this->assertSame( 1, $data['settings']->anything['nested'] );
		$this->assertSame( 200, $this->patch( $gallery, $items[0], array( 'settings' => (object) array() ) )->get_status() );
		$this->assertEquals( (object) array(), $this->listed( $gallery, $items[0] )['settings'] );
	}

	public function test_overrides_and_settings_in_one_patch_share_a_revision_and_invalid_settings_write_nothing(): void {
		$gallery  = $this->gallery_with_item_schema();
		$item     = $this->itemIds( $gallery )[0];
		$revision = $this->revisionOf( $gallery );

		$bad = $this->patch( $gallery, $item, array( 'overrides' => array( 'title' => 'Half' ), 'settings' => array( 'focus' => 'nope' ) ) );
		$this->assertSame( 422, $bad->get_status() );
		$this->assertNull( $this->listed( $gallery, $item )['overrides']['title'] );
		$this->assertSame( $revision, $this->revisionOf( $gallery ) );

		$both = $this->patch( $gallery, $item, array( 'overrides' => array( 'title' => 'Both' ), 'settings' => array( 'focus' => 'top' ) ) );
		$this->assertSame( 200, $both->get_status() );
		$this->assertSame( $revision + 1, $both->get_data()['revision'], 'one revision for both parts' );
		$this->assertSame( 'Both', $both->get_data()['item']['overrides']['title'] );
		$this->assertSame( 'top', $both->get_data()['item']['settings']->focus );
	}

	public function test_item_edit_uses_the_item_id_in_the_path(): void {
		list( $gallery, $items ) = $this->gallery_with_items( 2 );
		$patch = array( 'itemId' => $items[1], 'overrides' => array( 'title' => 'Updated' ) );

		$response = $this->patch( $gallery, $items[0], $patch );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Updated', $this->listed( $gallery, $items[0] )['overrides']['title'] );
		$this->assertNull( $this->listed( $gallery, $items[1] )['overrides']['title'] );
	}

	// Remove.

	public function test_delete_removes_only_the_relation(): void {
		list( $gallery, $items, $attachments ) = $this->gallery_with_items( 3 );
		$revision                              = $this->revisionOf( $gallery );

		$response = $this->call( 'DELETE', '/galleries/' . $gallery . '/items/' . $items[1], array(), $this->author, array( 'expectedRevision' => $revision ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'id'       => $items[1],
				'removed'  => true,
				'revision' => $revision + 1,
			),
			$response->get_data()
		);
		$this->assertSame( array( $items[0], $items[2] ), $this->itemIds( $gallery ) );
		foreach ( $attachments as $attachment ) {
			$this->assertSame( 'attachment', get_post_type( $attachment ) );
			$this->assertNotSame( 'trash', get_post_status( $attachment ) );
		}
		$again = $this->call( 'DELETE', '/galleries/' . $gallery . '/items/' . $items[1], array(), $this->author, array( 'expectedRevision' => $revision + 1 ) );
		$this->assertSame( 404, $again->get_status() );
		$this->assertSame( $revision + 1, $this->revisionOf( $gallery ), 'a failed removal does not move the revision' );
	}

	public function test_delete_accepts_the_revision_in_the_body_and_rejects_a_stale_one(): void {
		list( $gallery, $items ) = $this->gallery_with_items( 2 );

		$stale = $this->call( 'DELETE', '/galleries/' . $gallery . '/items/' . $items[0], array( 'expectedRevision' => 1 ), $this->author );
		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 2, Services::items()->count( $gallery ) );

		$ok = $this->call( 'DELETE', '/galleries/' . $gallery . '/items/' . $items[0], array( 'expectedRevision' => $this->revisionOf( $gallery ) ), $this->author );
		$this->assertSame( 200, $ok->get_status() );
		$missing = $this->call( 'DELETE', '/galleries/' . $gallery . '/items/' . $items[1] );
		$this->assertSame( 400, $missing->get_status(), 'expectedRevision is required' );
	}

	public function test_bulk_remove_reports_removed_and_unknown_ids(): void {
		list( $gallery, $items, $attachments ) = $this->gallery_with_items( 4 );
		$revision                              = $this->revisionOf( $gallery );

		$response = $this->call(
			'POST',
			'/galleries/' . $gallery . '/items/bulk-remove',
			array(
				'itemIds'          => array( $items[0], $items[2], 987654, $items[0] ),
				'expectedRevision' => $revision,
			),
			$this->author
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'removed'  => array( $items[0], $items[2] ),
				'notFound' => array( 987654 ),
				'revision' => $revision + 1,
			),
			$response->get_data()
		);
		$this->assertSame( array( $items[1], $items[3] ), $this->itemIds( $gallery ) );
		$this->assertSame( $attachments, array_map( 'get_post_field', array_fill( 0, 4, 'ID' ), $attachments ) );

		$none = $this->call( 'POST', '/galleries/' . $gallery . '/items/bulk-remove', array( 'itemIds' => array( 987654 ), 'expectedRevision' => $revision + 1 ), $this->author );
		$this->assertSame( 200, $none->get_status() );
		$this->assertSame( array(), $none->get_data()['removed'] );
		$this->assertSame( $revision + 1, $none->get_data()['revision'] );
	}

	public function test_bulk_remove_is_bounded_and_validated(): void {
		list( $gallery ) = $this->gallery_with_items( 1 );
		$path            = '/galleries/' . $gallery . '/items/bulk-remove';
		$revision        = $this->revisionOf( $gallery );

		foreach ( array(
			array( 'itemIds' => range( 1, 501 ), 'expectedRevision' => $revision ),
			array( 'itemIds' => array(), 'expectedRevision' => $revision ),
			array( 'itemIds' => array( 'x' ), 'expectedRevision' => $revision ),
			array( 'itemIds' => array( 0 ), 'expectedRevision' => $revision ),
			array( 'expectedRevision' => $revision ),
			array( 'itemIds' => array( 1 ) ),
		) as $body ) {
			$this->assertSame( 400, $this->call( 'POST', $path, $body, $this->author )->get_status(), (string) wp_json_encode( $body ) );
		}
		$this->assertSame( 200, $this->call( 'POST', $path, array( 'itemIds' => range( 900000, 900499 ), 'expectedRevision' => $revision ), $this->author )->get_status() );
		$stale = $this->call( 'POST', $path, array( 'itemIds' => array( 5 ), 'expectedRevision' => 99 ), $this->author );
		$this->assertSame( 200, $stale->get_status(), 'nothing to remove: no revision claim, so no conflict' );
		$this->assertSame( 1, Services::items()->count( $gallery ) );
	}

	public function test_bulk_remove_with_a_stale_revision_removes_nothing(): void {
		list( $gallery, $items ) = $this->gallery_with_items( 2 );

		$response = $this->call( 'POST', '/galleries/' . $gallery . '/items/bulk-remove', array( 'itemIds' => $items, 'expectedRevision' => 99 ), $this->author );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( $items, $this->itemIds( $gallery ) );
	}

	// Order.

	public function test_reorder_applies_the_complete_order_once(): void {
		list( $gallery, $items ) = $this->gallery_with_items( 4 );
		$revision                = $this->revisionOf( $gallery );
		$reversed                = array_reverse( $items );

		$response = $this->call( 'PUT', '/galleries/' . $gallery . '/order', array( 'itemIds' => $reversed, 'expectedRevision' => $revision ), $this->author );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'itemCount' => 4, 'revision' => $revision + 1 ), $response->get_data() );
		$this->assertSame( $reversed, $this->itemIds( $gallery ) );
		$this->assertSame( $revision + 1, $this->revisionOf( $gallery ) );
		$listed = $this->call( 'GET', '/galleries/' . $gallery . '/items', array(), $this->author )->get_data();
		$this->assertSame( $reversed, array_column( $listed, 'id' ) );
		$this->assertSame( array( 1, 2, 3, 4 ), array_column( $listed, 'position' ) );
	}

	public function test_reorder_rejects_everything_that_is_not_an_exact_permutation(): void {
		list( $gallery, $items )     = $this->gallery_with_items( 3 );
		list( $other, $other_items ) = $this->gallery_with_items( 1 );
		$revision                    = $this->revisionOf( $gallery );
		$path                        = '/galleries/' . $gallery . '/order';

		foreach ( array(
			'missing id'      => array( $items[2], $items[0] ),
			'extra id'        => array( $items[2], $items[1], $items[0], 987654 ),
			'duplicate id'    => array( $items[0], $items[0], $items[1] ),
			'foreign item'    => array( $items[0], $items[1], $other_items[0] ),
			'empty'           => array(),
		) as $label => $ids ) {
			$response = $this->call( 'PUT', $path, array( 'itemIds' => $ids, 'expectedRevision' => $revision ), $this->author );
			$this->assertSame( 422, $response->get_status(), $label );
			$this->assertSame( 'swg_invalid_order', $response->get_data()['code'], $label );
		}
		$this->assertSame( $items, $this->itemIds( $gallery ) );
		$this->assertSame( $revision, $this->revisionOf( $gallery ) );
		$this->assertSame( 1, Services::items()->count( $other ) );
	}

	public function test_reorder_with_a_stale_revision_answers_409_and_keeps_the_order(): void {
		list( $gallery, $items ) = $this->gallery_with_items( 2 );

		$response = $this->call( 'PUT', '/galleries/' . $gallery . '/order', array( 'itemIds' => array_reverse( $items ), 'expectedRevision' => 99 ), $this->author );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'swg_revision_conflict', $response->get_data()['code'] );
		$this->assertSame( $items, $this->itemIds( $gallery ) );
	}

	public function test_reorder_validates_the_request_shape(): void {
		list( $gallery, $items ) = $this->gallery_with_items( 2 );
		$path                    = '/galleries/' . $gallery . '/order';
		$revision                = $this->revisionOf( $gallery );

		foreach ( array(
			array( 'itemIds' => array( 'x', 'y' ), 'expectedRevision' => $revision ),
			array( 'itemIds' => range( 1, 10001 ), 'expectedRevision' => $revision ),
			array( 'expectedRevision' => $revision ),
			array( 'itemIds' => $items ),
		) as $body ) {
			$this->assertSame( 400, $this->call( 'PUT', $path, $body, $this->author )->get_status(), (string) wp_json_encode( array_keys( $body ) ) );
		}
		$this->assertSame( $items, $this->itemIds( $gallery ) );
	}

	public function test_reorder_of_an_empty_gallery_with_an_empty_list_is_valid(): void {
		$gallery = $this->makeGallery( $this->author );

		$response = $this->call( 'PUT', '/galleries/' . $gallery . '/order', array( 'itemIds' => array(), 'expectedRevision' => 1 ), $this->author );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $response->get_data()['itemCount'] );
	}

	public function test_reorder_scales_to_a_large_gallery(): void {
		$gallery = $this->makeGallery( $this->author );
		foreach ( range( 1, 300 ) as $attachment ) {
			Services::items()->append( $gallery, 5000 + $attachment );
		}
		$items    = $this->itemIds( $gallery );
		$revision = $this->revisionOf( $gallery );

		$response = $this->call( 'PUT', '/galleries/' . $gallery . '/order', array( 'itemIds' => array_reverse( $items ), 'expectedRevision' => $revision ), $this->author );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array_reverse( $items ), $this->itemIds( $gallery ) );
	}

	public function test_editing_a_gallery_the_user_may_not_edit_changes_nothing(): void {
		$foreign = $this->makeGallery( $this->author2, GalleryStatus::Publish );
		$images  = $this->seedItems( $foreign, $this->author2, 2 );
		$items   = $this->itemIds( $foreign );
		$base    = '/galleries/' . $foreign;
		$rev     = $this->revisionOf( $foreign );

		$responses = array(
			$this->call( 'PATCH', $base . '/items/' . $items[0], array( 'overrides' => array( 'title' => 'x' ), 'expectedRevision' => $rev ), $this->author ),
			$this->call( 'DELETE', $base . '/items/' . $items[0], array(), $this->author, array( 'expectedRevision' => $rev ) ),
			$this->call( 'POST', $base . '/items/bulk-remove', array( 'itemIds' => $items, 'expectedRevision' => $rev ), $this->author ),
			$this->call( 'PUT', $base . '/order', array( 'itemIds' => array_reverse( $items ), 'expectedRevision' => $rev ), $this->author ),
			$this->call( 'POST', $base . '/items', array( 'attachmentIds' => $images, 'expectedRevision' => $rev ), $this->author ),
		);

		foreach ( $responses as $response ) {
			$this->assertSame( 403, $response->get_status() );
			$this->assertSame( 'swg_forbidden', $response->get_data()['code'] );
		}
		$this->assertSame( $items, $this->itemIds( $foreign ) );
		$this->assertSame( $rev, $this->revisionOf( $foreign ) );
		$this->assertNull( $this->listed( $foreign, $items[0], $this->admin )['overrides']['title'] );
	}
}
