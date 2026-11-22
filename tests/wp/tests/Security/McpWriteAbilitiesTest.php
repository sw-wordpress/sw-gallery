<?php
/**
 * Behaviour of the MCP write abilities (MCP-03): shared rules with REST, plain-text storage, revisions, rights.
 * Authorisation per role lives in McpAbilitySecurityTest.
 *
 * @package sw-gallery
 */

require_once dirname( __DIR__, 2 ) . '/support/McpSecurityTestTrait.php';

use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Tests\Support\RestItemFixtures;
use SWGallery\WordPress\Mcp\McpAuditLog;
use SWGallery\WordPress\Mcp\TierPolicy;

class McpWriteAbilitiesTest extends SwgTestCase {

	use RestItemFixtures;
	use McpSecurityTestTrait;

	/** @var array<string, int> */
	private array $users = array();

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( '\\WP\\MCP\\Plugin', false ) ) {
			$this->markTestSkipped( 'The mcp-adapter is not loaded (absence run).' );
		}
		$this->bootRest();
		foreach ( array( 'author', 'other', 'editor', 'contributor', 'administrator' ) as $key ) {
			$this->users[ $key ] = $this->makeUser( 'other' === $key ? 'author' : $key );
		}
		$this->reregisterAbilities( 'write' );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		foreach ( array_keys( $this->ourAbilities() ) as $name ) {
			wp_unregister_ability( $name );
		}
		delete_option( TierPolicy::OPTION );
		delete_option( McpAuditLog::OPTION );
		$this->shutdownRest();
		parent::tear_down();
	}

	/** @return array<string, mixed> */
	private function read( int $gallery, string $as = 'author' ): array {
		return $this->runAbility( 'get-gallery', array( 'id' => $gallery ), $this->users[ $as ] );
	}

	public function test_create_makes_a_private_owned_draft_with_the_site_default_layout(): void {
		$created = $this->runAbility( 'create-gallery', array( 'title' => 'New one' ), $this->users['author'] );
		$this->assertSame( 'draft', $created['status'] );
		$this->assertSame( $this->users['author'], $created['ownerId'] );
		$this->assertSame( 1, $created['revision'] );
		$this->assertSame( 'manual', $created['sortMode'] );
		$this->assertSame( \SWGallery\WordPress\Infrastructure\Services::settings()->get()['defaultLayout'], $created['layout'] );
		$this->assertSame( $created['id'], $this->read( $created['id'] )['id'] );
		$this->assertSame( array(), $this->ids_of( $this->runAbility( 'list-galleries', array(), $this->users['other'] ) ), 'invisible to other authors' );

		$rest = $this->data( $this->call( 'GET', '/galleries/' . $created['id'], array(), $this->users['author'] ) );
		$this->assertSame( wp_json_encode( \SWGallery\WordPress\Mcp\GallerySchema::project( $rest ) ), wp_json_encode( $created ), 'the same resource as REST' );
	}

	/** @return list<int> */
	private function ids_of( array $list ): array {
		return array_map( 'intval', array_column( $list['items'], 'id' ) );
	}

	public function test_create_applies_every_field_and_strips_markup(): void {
		$created = $this->runAbility(
			'create-gallery',
			array(
				'title'       => '<script>alert(1)</script>Safe <b>title</b>',
				'description' => "Line one\n<img src=x onerror=alert(1)>Line two",
				'sortMode'    => 'title_desc',
				'perPage'     => 12,
				'hover'       => array( 'title' => true, 'caption' => false, 'animation' => 'cover' ),
			),
			$this->users['administrator']
		);
		$this->assertStringNotContainsString( '<', $created['title'] );
		$this->assertStringNotContainsString( '<', $created['description'] );
		$this->assertStringContainsString( "\n", $created['description'], 'line breaks survive' );
		$this->assertSame( array( 'title_desc', 12 ), array( $created['sortMode'], $created['perPage'] ) );
		$this->assertSame( 'cover', $created['hover']['animation'] );
	}

	public function test_create_refuses_publishing_without_the_right_and_unknown_layouts(): void {
		$denied = $this->runAbility( 'create-gallery', array( 'title' => 'Pub', 'status' => 'publish' ), $this->users['contributor'] );
		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( 'swg_cannot_publish', $denied->get_error_code() );
		$this->assertSame( array(), $this->ids_of( $this->runAbility( 'list-galleries', array(), $this->users['contributor'] ) ) );

		$unknown = $this->runAbility( 'create-gallery', array( 'title' => 'Layout', 'layout' => 'does-not-exist' ), $this->users['author'] );
		$this->assertInstanceOf( WP_Error::class, $unknown );
		$this->assertSame( 'swg_unknown_layout', $unknown->get_error_code() );
	}

	public function test_update_strips_markup_and_checks_the_cover_right(): void {
		$gallery = $this->makeGallery( $this->users['author'] );
		$rev     = $this->revisionOf( $gallery );
		$updated = $this->runAbility( 'update-gallery', array( 'id' => $gallery, 'expectedRevision' => $rev, 'title' => '<i>Plain</i>' ), $this->users['author'] );
		$this->assertSame( 'Plain', $updated['title'] );

		$secret  = $this->makeGallery( $this->users['editor'], GalleryStatus::Private );
		$private = $this->makeAttachment( $this->users['editor'], 'image/jpeg', array( 'post_parent' => $secret ) );
		$cover   = $this->runAbility( 'update-gallery', array( 'id' => $gallery, 'expectedRevision' => $updated['revision'], 'coverAttachmentId' => $private ), $this->users['author'] );
		$this->assertInstanceOf( WP_Error::class, $cover );
		$this->assertSame( 'swg_invalid_cover', $cover->get_error_code() );
		$own = $this->makeAttachment( $this->users['author'] );
		$ok  = $this->runAbility( 'update-gallery', array( 'id' => $gallery, 'expectedRevision' => $updated['revision'], 'coverAttachmentId' => $own ), $this->users['author'] );
		$this->assertSame( $own, $ok['coverAttachmentId'] );
	}

	public function test_duplicate_copies_items_into_a_new_draft_of_the_caller(): void {
		$source = $this->makeGallery( $this->users['author'], GalleryStatus::Draft, 'Original' );
		$att    = $this->seedItems( $source, $this->users['author'], 2 );
		$copy   = $this->runAbility( 'duplicate-gallery', array( 'id' => $source ), $this->users['author'] );
		$this->assertSame( 'Original (copy)', $copy['title'] );
		$this->assertSame( array( 'draft', 1, 2 ), array( $copy['status'], $copy['revision'], $copy['itemCount'] ) );
		$this->assertNotSame( $source, $copy['id'] );
		$items = $this->runAbility( 'list-items', array( 'id' => $copy['id'] ), $this->users['author'] );
		$this->assertSame( $att, array_column( $items['items'], 'attachmentId' ) );

		$named = $this->runAbility( 'duplicate-gallery', array( 'id' => $source, 'title' => '<b>Mine</b>' ), $this->users['editor'] );
		$this->assertSame( 'Mine', $named['title'] );
		$this->assertSame( $this->users['editor'], $named['ownerId'], 'the copy belongs to the caller' );

		$this->runAbility( 'trash-gallery', array( 'id' => $source, 'expectedRevision' => $this->revisionOf( $source ) ), $this->users['author'] );
		$trashed = $this->runAbility( 'duplicate-gallery', array( 'id' => $source ), $this->users['author'] );
		$this->assertSame( 'swg_invalid_state', $trashed->get_error_code() );
	}

	public function test_trash_and_restore_are_lossless_and_revision_checked(): void {
		$gallery = $this->makeGallery( $this->users['author'] );
		$this->seedItems( $gallery, $this->users['author'], 2 );
		$rev     = $this->revisionOf( $gallery );

		$stale = $this->runAbility( 'trash-gallery', array( 'id' => $gallery, 'expectedRevision' => $rev + 5 ), $this->users['author'] );
		$this->assertSame( 'swg_revision_conflict', $stale->get_error_code() );
		$this->assertSame( 'draft', $this->read( $gallery )['status'] );

		$trashed = $this->runAbility( 'trash-gallery', array( 'id' => $gallery, 'expectedRevision' => $rev ), $this->users['author'] );
		$this->assertSame( array( true, 'trash' ), array( $trashed['trashed'], $trashed['status'] ) );
		$again = $this->runAbility( 'trash-gallery', array( 'id' => $gallery, 'expectedRevision' => $trashed['revision'] ), $this->users['author'] );
		$this->assertSame( 'swg_invalid_state', $again->get_error_code(), 'trashing twice is a state error' );

		$restored = $this->runAbility( 'restore-gallery', array( 'id' => $gallery, 'expectedRevision' => $trashed['revision'] ), $this->users['author'] );
		$this->assertSame( array( 'draft', 2 ), array( $restored['status'], $restored['itemCount'] ) );
		$live = $this->runAbility( 'restore-gallery', array( 'id' => $gallery, 'expectedRevision' => $restored['revision'] ), $this->users['author'] );
		$this->assertSame( 'swg_invalid_state', $live->get_error_code(), 'restoring a live gallery is a state error' );
	}

	public function test_assign_reports_duplicates_and_unusable_ids_and_checks_the_revision(): void {
		$gallery = $this->makeGallery( $this->users['author'] );
		$own     = $this->makeAttachment( $this->users['author'] );
		$secret  = $this->makeGallery( $this->users['editor'], GalleryStatus::Private );
		$hidden  = $this->makeAttachment( $this->users['editor'], 'image/jpeg', array( 'post_parent' => $secret ) );
		$rev     = $this->revisionOf( $gallery );

		$first = $this->runAbility( 'assign-items', array( 'id' => $gallery, 'attachmentIds' => array( $own, $hidden, 99999999 ), 'expectedRevision' => $rev ), $this->users['author'] );
		$this->assertSame( array( $own ), $first['added'] );
		$this->assertEqualsCanonicalizing( array( $hidden, 99999999 ), $first['missing'], 'an attachment the user may not use looks nonexistent' );

		$second = $this->runAbility( 'assign-items', array( 'id' => $gallery, 'attachmentIds' => array( $own ), 'expectedRevision' => $first['revision'] ), $this->users['author'] );
		$this->assertSame( array( $own ), $second['alreadyAssigned'] );

		$stale = $this->runAbility( 'assign-items', array( 'id' => $gallery, 'attachmentIds' => array( $this->makeAttachment( $this->users['author'] ) ), 'expectedRevision' => $rev ), $this->users['author'] );
		$this->assertSame( 'swg_revision_conflict', $stale->get_error_code() );
		$this->assertCount( 1, $this->itemIds( $gallery ), 'the stale request added nothing' );
	}

	public function test_update_item_changes_texts_partially_and_strips_markup(): void {
		$gallery = $this->makeGallery( $this->users['author'] );
		$this->seedItems( $gallery, $this->users['author'], 1 );
		$item = $this->itemIds( $gallery )[0];
		$rev  = $this->revisionOf( $gallery );

		$one = $this->runAbility( 'update-item', array( 'id' => $gallery, 'itemId' => $item, 'expectedRevision' => $rev, 'overrides' => array( 'title' => '<b>Hello</b>', 'caption' => "Two\nlines<script>x</script>" ) ), $this->users['author'] );
		$this->assertSame( 'Hello', $one['item']['overrides']['title'] );
		$this->assertStringContainsString( "\n", (string) $one['item']['overrides']['caption'] );
		$this->assertStringNotContainsString( '<', (string) $one['item']['overrides']['caption'] );
		$this->assertNull( $one['item']['overrides']['alt'] );

		$two = $this->runAbility( 'update-item', array( 'id' => $gallery, 'itemId' => $item, 'expectedRevision' => $one['revision'], 'overrides' => array( 'alt' => '' ) ), $this->users['author'] );
		$this->assertSame( array( 'Hello', '' ), array( $two['item']['overrides']['title'], $two['item']['overrides']['alt'] ), 'absent fields keep their value, an empty string is a blank' );

		$three = $this->runAbility( 'update-item', array( 'id' => $gallery, 'itemId' => $item, 'expectedRevision' => $two['revision'], 'overrides' => array( 'title' => null ) ), $this->users['author'] );
		$this->assertNull( $three['item']['overrides']['title'], 'null inherits again' );
		$this->assertArrayNotHasKey( 'media', $three['item'] );
		$this->assertDoesNotMatchRegularExpression( '#https?://#', (string) wp_json_encode( $three ) );
	}

	public function test_update_item_input_errors_and_foreign_items(): void {
		$gallery = $this->makeGallery( $this->users['author'] );
		$this->seedItems( $gallery, $this->users['author'], 1 );
		$other   = $this->makeGallery( $this->users['author'], GalleryStatus::Draft, 'Other' );
		$this->seedItems( $other, $this->users['author'], 1 );
		$item    = $this->itemIds( $gallery )[0];
		$foreign = $this->itemIds( $other )[0];
		$rev     = $this->revisionOf( $gallery );

		$none = $this->runAbility( 'update-item', array( 'id' => $gallery, 'itemId' => $item, 'expectedRevision' => $rev ), $this->users['author'] );
		$this->assertSame( 'swg_no_changes', $none->get_error_code() );

		$wrong = $this->runAbility( 'update-item', array( 'id' => $gallery, 'itemId' => $foreign, 'expectedRevision' => $rev, 'overrides' => array( 'title' => 'x' ) ), $this->users['author'] );
		$this->assertInstanceOf( WP_Error::class, $wrong, 'an item of another gallery is not found here' );
		$this->assertSame( $this->read( $other )['revision'], $this->revisionOf( $other ), 'and nothing changed there' );

		$list = $this->runAbility( 'update-item', array( 'id' => $gallery, 'itemId' => $item, 'expectedRevision' => $rev, 'settings' => array( 1, 2 ) ), $this->users['author'] );
		$this->assertInstanceOf( WP_Error::class, $list, 'settings must be an object' );
		foreach ( array( array( 'title' => str_repeat( 'x', 201 ) ), array( 'caption' => str_repeat( 'x', 5001 ) ), array( 'alt' => str_repeat( 'x', 501 ) ), array( 'bogus' => 'x' ), array() ) as $overrides ) {
			$bad = $this->runAbility( 'update-item', array( 'id' => $gallery, 'itemId' => $item, 'expectedRevision' => $rev, 'overrides' => $overrides ), $this->users['author'] );
			$this->assertInstanceOf( WP_Error::class, $bad, wp_json_encode( $overrides ) );
		}
		$this->assertSame( $rev, $this->revisionOf( $gallery ) );
	}

	public function test_reorder_is_atomic_and_needs_the_exact_set(): void {
		$gallery = $this->makeGallery( $this->users['author'] );
		$this->seedItems( $gallery, $this->users['author'], 3 );
		$items   = $this->itemIds( $gallery );
		$rev     = $this->revisionOf( $gallery );

		$bad = $this->runAbility( 'reorder-items', array( 'id' => $gallery, 'itemIds' => array( $items[0], $items[1] ), 'expectedRevision' => $rev ), $this->users['author'] );
		$this->assertSame( 'swg_invalid_order', $bad->get_error_code() );
		$dup = $this->runAbility( 'reorder-items', array( 'id' => $gallery, 'itemIds' => array( $items[0], $items[0], $items[1] ), 'expectedRevision' => $rev ), $this->users['author'] );
		$this->assertInstanceOf( WP_Error::class, $dup );
		$this->assertSame( $items, $this->itemIds( $gallery ), 'nothing was written' );

		$ok = $this->runAbility( 'reorder-items', array( 'id' => $gallery, 'itemIds' => array_reverse( $items ), 'expectedRevision' => $rev ), $this->users['author'] );
		$this->assertSame( 3, $ok['itemCount'] );
		$this->assertSame( array_reverse( $items ), $this->itemIds( $gallery ) );

		$stale = $this->runAbility( 'reorder-items', array( 'id' => $gallery, 'itemIds' => $items, 'expectedRevision' => $rev ), $this->users['author'] );
		$this->assertSame( 'swg_revision_conflict', $stale->get_error_code() );
	}

	public function test_other_users_cannot_write_to_a_gallery_they_cannot_see(): void {
		$gallery = $this->makeGallery( $this->users['author'] );
		$this->seedItems( $gallery, $this->users['author'], 1 );
		$item    = $this->itemIds( $gallery )[0];
		$rev     = $this->revisionOf( $gallery );
		$calls   = array(
			array( 'update-gallery', array( 'title' => 'Hijack' ) ),
			array( 'trash-gallery', array() ),
			array( 'assign-items', array( 'attachmentIds' => array( $this->makeAttachment( $this->users['other'] ) ) ) ),
			array( 'update-item', array( 'itemId' => $item, 'overrides' => array( 'title' => 'x' ) ) ),
			array( 'reorder-items', array( 'itemIds' => array( $item ) ) ),
		);
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		foreach ( $calls as $call ) {
			$result = $this->runAbility( $call[0], array( 'id' => $gallery, 'expectedRevision' => $rev ) + $call[1], $this->users['other'] );
			$this->assertInstanceOf( WP_Error::class, $result, $call[0] );
		}
		$this->assertSame( $rev, $this->revisionOf( $gallery ), 'nothing was written' );
		$this->assertSame( array( $item ), $this->itemIds( $gallery ) );
	}

	public function test_every_successful_write_is_audited_once(): void {
		delete_option( McpAuditLog::OPTION );
		$created = $this->runAbility( 'create-gallery', array( 'title' => 'Audit me' ), $this->users['author'] );
		$this->runAbility( 'update-gallery', array( 'id' => $created['id'], 'expectedRevision' => 1, 'title' => 'Audit me 2' ), $this->users['author'] );
		$entries = ( new McpAuditLog() )->recent();
		$this->assertSame( array( 'update-gallery', 'create-gallery' ), array_column( $entries, 'ability' ) );
		$this->assertSame( array( 'ok', 'ok' ), array_column( $entries, 'outcome' ) );
		$this->assertStringNotContainsString( 'Audit me', (string) wp_json_encode( $entries ), 'no payload in the audit' );
	}
}
