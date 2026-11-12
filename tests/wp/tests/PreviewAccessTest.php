<?php
/**
 * The explicit preview rule (FU-13) and the new-gallery publish rule (FU-10) of the WordPress access policy.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Access\RenderAccess;
use SWGallery\Tests\Support\RenderFixtures;
use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\Security\WpGalleryAccessPolicy;

class PreviewAccessTest extends SwgTestCase {

	use RenderFixtures;

	/** @var array<string, int> */
	private array $users = array();

	public function set_up(): void {
		parent::set_up();
		$this->purgeGalleries();
		Services::reset();
		foreach ( array( 'author', 'other', 'contributor', 'editor', 'administrator', 'subscriber' ) as $key ) {
			$this->users[ $key ] = $this->makeUser( 'other' === $key ? 'author' : $key );
		}
		$this->users['visitor'] = 0;
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->purgeGalleries();
		Services::reset();
		parent::tear_down();
	}

	/**
	 * @return iterable<string, array{string, string, string, bool}>
	 */
	public static function preview_matrix(): iterable {
		// status, password, user, expected: whether the preview renders. Galleries are owned by "author".
		$can_edit = array( 'author', 'editor', 'administrator' );
		foreach ( array( 'draft', 'pending', 'private', 'publish', 'trash' ) as $status ) {
			foreach ( array( 'visitor', 'subscriber', 'contributor', 'other', 'author', 'editor', 'administrator' ) as $user ) {
				yield "$status $user" => array( $status, '', $user, 'trash' !== $status && in_array( $user, $can_edit, true ) );
			}
		}
		foreach ( array( 'visitor', 'other', 'author', 'editor', 'administrator' ) as $user ) {
			yield "publish password $user" => array( 'publish', 'secret', $user, in_array( $user, $can_edit, true ) );
		}
	}

	/**
	 * @dataProvider preview_matrix
	 */
	public function test_the_preview_rule( string $status, string $password, string $user, bool $expected ): void {
		$id      = $this->makeGalleryPost( $this->users['author'], $status, $password );
		$gallery = Services::galleries()->find( $id );
		$this->assertNotNull( $gallery );

		$decision = Services::access()->previewAccess( new Actor( $this->users[ $user ] ), $gallery );

		$this->assertSame( $expected ? RenderAccess::Render : RenderAccess::Nothing, $decision, "$status/$password/$user" );
	}

	public function test_the_public_entry_points_keep_their_own_rule_for_the_same_gallery(): void {
		$id = $this->makeGalleryPost( $this->users['author'], 'draft' );
		$this->addItems( $id, array( $this->makeImage( 'One' ) ) );
		wp_set_current_user( $this->users['author'] );
		Services::reset();

		$shortcode = Services::gallery_renderer()->render( $id, EntryPoint::Shortcode );
		$preview   = Services::gallery_renderer()->render( $id, EntryPoint::Preview );

		$this->assertSame( RenderAccess::Diagnostic, $shortcode->access );
		$this->assertSame( RenderAccess::Render, $preview->access );
		$this->assertStringContainsString( '<img', $preview->html );
	}

	public function test_a_password_is_ignored_in_the_preview_for_editors_only(): void {
		$id = $this->makeGalleryPost( $this->users['author'], 'publish', 'secret' );
		$this->addItems( $id, array( $this->makeImage( 'One' ) ) );
		$this->lockPassword();

		wp_set_current_user( $this->users['editor'] );
		Services::reset();
		$editor = Services::gallery_renderer()->render( $id, EntryPoint::Preview );
		$this->assertSame( RenderAccess::Render, $editor->access );
		$this->assertStringNotContainsString( 'post_password', $editor->html );

		wp_set_current_user( $this->users['other'] );
		Services::reset();
		$this->assertSame( '', Services::gallery_renderer()->render( $id, EntryPoint::Preview )->html );
	}

	/**
	 * @return iterable<string, array{string, bool}>
	 */
	public static function publish_new_matrix(): iterable {
		yield 'administrator' => array( 'administrator', true );
		yield 'editor' => array( 'editor', true );
		yield 'author' => array( 'author', true );
		yield 'contributor' => array( 'contributor', false );
		yield 'subscriber' => array( 'subscriber', false );
		yield 'visitor' => array( 'visitor', false );
	}

	/**
	 * @dataProvider publish_new_matrix
	 */
	public function test_can_publish_new( string $user, bool $expected ): void {
		$this->assertSame( $expected, ( new WpGalleryAccessPolicy() )->canPublishNew( new Actor( $this->users[ $user ] ) ), $user );
	}
}
