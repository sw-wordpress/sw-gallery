<?php
/**
 * /swg/v1/galleries/{id}: read, partial update, trash/delete, duplicate and restore.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\Tests\Support\RestFixtures;
use SWGallery\WordPress\Infrastructure\Services;

class RestGalleryItemTest extends SwgTestCase {

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
		remove_all_filters( 'swg_before_gallery_trashing' );
		remove_all_filters( 'swg_before_gallery_deleting' );
		$this->shutdownRest();
		parent::tear_down();
	}

	// Read.

	public function test_get_returns_the_gallery_with_its_revision_and_item_count(): void {
		$id     = $this->makeGallery( $this->author, GalleryStatus::Draft, 'Mine' );
		$images = array( $this->makeAttachment( $this->author ), $this->makeAttachment( $this->author ) );
		Services::item_service()->assign( new Actor( $this->author ), $id, $images, new Revision( 1 ) );

		$response = $this->call( 'GET', '/galleries/' . $id, array(), $this->author );
		$data     = $this->data( $response );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Mine', $data['title'] );
		$this->assertSame( 2, $data['revision'] );
		$this->assertSame( 2, $data['itemCount'] );
	}

	public function test_a_gallery_the_user_may_not_view_looks_exactly_like_a_missing_one(): void {
		$foreign_draft = $this->makeGallery( $this->author2, GalleryStatus::Draft );

		$hidden  = $this->call( 'GET', '/galleries/' . $foreign_draft, array(), $this->author );
		$missing = $this->call( 'GET', '/galleries/999999', array(), $this->author );

		$this->assertSame( 404, $hidden->get_status() );
		$this->assertSame( $this->data( $missing ), $this->data( $hidden ) );
		foreach ( array(
			array( 'PATCH', '/galleries/%d', array( 'title' => 'x', 'expectedRevision' => 1 ) ),
			array( 'DELETE', '/galleries/%d', array( 'expectedRevision' => 1 ) ),
			array( 'POST', '/galleries/%d/duplicate', array() ),
			array( 'POST', '/galleries/%d/restore', array( 'expectedRevision' => 1 ) ),
		) as $route ) {
			$one = $this->call( $route[0], sprintf( $route[1], $foreign_draft ), $route[2], $this->author );
			$two = $this->call( $route[0], sprintf( $route[1], 999999 ), $route[2], $this->author );
			$this->assertSame( 404, $one->get_status(), $route[0] . ' ' . $route[1] );
			$this->assertSame( $this->data( $two ), $this->data( $one ) );
		}
		$this->assertSame( 'Gallery', get_post( $foreign_draft )->post_title );
	}

	public function test_published_foreign_galleries_are_viewable_but_not_editable(): void {
		$id = $this->makeGallery( $this->author2, GalleryStatus::Publish );

		$data = $this->data( $this->call( 'GET', '/galleries/' . $id, array(), $this->author ) );

		$this->assertSame( $id, $data['id'] );
		$this->assertFalse( $data['canEdit'] );
		$this->assertFalse( $data['canDelete'] );
		$this->assertFalse( $data['canPublish'] );
		$patch = $this->call( 'PATCH', '/galleries/' . $id, array( 'title' => 'Hijack', 'expectedRevision' => 1 ), $this->author );
		$this->assertSame( 403, $patch->get_status() );
		$this->assertSame( 'swg_forbidden', $this->data( $patch )['code'] );
		$this->assertSame( 403, $this->call( 'DELETE', '/galleries/' . $id, array(), $this->author, array( 'expectedRevision' => 1 ) )->get_status() );
		$this->assertSame( 'Gallery', get_post( $id )->post_title );
	}

	public function test_get_status_codes_for_unusual_ids(): void {
		foreach ( array( '0', 'abc', '-1', '12345678901234' ) as $id ) {
			$this->assertSame( 404, $this->call( 'GET', '/galleries/' . $id, array(), $this->admin )->get_status(), $id );
		}
		$other = $this->factory()->post->create( array( 'post_type' => 'post' ) );
		$this->assertSame( 404, $this->call( 'GET', '/galleries/' . $other, array(), $this->admin )->get_status() );
	}

	// Update.

	public function test_patch_updates_fields_and_bumps_the_revision(): void {
		$id = $this->makeGallery( $this->author, GalleryStatus::Draft, 'Old' );

		$response = $this->call(
			'PATCH',
			'/galleries/' . $id,
			array(
				'title'            => 'New <i>title</i>',
				'description'      => 'Plain',
				'layout'           => 'grid',
				'sortMode'         => 'date_desc',
				'settings'         => array( 'columns' => 4 ),
				'expectedRevision' => 1,
			),
			$this->author
		);
		$data     = $this->data( $response );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'New title', $data['title'] );
		$this->assertSame( 'Plain', $data['description'] );
		$this->assertSame( 'date_desc', $data['sortMode'] );
		$this->assertSame( 4, $data['settings']->columns );
		$this->assertSame( 2, $data['revision'] );
		$this->assertSame( 'draft', $data['status'] );
		$this->assertSame( $this->author, $data['ownerId'] );
	}

	public function test_patch_changes_only_what_is_submitted(): void {
		$id = $this->makeGallery( $this->author, GalleryStatus::Draft, 'Keep' );
		$this->call( 'PATCH', '/galleries/' . $id, array( 'description' => 'D', 'sortMode' => 'title_asc', 'expectedRevision' => 1 ), $this->author );

		$data = $this->data( $this->call( 'PATCH', '/galleries/' . $id, array( 'status' => 'pending', 'expectedRevision' => 2 ), $this->author ) );

		$this->assertSame( 'Keep', $data['title'] );
		$this->assertSame( 'D', $data['description'] );
		$this->assertSame( 'title_asc', $data['sortMode'] );
		$this->assertSame( 'pending', $data['status'] );
		$this->assertSame( 3, $data['revision'] );
	}

	public function test_patch_sets_and_clears_the_gallery_page_size(): void {
		$id = $this->makeGallery( $this->author, GalleryStatus::Draft, 'Size' );
		$this->assertNull( $this->data( $this->call( 'GET', '/galleries/' . $id, array(), $this->author ) )['perPage'] );

		$data = $this->data( $this->call( 'PATCH', '/galleries/' . $id, array( 'perPage' => 30, 'expectedRevision' => 1 ), $this->author ) );
		$this->assertSame( 30, $data['perPage'] );
		$this->assertSame( 2, $data['revision'] );

		$data = $this->data( $this->call( 'PATCH', '/galleries/' . $id, array( 'perPage' => null, 'expectedRevision' => 2 ), $this->author ) );
		$this->assertNull( $data['perPage'] );
		$this->assertSame( 3, $data['revision'] );
	}

	public function test_patch_sets_and_clears_the_gallery_hover(): void {
		$id = $this->makeGallery( $this->author, GalleryStatus::Draft, 'Hover' );
		$this->assertNull( $this->data( $this->call( 'GET', '/galleries/' . $id, array(), $this->author ) )['hover'] );

		$data = $this->data( $this->call( 'PATCH', '/galleries/' . $id, array( 'hover' => array( 'caption' => true, 'animation' => 'cover' ), 'expectedRevision' => 1 ), $this->author ) );
		$this->assertSame( array( 'title' => false, 'caption' => true, 'animation' => 'cover' ), $data['hover'] );
		$this->assertSame( 2, $data['revision'] );

		$data = $this->data( $this->call( 'PATCH', '/galleries/' . $id, array( 'hover' => null, 'expectedRevision' => 2 ), $this->author ) );
		$this->assertNull( $data['hover'] );
		$this->assertSame( 3, $data['revision'] );
	}

	public function test_an_editor_may_edit_foreign_galleries(): void {
		$id = $this->makeGallery( $this->author, GalleryStatus::Publish, 'Foreign' );

		$response = $this->call( 'PATCH', '/galleries/' . $id, array( 'title' => 'Edited', 'expectedRevision' => 1 ), $this->editor );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->author, $this->data( $response )['ownerId'] );
	}

	public function test_expected_revision_is_required_and_validated(): void {
		$id = $this->makeGallery( $this->author );

		$missing = $this->call( 'PATCH', '/galleries/' . $id, array( 'title' => 'x' ), $this->author );
		$this->assertSame( 400, $missing->get_status() );
		$this->assertSame( 'rest_missing_callback_param', $this->data( $missing )['code'] );
		foreach ( array( -1, 'abc', 1.5, null ) as $value ) {
			$this->assertSame( 400, $this->call( 'PATCH', '/galleries/' . $id, array( 'title' => 'x', 'expectedRevision' => $value ), $this->author )->get_status(), (string) wp_json_encode( $value ) );
		}
		$this->assertSame( 'Gallery', get_post( $id )->post_title );
	}

	public function test_a_stale_revision_is_a_409_with_both_revisions_and_changes_nothing(): void {
		$id = $this->makeGallery( $this->author, GalleryStatus::Draft, 'Original' );
		$this->call( 'PATCH', '/galleries/' . $id, array( 'title' => 'First writer', 'expectedRevision' => 1 ), $this->author );

		$response = $this->call( 'PATCH', '/galleries/' . $id, array( 'title' => 'Second writer', 'expectedRevision' => 1 ), $this->author );
		$data     = $this->data( $response );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'swg_revision_conflict', $data['code'] );
		$this->assertSame( 1, $data['data']['expectedRevision'] );
		$this->assertSame( 2, $data['data']['currentRevision'] );
		$this->assertSame( 'First writer', get_post( $id )->post_title );
		$this->assertSame( 409, $this->call( 'PATCH', '/galleries/' . $id, array( 'title' => 'x', 'expectedRevision' => 99 ), $this->author )->get_status() );
	}

	public function test_an_empty_patch_is_a_400(): void {
		$id = $this->makeGallery( $this->author );

		$response = $this->call( 'PATCH', '/galleries/' . $id, array( 'expectedRevision' => 1 ), $this->author );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'swg_no_changes', $this->data( $response )['code'] );
		$this->assertSame( 1, Services::revisions()->current( $id )->value );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function invalid_patches(): array {
		return array(
			'empty title'  => array( array( 'title' => '' ) ),
			'trash status' => array( array( 'status' => 'trash' ) ),
			'bad sort'     => array( array( 'sortMode' => 'random' ) ),
			'per page 0'   => array( array( 'perPage' => 0 ) ),
			'hover bad'    => array( array( 'hover' => array( 'animation' => 'spin' ) ) ),
			'per page 201' => array( array( 'perPage' => 201 ) ),
			'owner zero'   => array( array( 'ownerId' => 0 ) ),
			'owner string' => array( array( 'ownerId' => 'me' ) ),
			'cover zero'   => array( array( 'coverAttachmentId' => 0 ) ),
			'cover string' => array( array( 'coverAttachmentId' => 'x' ) ),
			'bad layout'   => array( array( 'layout' => 'No Good' ) ),
			'settings str' => array( array( 'settings' => 'x' ) ),
		);
	}

	/**
	 * @dataProvider invalid_patches
	 * @param array<string, mixed> $body Fields to patch.
	 */
	public function test_malformed_patches_are_400( array $body ): void {
		$id = $this->makeGallery( $this->author );

		$response = $this->call( 'PATCH', '/galleries/' . $id, $body + array( 'expectedRevision' => 1 ), $this->author );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 1, Services::revisions()->current( $id )->value );
	}

	public function test_semantic_errors_are_422(): void {
		$id = $this->makeGallery( $this->author );

		$layout = $this->call( 'PATCH', '/galleries/' . $id, array( 'layout' => 'unknown', 'expectedRevision' => 1 ), $this->author );
		$this->assertSame( 422, $layout->get_status() );
		$this->assertSame( 'swg_unknown_layout', $this->data( $layout )['code'] );

		$settings = $this->call( 'PATCH', '/galleries/' . $id, array( 'settings' => array( 'columns' => 'many' ), 'expectedRevision' => 1 ), $this->author );
		$this->assertSame( 422, $settings->get_status() );
		$this->assertSame( 'swg_validation_failed', $this->data( $settings )['code'] );

		$owner = $this->call( 'PATCH', '/galleries/' . $id, array( 'ownerId' => 99999999, 'expectedRevision' => 1 ), $this->editor );
		$this->assertSame( 422, $owner->get_status() );
		$this->assertSame( 'swg_invalid_owner', $this->data( $owner )['code'] );
		$this->assertSame( 1, Services::revisions()->current( $id )->value );
	}

	public function test_a_trashed_gallery_cannot_be_updated(): void {
		$id = $this->makeGallery( $this->author );
		$this->call( 'DELETE', '/galleries/' . $id, array(), $this->author, array( 'expectedRevision' => 1 ) );

		$response = $this->call( 'PATCH', '/galleries/' . $id, array( 'title' => 'x', 'expectedRevision' => 2 ), $this->author );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'swg_invalid_state', $this->data( $response )['code'] );
	}

	public function test_owner_changes_need_edit_others_posts_and_a_new_owner_who_may_write_posts(): void {
		$own = $this->makeGallery( $this->author );

		// S-04: even the owner cannot hand a gallery over without `edit_others_posts`.
		foreach ( array( $this->author, $this->contributor ) as $user ) {
			$id   = $this->makeGallery( $user );
			$deny = $this->call( 'PATCH', '/galleries/' . $id, array( 'ownerId' => $this->author2, 'expectedRevision' => 1 ), $user );
			$this->assertSame( 403, $deny->get_status() );
			$this->assertSame( $user, Services::galleries()->find( $id )->ownerId );
			$this->assertSame( 1, Services::revisions()->current( $id )->value );
		}

		// An unchanged owner is no transfer and stays allowed.
		$same = $this->call( 'PATCH', '/galleries/' . $own, array( 'ownerId' => $this->author, 'title' => 'T', 'expectedRevision' => 1 ), $this->author );
		$this->assertSame( 200, $same->get_status() );

		foreach ( array( $this->editor, $this->admin ) as $actor ) {
			$moved = $this->call( 'PATCH', '/galleries/' . $own, array( 'ownerId' => $this->contributor, 'expectedRevision' => $this->data( $this->call( 'GET', '/galleries/' . $own, array(), $actor ) )['revision'] ), $actor );
			$this->assertSame( 200, $moved->get_status() );
			$this->assertSame( $this->contributor, $this->data( $moved )['ownerId'] );
			$own_back = $this->call( 'PATCH', '/galleries/' . $own, array( 'ownerId' => $this->author, 'expectedRevision' => $this->data( $moved )['revision'] ), $actor );
			$this->assertSame( 200, $own_back->get_status() );
		}

		$revision = Services::revisions()->current( $own )->value;
		foreach ( array(
			'subscriber'  => $this->subscriber,
			'nonexistent' => 99999999,
		) as $label => $target ) {
			$bad = $this->call( 'PATCH', '/galleries/' . $own, array( 'ownerId' => $target, 'expectedRevision' => $revision ), $this->editor );
			$this->assertSame( 422, $bad->get_status(), $label );
			$this->assertSame( 'swg_invalid_owner', $this->data( $bad )['code'], $label );
		}
		$this->assertSame( $this->author, Services::galleries()->find( $own )->ownerId );
		$this->assertSame( $revision, Services::revisions()->current( $own )->value );

		// The existence-leak policy stays: a gallery the actor may not view is a 404, not a 403.
		$foreign = $this->makeGallery( $this->author2, GalleryStatus::Draft );
		$hidden  = $this->call( 'PATCH', '/galleries/' . $foreign, array( 'ownerId' => $this->author, 'expectedRevision' => 1 ), $this->author );
		$this->assertSame( 404, $hidden->get_status() );
	}

	// Publish rule (FU-03).

	public function test_publishing_needs_the_publish_right(): void {
		$id = $this->makeGallery( $this->contributor, GalleryStatus::Draft );

		foreach ( array( 'publish', 'private' ) as $status ) {
			$denied = $this->call( 'PATCH', '/galleries/' . $id, array( 'status' => $status, 'expectedRevision' => 1 ), $this->contributor );
			$this->assertSame( 403, $denied->get_status(), $status );
			$this->assertSame( 'swg_cannot_publish', $this->data( $denied )['code'] );
		}
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( 1, Services::revisions()->current( $id )->value );

		$pending = $this->call( 'PATCH', '/galleries/' . $id, array( 'status' => 'pending', 'expectedRevision' => 1 ), $this->contributor );
		$this->assertSame( 200, $pending->get_status() );

		$own = $this->makeGallery( $this->author );
		$this->assertSame( 200, $this->call( 'PATCH', '/galleries/' . $own, array( 'status' => 'publish', 'expectedRevision' => 1 ), $this->author )->get_status() );
		$this->assertSame( 'publish', get_post_status( $own ) );
	}

	public function test_the_publish_rule_applies_to_the_policys_can_publish_for_foreign_galleries(): void {
		$id = $this->makeGallery( $this->author );

		$this->assertSame( 200, $this->call( 'PATCH', '/galleries/' . $id, array( 'status' => 'publish', 'expectedRevision' => 1 ), $this->editor )->get_status() );
	}

	// Cover.

	public function test_the_cover_must_be_an_attachment_the_actor_may_use(): void {
		$id    = $this->makeGallery( $this->author );
		$image = $this->makeAttachment( $this->author );
		update_post_meta( $image, '_wp_attached_file', '2026/01/cover.jpg' );

		$ok = $this->data( $this->call( 'PATCH', '/galleries/' . $id, array( 'coverAttachmentId' => $image, 'expectedRevision' => 1 ), $this->author ) );
		$this->assertSame( $image, $ok['coverAttachmentId'] );
		$this->assertIsString( $ok['coverUrl'] );
		$this->assertStringContainsString( 'cover', $ok['coverUrl'] );

		foreach ( array(
			'missing'    => 99999999,
			'not media'  => $this->factory()->post->create(),
			'text file'  => $this->makeAttachment( $this->author, 'text/plain' ),
			'svg'        => $this->makeAttachment( $this->author, 'image/svg+xml' ),
		) as $label => $bad ) {
			$response = $this->call( 'PATCH', '/galleries/' . $id, array( 'coverAttachmentId' => $bad, 'expectedRevision' => 2 ), $this->author );
			$this->assertSame( 422, $response->get_status(), $label );
			$this->assertSame( 'swg_invalid_cover', $this->data( $response )['code'] );
		}
		$this->assertSame( $image, Services::galleries()->find( $id )->coverAttachmentId );

		$cleared = $this->data( $this->call( 'PATCH', '/galleries/' . $id, array( 'coverAttachmentId' => null, 'expectedRevision' => 2 ), $this->author ) );
		$this->assertNull( $cleared['coverAttachmentId'] );
		$this->assertNull( $cleared['coverUrl'] );
	}

	public function test_a_private_foreign_attachment_cannot_be_used_as_cover_and_its_url_is_hidden(): void {
		$id      = $this->makeGallery( $this->author );
		$foreign = $this->makeAttachment( $this->author2, 'image/jpeg', array( 'post_status' => 'private' ) );
		update_post_meta( $foreign, '_wp_attached_file', '2026/01/private.jpg' );

		$response = $this->call( 'PATCH', '/galleries/' . $id, array( 'coverAttachmentId' => $foreign, 'expectedRevision' => 1 ), $this->author );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'swg_invalid_cover', $this->data( $response )['code'] );
	}

	public function test_the_cover_url_is_only_shown_to_users_who_may_use_the_attachment(): void {
		$id      = $this->makeGallery( $this->contributor );
		$image   = $this->makeAttachment( $this->contributor );
		$private = $this->makeAttachment( $this->author2, 'image/jpeg', array( 'post_status' => 'private' ) );
		update_post_meta( $image, '_wp_attached_file', '2026/01/cover.jpg' );
		update_post_meta( $private, '_wp_attached_file', '2026/01/private.jpg' );
		Services::gallery_service()->update( new Actor( $this->admin ), Services::galleries()->find( $id )->withCover( $image ), new Revision( 1 ) );

		$contributor_view = $this->data( $this->call( 'GET', '/galleries/' . $id, array(), $this->contributor ) );
		$admin_view       = $this->data( $this->call( 'GET', '/galleries/' . $id, array(), $this->admin ) );

		$this->assertSame( $image, $contributor_view['coverAttachmentId'] );
		$this->assertIsString( $contributor_view['coverUrl'], 'FU-16: contributors may use the images they can read.' );
		$this->assertIsString( $admin_view['coverUrl'] );

		Services::gallery_service()->update( new Actor( $this->admin ), Services::galleries()->find( $id )->withCover( $private ), new Revision( 2 ) );
		$hidden = $this->data( $this->call( 'GET', '/galleries/' . $id, array(), $this->contributor ) );
		$this->assertNull( $hidden['coverUrl'], 'Another user\'s private upload stays hidden.' );
	}

	public function test_an_unchanged_cover_does_not_fail_when_other_fields_change(): void {
		$id    = $this->makeGallery( $this->contributor );
		$image = $this->makeAttachment( $this->contributor );
		Services::gallery_service()->update( new Actor( $this->admin ), Services::galleries()->find( $id )->withCover( $image ), new Revision( 1 ) );

		$response = $this->call( 'PATCH', '/galleries/' . $id, array( 'title' => 'Renamed', 'coverAttachmentId' => $image, 'expectedRevision' => 2 ), $this->contributor );

		$this->assertSame( 200, $response->get_status() );
	}

	// Trash, force delete, restore.

	public function test_delete_trashes_and_keeps_items_and_attachments(): void {
		$id     = $this->makeGallery( $this->author );
		$images = array( $this->makeAttachment( $this->author ), $this->makeAttachment( $this->author ) );
		Services::item_service()->assign( new Actor( $this->author ), $id, $images, new Revision( 1 ) );

		$response = $this->call( 'DELETE', '/galleries/' . $id, array(), $this->author, array( 'expectedRevision' => 2 ) );
		$data     = $this->data( $response );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['trashed'] );
		$this->assertSame( 'trash', $data['status'] );
		$this->assertSame( 3, $data['revision'] );
		$this->assertSame( 'trash', get_post_status( $id ) );
		$this->assertSame( 2, $this->countItemRows() );
		foreach ( $images as $image ) {
			$this->assertSame( 'attachment', get_post_type( $image ) );
		}

		$again = $this->call( 'DELETE', '/galleries/' . $id, array(), $this->author, array( 'expectedRevision' => 3 ) );
		$this->assertSame( 409, $again->get_status() );
		$this->assertSame( 'swg_invalid_state', $this->data( $again )['code'] );
	}

	public function test_force_delete_removes_gallery_and_relations_but_never_attachments(): void {
		$id     = $this->makeGallery( $this->author );
		$other  = $this->makeGallery( $this->author );
		$images = array( $this->makeAttachment( $this->author ), $this->makeAttachment( $this->author ) );
		Services::item_service()->assign( new Actor( $this->author ), $id, $images, new Revision( 1 ) );
		Services::item_service()->assign( new Actor( $this->author ), $other, array( $images[0] ), new Revision( 1 ) );

		$response = $this->call( 'DELETE', '/galleries/' . $id, array(), $this->author, array( 'expectedRevision' => 2, 'force' => 'true' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'id' => $id, 'deleted' => true ), $this->data( $response ) );
		$this->assertNull( get_post( $id ) );
		$this->assertSame( 1, $this->countItemRows() );
		foreach ( $images as $image ) {
			$this->assertSame( 'attachment', get_post_type( $image ) );
		}
		$this->assertSame( 404, $this->call( 'GET', '/galleries/' . $id, array(), $this->author )->get_status() );
	}

	public function test_a_trashed_gallery_can_be_deleted_permanently(): void {
		$id = $this->makeGallery( $this->author );
		$this->call( 'DELETE', '/galleries/' . $id, array(), $this->author, array( 'expectedRevision' => 1 ) );

		$response = $this->call( 'DELETE', '/galleries/' . $id, array(), $this->author, array( 'expectedRevision' => 2, 'force' => true ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( get_post( $id ) );
	}

	public function test_delete_needs_the_expected_revision_in_query_or_body(): void {
		$id = $this->makeGallery( $this->author );

		$this->assertSame( 400, $this->call( 'DELETE', '/galleries/' . $id, array(), $this->author )->get_status() );
		$this->assertSame( 409, $this->call( 'DELETE', '/galleries/' . $id, array(), $this->author, array( 'expectedRevision' => 5 ) )->get_status() );
		$this->assertSame( 400, $this->call( 'DELETE', '/galleries/' . $id, array(), $this->author, array( 'expectedRevision' => 1, 'force' => 'maybe' ) )->get_status() );
		$this->assertSame( 'draft', get_post_status( $id ) );

		$body = $this->call( 'DELETE', '/galleries/' . $id, array( 'expectedRevision' => 1 ), $this->author );
		$this->assertSame( 200, $body->get_status() );
		$this->assertSame( 'trash', get_post_status( $id ) );
	}

	public function test_a_listener_can_veto_trash_and_delete(): void {
		$id = $this->makeGallery( $this->author );
		add_filter( 'swg_before_gallery_trashing', '__return_false' );
		add_filter( 'swg_before_gallery_deleting', '__return_false' );

		$trash = $this->call( 'DELETE', '/galleries/' . $id, array(), $this->author, array( 'expectedRevision' => 1 ) );
		$force = $this->call( 'DELETE', '/galleries/' . $id, array(), $this->author, array( 'expectedRevision' => 1, 'force' => true ) );

		$this->assertSame( 403, $trash->get_status() );
		$this->assertSame( 'swg_operation_vetoed', $this->data( $trash )['code'] );
		$this->assertSame( 403, $force->get_status() );
		$this->assertSame( 'draft', get_post_status( $id ) );
	}

	public function test_restore_returns_a_draft_with_a_new_revision(): void {
		$id = $this->makeGallery( $this->author, GalleryStatus::Publish );
		$this->call( 'DELETE', '/galleries/' . $id, array(), $this->author, array( 'expectedRevision' => 1 ) );

		$stale = $this->call( 'POST', '/galleries/' . $id . '/restore', array( 'expectedRevision' => 1 ), $this->author );
		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 'swg_revision_conflict', $this->data( $stale )['code'] );

		$missing = $this->call( 'POST', '/galleries/' . $id . '/restore', array(), $this->author );
		$this->assertSame( 400, $missing->get_status() );

		$response = $this->call( 'POST', '/galleries/' . $id . '/restore', array( 'expectedRevision' => 2 ), $this->author );
		$data     = $this->data( $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'draft', $data['status'] );
		$this->assertSame( 3, $data['revision'] );

		$again = $this->call( 'POST', '/galleries/' . $id . '/restore', array( 'expectedRevision' => 3 ), $this->author );
		$this->assertSame( 409, $again->get_status() );
		$this->assertSame( 'swg_invalid_state', $this->data( $again )['code'] );
	}

	// Duplicate.

	public function test_duplicate_copies_membership_into_a_draft_owned_by_the_caller(): void {
		$id     = $this->makeGallery( $this->author2, GalleryStatus::Publish, 'Source' );
		$images = array( $this->makeAttachment( $this->author2 ), $this->makeAttachment( $this->author2 ) );
		Services::item_service()->assign( new Actor( $this->author2 ), $id, $images, new Revision( 1 ) );

		$response = $this->call( 'POST', '/galleries/' . $id . '/duplicate', array(), $this->author );
		$data     = $this->data( $response );

		$this->assertSame( 201, $response->get_status() );
		$this->assertNotSame( $id, $data['id'] );
		$this->assertSame( 'Source (copy)', $data['title'] );
		$this->assertSame( 'draft', $data['status'] );
		$this->assertSame( $this->author, $data['ownerId'] );
		$this->assertSame( 1, $data['revision'] );
		$this->assertSame( 2, $data['itemCount'] );
		$this->assertSame( rest_url( 'swg/v1/galleries/' . $data['id'] ), $response->get_headers()['Location'] );
		$this->assertSame( 2, Services::revisions()->current( $id )->value, 'The source keeps its revision.' );
		$this->assertSame( 4, $this->countItemRows() );
		foreach ( $images as $image ) {
			$this->assertSame( 'attachment', get_post_type( $image ) );
		}
	}

	public function test_duplicate_accepts_a_title_and_validates_it(): void {
		$id = $this->makeGallery( $this->author );

		$named = $this->data( $this->call( 'POST', '/galleries/' . $id . '/duplicate', array( 'title' => ' <b>Copy</b> ' ), $this->author ) );
		$this->assertSame( 'Copy', $named['title'] );

		$this->assertSame( 400, $this->call( 'POST', '/galleries/' . $id . '/duplicate', array( 'title' => '' ), $this->author )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/galleries/' . $id . '/duplicate', array( 'title' => str_repeat( 'a', 201 ) ), $this->author )->get_status() );
	}

	public function test_a_trashed_gallery_cannot_be_duplicated(): void {
		$id = $this->makeGallery( $this->author );
		$this->call( 'DELETE', '/galleries/' . $id, array(), $this->author, array( 'expectedRevision' => 1 ) );

		$response = $this->call( 'POST', '/galleries/' . $id . '/duplicate', array(), $this->author );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'swg_invalid_state', $this->data( $response )['code'] );
	}
}
