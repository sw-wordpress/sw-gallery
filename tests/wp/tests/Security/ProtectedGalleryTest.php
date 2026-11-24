<?php
/**
 * Password protection for gallery resources and copies.
 *
 * @package sw-gallery
 */

require_once dirname( __DIR__, 2 ) . '/support/McpSecurityTestTrait.php';

use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Tests\Support\RestItemFixtures;
use SWGallery\WordPress\Mcp\TierPolicy;

class ProtectedGalleryTest extends SwgTestCase {

	use RestItemFixtures;
	use McpSecurityTestTrait;

	private const DESCRIPTION = 'Members meeting notes';
	private const PASSWORD    = 'fixture-password';

	public function set_up(): void {
		parent::set_up();
		$this->bootRest();
	}

	public function tear_down(): void {
		foreach ( array_keys( $this->ourAbilities() ) as $name ) {
			wp_unregister_ability( $name );
		}
		delete_option( TierPolicy::OPTION );
		$this->shutdownRest();
		parent::tear_down();
	}

	public function test_gallery_details_items_and_duplicate_require_edit_access_to_a_locked_gallery(): void {
		$owner  = $this->makeUser( 'author' );
		$reader = $this->makeUser( 'contributor' );
		$id     = $this->protected_gallery( $owner );
		$path   = "/galleries/$id";

		$this->assertSame( 404, $this->call( 'GET', $path, array(), $reader )->get_status() );
		$this->assertSame( 404, $this->call( 'GET', "$path/items", array(), $reader )->get_status() );
		$this->assertSame( 404, $this->call( 'POST', "$path/duplicate", array(), $reader )->get_status() );

		$this->assertSame( self::DESCRIPTION, $this->data( $this->call( 'GET', $path, array(), $owner ) )['description'] );
		$this->assertCount( 1, $this->data( $this->call( 'GET', "$path/items", array(), $owner ) ) );
		$copy = $this->call( 'POST', "$path/duplicate", array(), $owner );
		$this->assertSame( 201, $copy->get_status() );
		$this->assertTrue( $this->data( $copy )['passwordProtected'] );
		$this->assertSame( self::PASSWORD, get_post_field( 'post_password', $this->data( $copy )['id'], 'raw' ) );
	}

	public function test_collection_does_not_disclose_protected_fields_or_match_protected_descriptions(): void {
		$owner  = $this->makeUser( 'author' );
		$reader = $this->makeUser( 'editor' );
		get_user_by( 'id', $reader )->add_cap( 'edit_published_posts', false );
		$id = $this->protected_gallery( $owner );

		$rows = $this->data( $this->call( 'GET', '/galleries', array( 'search' => 'Club gallery' ), $reader ) );
		$this->assertSame( array( $id ), array_column( $rows, 'id' ) );
		$this->assertSame( '', $rows[0]['description'] );
		$this->assertSame( 0, $rows[0]['itemCount'] );
		$this->assertNull( $rows[0]['coverAttachmentId'] );
		$this->assertFalse( $rows[0]['canEdit'] );
		$owner_row = $this->data( $this->call( 'GET', '/galleries', array(), $owner ) )[0];
		$this->assertSame( self::DESCRIPTION, $owner_row['description'] );
		$this->assertNotNull( $owner_row['coverAttachmentId'] );
		$this->assertSame( 1, $owner_row['itemCount'] );

		$public = $this->makeGallery( $owner, GalleryStatus::Publish, 'Public gallery' );
		wp_update_post( array( 'ID' => $public, 'post_content' => self::DESCRIPTION ) );
		$match = $this->call( 'GET', '/galleries', array( 'search' => self::DESCRIPTION ), $reader );
		$this->assertSame( array( $public ), array_column( $this->data( $match ), 'id' ) );
		$this->assertSame( 1, (int) $match->get_headers()['X-WP-Total'] );
	}

	public function test_mcp_shares_the_edit_requirement_for_a_locked_gallery(): void {
		if ( ! class_exists( '\\WP\\MCP\\Plugin', false ) ) {
			$this->markTestSkipped( 'The MCP Adapter is not available.' );
		}
		$owner  = $this->makeUser( 'author' );
		$reader = $this->makeUser( 'contributor' );
		$id     = $this->protected_gallery( $owner );
		$this->reregisterAbilities( 'write' );

		foreach ( array( 'get-gallery', 'list-items', 'duplicate-gallery' ) as $ability ) {
			$permission = $this->checkAbility( $ability, array( 'id' => $id ), $reader );
			$this->assertWPError( $permission );
			$this->assertSame( 'swg_gallery_not_found', $permission->get_error_code() );
			$this->assertTrue( $this->checkAbility( $ability, array( 'id' => $id ), $owner ) );
		}
	}

	private function protected_gallery( int $owner ): int {
		$id          = $this->makeGallery( $owner, GalleryStatus::Publish, 'Club gallery' );
		$attachments = $this->seedItems( $id, $owner, 1 );
		wp_update_post(
			array(
				'ID'            => $id,
				'post_content'  => self::DESCRIPTION,
				'post_password' => self::PASSWORD,
			)
		);
		update_post_meta( $id, '_thumbnail_id', $attachments[0] );
		return $id;
	}
}
