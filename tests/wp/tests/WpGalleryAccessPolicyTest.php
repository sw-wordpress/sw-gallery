<?php
/**
 * Table-driven tests of the WordPress access policy: roles x actions x own/foreign galleries x statuses, and the
 * attachment rules of decision D11. The policy never reads the current user.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Access\RenderAccess;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Gallery\NewGallery;
use SWGallery\Tests\Support\WpFixtures;
use SWGallery\WordPress\Repository\WpdbGalleryRelations;
use SWGallery\WordPress\Repository\WpGalleryRepository;
use SWGallery\WordPress\Security\WpGalleryAccessPolicy;

class WpGalleryAccessPolicyTest extends SwgTestCase {

	use WpFixtures;

	private const ROLES = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );

	private WpGalleryAccessPolicy $policy;
	private WpGalleryRepository $repository;

	/** @var array<string, int> role => user id */
	private array $users = array();

	/** @var int A user who owns the "foreign" galleries and attachments (an author). */
	private int $stranger = 0;

	public function set_up(): void {
		parent::set_up();
		$this->purgeGalleries();
		$this->policy     = new WpGalleryAccessPolicy();
		$this->repository = new WpGalleryRepository( new WpdbGalleryRelations() );
		foreach ( self::ROLES as $role ) {
			$this->users[ $role ] = $this->makeUser( $role );
		}
		$this->stranger = $this->makeUser( 'author' );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->purgeGalleries();
		parent::tear_down();
	}

	/**
	 * Expected [view, edit, delete] per role, ownership and status.
	 *
	 * @return array<string, array{string, bool, string, array{bool, bool, bool}}>
	 */
	public static function gallery_matrix(): array {
		$yes  = array( true, true, true );
		$none = array( false, false, false );
		$rows = array(
			// Administrators, editors and authors manage everything they own.
			array( 'administrator', true, 'publish', $yes ),
			array( 'administrator', true, 'private', $yes ),
			array( 'administrator', true, 'draft', $yes ),
			array( 'editor', true, 'publish', $yes ),
			array( 'editor', true, 'private', $yes ),
			array( 'editor', true, 'draft', $yes ),
			array( 'author', true, 'publish', $yes ),
			array( 'author', true, 'private', $yes ),
			array( 'author', true, 'draft', $yes ),
			// Contributors keep drafts, but cannot touch their published galleries (core semantics of the post type).
			array( 'contributor', true, 'publish', array( true, false, false ) ),
			array( 'contributor', true, 'private', $yes ),
			array( 'contributor', true, 'draft', $yes ),
			// Subscribers hold no editing capability at all.
			array( 'subscriber', true, 'publish', $none ),
			array( 'subscriber', true, 'private', $none ),
			array( 'subscriber', true, 'draft', $none ),
			// Foreign galleries: only users with the *_others_posts capabilities.
			array( 'administrator', false, 'publish', $yes ),
			array( 'administrator', false, 'private', $yes ),
			array( 'administrator', false, 'draft', $yes ),
			array( 'editor', false, 'publish', $yes ),
			array( 'editor', false, 'private', $yes ),
			array( 'editor', false, 'draft', $yes ),
			array( 'author', false, 'publish', array( true, false, false ) ),
			array( 'author', false, 'private', $none ),
			array( 'author', false, 'draft', $none ),
			array( 'contributor', false, 'publish', array( true, false, false ) ),
			array( 'contributor', false, 'private', $none ),
			array( 'contributor', false, 'draft', $none ),
			array( 'subscriber', false, 'publish', $none ),
			array( 'subscriber', false, 'private', $none ),
			array( 'subscriber', false, 'draft', $none ),
		);
		$data = array();
		foreach ( $rows as $row ) {
			$data[ sprintf( '%s %s %s', $row[0], $row[1] ? 'own' : 'foreign', $row[2] ) ] = $row;
		}
		return $data;
	}

	/**
	 * @dataProvider gallery_matrix
	 * @param string                  $role     Actor role.
	 * @param bool                    $own      Whether the actor owns the gallery.
	 * @param string                  $status   Gallery status.
	 * @param array{bool, bool, bool} $expected View, edit, delete.
	 */
	public function test_view_edit_and_delete_follow_ownership_and_status( string $role, bool $own, string $status, array $expected ): void {
		$actor   = new Actor( $this->users[ $role ] );
		$gallery = $this->repository->create( new NewGallery( 'G', $own ? $this->users[ $role ] : $this->stranger, '', GalleryStatus::from( $status ) ) );

		$this->assertSame( $expected[0], $this->policy->canView( $actor, $gallery ), 'view' );
		$this->assertSame( $expected[1], $this->policy->canEdit( $actor, $gallery ), 'edit' );
		$this->assertSame( $expected[2], $this->policy->canDelete( $actor, $gallery ), 'delete' );
	}

	/**
	 * @return array<string, array{string, bool, string, string}>
	 */
	public static function embed_matrix(): array {
		$rows = array(
			array( 'administrator', false, 'publish', 'Render' ),
			array( 'subscriber', false, 'publish', 'Render' ),
			array( 'administrator', false, 'private', 'Render' ),
			array( 'editor', false, 'private', 'Render' ),
			array( 'author', false, 'private', 'Nothing' ),
			array( 'contributor', false, 'private', 'Nothing' ),
			array( 'subscriber', false, 'private', 'Nothing' ),
			array( 'author', true, 'private', 'Render' ),
			array( 'subscriber', true, 'private', 'Render' ),
			array( 'administrator', false, 'draft', 'Diagnostic' ),
			array( 'editor', false, 'draft', 'Diagnostic' ),
			array( 'author', false, 'draft', 'Nothing' ),
			array( 'contributor', false, 'draft', 'Nothing' ),
			array( 'subscriber', false, 'draft', 'Nothing' ),
			array( 'author', true, 'draft', 'Diagnostic' ),
			array( 'contributor', true, 'pending', 'Diagnostic' ),
			array( 'subscriber', true, 'draft', 'Nothing' ),
			array( 'editor', false, 'pending', 'Diagnostic' ),
		);
		$data = array();
		foreach ( $rows as $row ) {
			$data[ sprintf( '%s %s %s', $row[0], $row[1] ? 'own' : 'foreign', $row[2] ) ] = $row;
		}
		return $data;
	}

	/**
	 * @dataProvider embed_matrix
	 */
	public function test_embed_access_delegates_to_the_status_rules_with_real_capabilities( string $role, bool $own, string $status, string $expected ): void {
		$actor   = new Actor( $this->users[ $role ] );
		$gallery = $this->repository->create( new NewGallery( 'G', $own ? $this->users[ $role ] : $this->stranger, '', GalleryStatus::from( $status ) ) );

		$this->assertSame( $expected, $this->policy->embedAccess( $actor, $gallery )->name );
	}

	public function test_anonymous_visitors_get_published_galleries_and_nothing_else(): void {
		$anonymous = Actor::anonymous();
		$published = $this->repository->create( new NewGallery( 'P', $this->stranger, '', GalleryStatus::Publish ) );
		$private   = $this->repository->create( new NewGallery( 'X', $this->stranger, '', GalleryStatus::Private ) );
		$draft     = $this->repository->create( new NewGallery( 'D', $this->stranger ) );

		$this->assertSame( RenderAccess::Render, $this->policy->embedAccess( $anonymous, $published ) );
		$this->assertSame( RenderAccess::Nothing, $this->policy->embedAccess( $anonymous, $private ) );
		$this->assertSame( RenderAccess::Nothing, $this->policy->embedAccess( $anonymous, $draft ) );
		foreach ( array( $published, $private, $draft ) as $gallery ) {
			$this->assertFalse( $this->policy->canView( $anonymous, $gallery ) );
			$this->assertFalse( $this->policy->canEdit( $anonymous, $gallery ) );
			$this->assertFalse( $this->policy->canDelete( $anonymous, $gallery ) );
			$this->assertFalse( $this->policy->canPublish( $anonymous, $gallery ) );
		}
		$this->assertFalse( $this->policy->canCreate( $anonymous ) );
		$this->assertFalse( $this->policy->canUseAttachment( $anonymous, $this->makeAttachment() ) );
		$this->assertFalse( $this->policy->canEditAttachment( $anonymous, $this->makeAttachment() ) );
	}

	public function test_a_published_password_protected_gallery_asks_for_the_password_until_unlocked(): void {
		$actor   = new Actor( $this->users['administrator'] );
		$gallery = $this->repository->create( new NewGallery( 'P', $this->stranger, '', GalleryStatus::Publish ) );
		wp_update_post(
			array(
				'ID'            => $gallery->id,
				'post_password' => 'secret',
			)
		);
		$gallery = $this->repository->find( $gallery->id );
		$this->assertNotNull( $gallery );

		$this->assertTrue( $gallery->passwordProtected );
		$this->assertSame( RenderAccess::PasswordForm, $this->policy->embedAccess( $actor, $gallery ), 'No editor bypass.' );
		$this->assertSame( RenderAccess::PasswordForm, $this->policy->embedAccess( Actor::anonymous(), $gallery ) );
		$this->assertSame( RenderAccess::Render, $this->policy->embedAccess( Actor::anonymous(), $gallery, true ) );
	}

	public function test_password_unlock_uses_the_core_password_logic(): void {
		$open = $this->repository->create( new NewGallery( 'Open', 1, '', GalleryStatus::Publish ) );
		$this->assertTrue( $this->policy->isPasswordUnlocked( $open ), 'Without a password there is nothing to unlock.' );

		$locked = $this->repository->create( new NewGallery( 'Locked', 1, '', GalleryStatus::Publish ) );
		wp_update_post(
			array(
				'ID'            => $locked->id,
				'post_password' => 'secret',
			)
		);
		$locked = $this->repository->find( $locked->id );
		$this->assertNotNull( $locked );
		$this->assertFalse( $this->policy->isPasswordUnlocked( $locked ), 'No cookie: still locked.' );

		add_filter( 'post_password_required', '__return_false' );
		$this->assertTrue( $this->policy->isPasswordUnlocked( $locked ), 'The cookie check of core says unlocked.' );
		remove_filter( 'post_password_required', '__return_false' );
	}

	public function test_create_and_publish_capabilities(): void {
		$expected = array(
			'administrator' => array( true, true ),
			'editor'        => array( true, true ),
			'author'        => array( true, true ),
			'contributor'   => array( true, false ),
			'subscriber'    => array( false, false ),
		);
		$draft    = $this->repository->create( new NewGallery( 'D', 0 ) );
		foreach ( $expected as $role => $answers ) {
			$actor = new Actor( $this->users[ $role ] );
			$own   = $this->repository->create( new NewGallery( 'Own', $this->users[ $role ] ) );

			$this->assertSame( $answers[0], $this->policy->canCreate( $actor ), "$role create" );
			$this->assertSame( $answers[1], $this->policy->canPublish( $actor, $own ), "$role publish own" );
		}
		$this->assertTrue( $this->policy->canPublish( new Actor( $this->users['editor'] ), $draft ), 'Editors publish foreign galleries.' );
		$this->assertFalse( $this->policy->canPublish( new Actor( $this->users['author'] ), $draft ), 'Authors cannot publish foreign galleries.' );
	}

	public function test_the_actor_is_never_read_from_the_current_user(): void {
		$gallery = $this->repository->create( new NewGallery( 'G', $this->users['author'] ) );

		wp_set_current_user( $this->users['administrator'] );
		$subscriber = new Actor( $this->users['subscriber'] );
		$this->assertFalse( $this->policy->canEdit( $subscriber, $gallery ) );
		$this->assertFalse( $this->policy->canCreate( $subscriber ) );
		$this->assertFalse( $this->policy->canView( $subscriber, $gallery ) );

		wp_set_current_user( $this->users['subscriber'] );
		$admin = new Actor( $this->users['administrator'] );
		$this->assertTrue( $this->policy->canEdit( $admin, $gallery ) );
		$this->assertTrue( $this->policy->canDelete( $admin, $gallery ) );
	}

	public function test_an_in_memory_entity_cannot_grant_itself_ownership(): void {
		$gallery = $this->repository->create( new NewGallery( 'G', $this->stranger ) );
		$forged  = $gallery->withOwner( $this->users['author'] );

		$this->assertFalse( $this->policy->canEdit( new Actor( $this->users['author'] ), $forged ), 'Ownership comes from the database.' );
	}

	public function test_unknown_galleries_are_denied_to_everyone(): void {
		$gallery = $this->repository->create( new NewGallery( 'G', $this->users['administrator'] ) );
		$this->repository->delete( $gallery->id );

		foreach ( self::ROLES as $role ) {
			$actor = new Actor( $this->users[ $role ] );
			$this->assertFalse( $this->policy->canEdit( $actor, $gallery ), $role );
			$this->assertFalse( $this->policy->canDelete( $actor, $gallery ), $role );
			$this->assertFalse( $this->policy->canView( $actor, $gallery ), $role );
		}
	}

	/**
	 * Expected [use, editAttachment] per role for an own and a foreign unattached image (public in the library).
	 *
	 * @return array<string, array{string, bool, bool, bool}>
	 */
	public static function attachment_matrix(): array {
		return array(
			'administrator own'     => array( 'administrator', true, true, true ),
			'administrator foreign' => array( 'administrator', false, true, true ),
			'editor own'            => array( 'editor', true, true, true ),
			'editor foreign'        => array( 'editor', false, true, true ),
			'author own'            => array( 'author', true, true, true ),
			'author foreign'        => array( 'author', false, true, false ),
			// No access to the Media Library without upload_files (a contributor may still edit what they own).
			'contributor own'       => array( 'contributor', true, false, true ),
			'contributor foreign'   => array( 'contributor', false, false, false ),
			'subscriber own'        => array( 'subscriber', true, false, false ),
			'subscriber foreign'    => array( 'subscriber', false, false, false ),
		);
	}

	/**
	 * @dataProvider attachment_matrix
	 */
	public function test_attachments_can_be_used_when_readable_and_edited_only_with_edit_rights( string $role, bool $own, bool $can_use, bool $can_edit ): void {
		$actor = new Actor( $this->users[ $role ] );
		$image = $this->makeAttachment( $own ? $this->users[ $role ] : $this->stranger );

		$this->assertSame( $can_use, $this->policy->canUseAttachment( $actor, $image ), 'use' );
		$this->assertSame( $can_edit, $this->policy->canEditAttachment( $actor, $image ), 'edit attachment' );
	}

	public function test_private_uploads_of_others_stay_out_of_reach(): void {
		$image  = $this->makeAttachment( $this->stranger, 'image/png', array( 'post_status' => 'private' ) );
		$result = array();
		foreach ( self::ROLES as $role ) {
			$result[ $role ] = $this->policy->canUseAttachment( new Actor( $this->users[ $role ] ), $image );
		}

		$this->assertSame(
			array(
				'administrator' => true,
				'editor'        => true,
				'author'        => false,
				'contributor'   => false,
				'subscriber'    => false,
			),
			$result
		);
		$this->assertTrue( $this->policy->canUseAttachment( new Actor( $this->stranger ), $image ), 'The owner reads their private upload.' );
	}

	public function test_only_allowed_raster_images_that_exist_can_be_used(): void {
		$admin = new Actor( $this->users['administrator'] );
		$post  = self::factory()->post->create();

		$this->assertTrue( $this->policy->canUseAttachment( $admin, $this->makeAttachment( 0, 'image/png' ) ) );
		$this->assertTrue( $this->policy->canUseAttachment( $admin, $this->makeAttachment( 0, 'image/gif' ) ) );
		$this->assertFalse( $this->policy->canUseAttachment( $admin, $this->makeAttachment( 0, 'image/svg+xml' ) ), 'SVG is excluded in 1.0.' );
		$this->assertFalse( $this->policy->canUseAttachment( $admin, $this->makeAttachment( 0, 'image/heic' ) ), 'HEIC is excluded in 1.0.' );
		$this->assertFalse( $this->policy->canUseAttachment( $admin, $this->makeAttachment( 0, 'application/pdf' ) ) );
		$this->assertFalse( $this->policy->canUseAttachment( $admin, $this->makeAttachment( 0, 'image/jpeg', array( 'post_status' => 'trash' ) ) ), 'Trashed media is not offered.' );
		$this->assertFalse( $this->policy->canUseAttachment( $admin, $post ), 'A post is no attachment.' );
		$this->assertFalse( $this->policy->canUseAttachment( $admin, 987654 ) );
		$this->assertFalse( $this->policy->canUseAttachment( $admin, 0 ) );
		$this->assertFalse( $this->policy->canUseAttachment( $admin, -1 ) );
	}

	public function test_attachment_editing_never_extends_to_other_post_types(): void {
		$admin   = new Actor( $this->users['administrator'] );
		$gallery = $this->repository->create( new NewGallery( 'G', $this->users['administrator'] ) );
		$post    = self::factory()->post->create();

		$this->assertFalse( $this->policy->canEditAttachment( $admin, $gallery->id ), 'edit_post on a gallery is not edit rights on an attachment.' );
		$this->assertFalse( $this->policy->canEditAttachment( $admin, $post ) );
		$this->assertFalse( $this->policy->canEditAttachment( $admin, 987654 ) );
		$this->assertFalse( $this->policy->canEditAttachment( $admin, 0 ) );
	}

	public function test_the_post_type_capability_map_is_used_when_the_type_is_missing(): void {
		unregister_post_type( \SWGallery\WordPress\PostTypes::GALLERY );
		try {
			$this->assertTrue( $this->policy->canCreate( new Actor( $this->users['author'] ) ), 'Falls back to edit_posts.' );
			$this->assertFalse( $this->policy->canCreate( new Actor( $this->users['subscriber'] ) ) );
		} finally {
			\SWGallery\WordPress\PostTypes::register();
		}
	}
}
