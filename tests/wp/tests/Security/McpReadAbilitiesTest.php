<?php
/**
 * Behaviour of the MCP read abilities (MCP-02): visibility parity with REST, complete layout schemas, paging with a byte
 * budget, no URLs. Authorisation lives in McpAbilitySecurityTest.
 *
 * @package sw-gallery
 */

require_once dirname( __DIR__, 2 ) . '/support/McpSecurityTestTrait.php';

use SWGallery\Core\Contract\LayoutRegistryInterface;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Settings\SettingsSchemaValidator;
use SWGallery\Tests\Support\RestItemFixtures;
use SWGallery\Tests\Support\StubLayout;
use SWGallery\WordPress\Mcp\Paging;
use SWGallery\WordPress\Mcp\TierPolicy;

class McpReadAbilitiesTest extends SwgTestCase {

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
		foreach ( array( 'author', 'other', 'editor', 'contributor' ) as $key ) {
			$this->users[ $key ] = $this->makeUser( 'other' === $key ? 'author' : $key );
		}
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		foreach ( array_keys( $this->ourAbilities() ) as $name ) {
			wp_unregister_ability( $name );
		}
		delete_option( TierPolicy::OPTION );
		$this->shutdownRest();
		parent::tear_down();
	}

	private function register(): void {
		$this->reregisterAbilities( 'read' );
	}

	/** @return list<int> */
	private function ids( array $items ): array {
		return array_map( 'intval', array_column( $items, 'id' ) );
	}

	public function test_gallery_lists_follow_the_rest_visibility_rules(): void {
		$mine    = $this->makeGallery( $this->users['author'], GalleryStatus::Draft, 'Mine' );
		$foreign = $this->makeGallery( $this->users['other'], GalleryStatus::Draft, 'Foreign draft' );
		$this->register();

		$own = $this->runAbility( 'list-galleries', array(), $this->users['author'] );
		$this->assertSame( array( $mine ), $this->ids( $own['items'] ) );
		$this->assertSame( 1, $own['total'] );
		$this->assertNull( $own['next'] );
		$this->assertArrayNotHasKey( 'coverUrl', $own['items'][0] );

		$all = $this->runAbility( 'list-galleries', array(), $this->users['editor'] );
		$this->assertEqualsCanonicalizing( array( $mine, $foreign ), $this->ids( $all['items'] ) );

		$rest = $this->call( 'GET', '/galleries', array(), $this->users['editor'] );
		$this->assertEqualsCanonicalizing( $this->ids( $this->data( $rest ) ), $this->ids( $all['items'] ), 'same galleries as REST' );

		$filtered = $this->runAbility( 'list-galleries', array( 'owner' => $this->users['other'] ), $this->users['author'] );
		$this->assertSame( array( $mine ), $this->ids( $filtered['items'] ), 'the owner filter is forced to the caller' );

		// Contributors may delete their own drafts, so they may list the trash, but only their own entries (as REST).
		$theirs = $this->makeGallery( $this->users['contributor'], GalleryStatus::Draft, 'Contributor draft' );
		wp_trash_post( $theirs );
		wp_trash_post( $foreign );
		$trash = $this->runAbility( 'list-galleries', array( 'status' => array( 'trash' ) ), $this->users['contributor'] );
		$this->assertSame( array( $theirs ), $this->ids( $trash['items'] ) );
	}

	public function test_gallery_list_pages_without_duplicates_or_gaps(): void {
		$created = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$created[] = $this->makeGallery( $this->users['author'], GalleryStatus::Draft, 'G' . $i );
		}
		$this->register();
		$seen = array();
		$page = 1;
		do {
			$result = $this->runAbility( 'list-galleries', array( 'per_page' => 2, 'page' => $page ), $this->users['author'] );
			$this->assertSame( 5, $result['total'] );
			$this->assertSame( 3, $result['totalPages'] );
			$seen   = array_merge( $seen, $this->ids( $result['items'] ) );
			$next   = $result['next'];
			$page   = null === $next ? 0 : $next['page'];
		} while ( 0 !== $page );
		$this->assertEqualsCanonicalizing( $created, $seen );
		$this->assertCount( 5, array_unique( $seen ) );
	}

	public function test_items_are_listed_in_order_with_revision_and_without_urls(): void {
		$gallery     = $this->makeGallery( $this->users['author'] );
		$attachments = $this->seedItems( $gallery, $this->users['author'], 3 );
		$this->register();

		$result = $this->runAbility( 'list-items', array( 'id' => $gallery, 'per_page' => 2 ), $this->users['author'] );
		$this->assertSame( 3, $result['total'] );
		$this->assertSame( $this->revisionOf( $gallery ), $result['revision'] );
		$this->assertSame( array( $attachments[0], $attachments[1] ), array_column( $result['items'], 'attachmentId' ) );
		$this->assertSame( array( 'page' => 2, 'offset' => 0 ), $result['next'] );
		$this->assertDoesNotMatchRegularExpression( '#https?://|thumbnailUrl|fullUrl#', (string) wp_json_encode( $result ) );

		$last = $this->runAbility( 'list-items', array( 'id' => $gallery, 'per_page' => 2, 'page' => 2 ), $this->users['author'] );
		$this->assertSame( array( $attachments[2] ), array_column( $last['items'], 'attachmentId' ) );
		$this->assertNull( $last['next'] );

		$rest = $this->data( $this->call( 'GET', '/galleries/' . $gallery . '/items', array(), $this->users['author'] ) );
		$this->assertSame( array_column( $rest, 'id' ), array_merge( array_column( $result['items'], 'id' ), array_column( $last['items'], 'id' ) ) );
	}

	public function test_a_missing_attachment_stays_listed_as_missing(): void {
		$gallery     = $this->makeGallery( $this->users['author'] );
		$attachments = $this->seedItems( $gallery, $this->users['author'], 2 );
		wp_update_post( array( 'ID' => $attachments[0], 'post_status' => 'trash' ) );
		$this->register();
		$result = $this->runAbility( 'list-items', array( 'id' => $gallery ), $this->users['author'] );
		$this->assertSame( array( true, false ), array_column( $result['items'], 'missing' ) );
		$this->assertNull( $result['items'][0]['global'] );
	}

	public function test_layout_pages_equal_the_rest_registry_including_third_party_layouts(): void {
		add_action(
			'swg_register_layouts',
			static function ( LayoutRegistryInterface $registry ): void {
				$registry->register( new StubLayout( 'partner-wall', array( 'type' => 'object', 'properties' => array( 'rows' => array( 'type' => 'integer' ) ) ) ) );
				$registry->register( new StubLayout( 'bare-wall', array( 'type' => 'object', 'properties' => array() ) ) );
			}
		);
		\SWGallery\WordPress\Infrastructure\Services::reset();
		global $wp_rest_server;
		$wp_rest_server = null;
		rest_get_server();
		$this->register();

		$layouts = array();
		$page    = 1;
		do {
			$result = $this->runAbility( 'list-layouts', array( 'per_page' => 3, 'page' => $page ), $this->users['editor'] );
			$this->assertSame( SettingsSchemaValidator::BREAKPOINTS, $result['breakpoints'] );
			$this->assertSame( 'grid', $result['fallbackId'] );
			$layouts = array_merge( $layouts, $result['layouts'] );
			$page    = null === $result['next'] ? 0 : $result['next']['page'];
		} while ( 0 !== $page );

		$rest = $this->data( $this->call( 'GET', '/layouts', array(), $this->users['editor'] ) );
		$this->assertSame( count( $rest['layouts'] ), count( $layouts ) );
		$this->assertSame( wp_json_encode( $rest['layouts'] ), wp_json_encode( $layouts ), 'complete schemas, labels and empty objects survive JSON encoding' );
		$this->assertContains( 'partner-wall', array_column( $layouts, 'id' ) );
		$this->assertStringContainsString( '"properties":{}', (string) wp_json_encode( $layouts ), 'an empty schema stays an object' );
	}

	public function test_discovery_schemas_use_the_rest_and_domain_enums(): void {
		$this->register();
		$list    = wp_get_ability( 'sw-gallery/list-galleries' )->get_input_schema();
		$rest    = rest_get_server()->get_routes()['/swg/v1/galleries'][0]['args']['status']['items']['enum'];
		$this->assertSame( $rest, $list['properties']['status']['items']['enum'] );
		$media   = wp_get_ability( 'sw-gallery/list-media' )->get_input_schema()['properties'];
		$routes  = rest_get_server()->get_routes()['/swg/v1/media'][0]['args'];
		$this->assertSame( $routes['orderby']['enum'], $media['orderby']['enum'] );
		$this->assertSame( $routes['order']['enum'], $media['order']['enum'] );
		$out = wp_get_ability( 'sw-gallery/get-gallery' )->get_output_schema()['properties'];
		$this->assertContains( 'future', $out['status']['enum'], 'reads expose every stored status' );
		$this->assertContains( 'manual', $out['sortMode']['enum'] );
		$this->assertContains( 'fade', $out['hover']['properties']['animation']['enum'] );
	}

	public function test_media_list_shows_only_usable_images_without_urls(): void {
		$own    = $this->makeAttachment( $this->users['author'] );
		$theirs = $this->makeAttachment( $this->users['other'] );
		$secret = $this->makeGallery( $this->users['editor'], GalleryStatus::Private );
		$hidden = $this->makeAttachment( $this->users['editor'], 'image/jpeg', array( 'post_parent' => $secret ) );
		$this->register();

		$result = $this->runAbility( 'list-media', array(), $this->users['author'] );
		$ids    = $this->ids( $result['items'] );
		$this->assertContains( $own, $ids );
		$this->assertNotContains( $hidden, $ids, 'attachments of private parents stay invisible' );
		$this->assertDoesNotMatchRegularExpression( '#https?://|thumbnailUrl|fullUrl#', (string) wp_json_encode( $result ) );

		$forced = $this->runAbility( 'list-media', array( 'uploadedBy' => $this->users['other'] ), $this->users['author'] );
		$this->assertNotContains( $theirs, $this->ids( $forced['items'] ), 'the uploader filter is forced to the caller' );
		$this->assertContains( $own, $this->ids( $forced['items'] ) );

		$rest = $this->data( $this->call( 'GET', '/media', array(), $this->users['author'] ) );
		$this->assertEqualsCanonicalizing( $this->ids( $rest ), $ids );
	}

	public function test_render_gallery_reports_the_outcome_but_no_markup(): void {
		$gallery = $this->makeGallery( $this->users['author'] );
		$this->seedItems( $gallery, $this->users['author'], 2 );
		$this->register();
		$result = $this->runAbility( 'render-gallery', array( 'id' => $gallery ), $this->users['author'] );
		$this->assertSame( array( 'layoutId', 'page', 'totalPages', 'totalItems', 'diagnostics' ), array_keys( $result ) );
		$this->assertSame( 2, $result['totalItems'] );
		$this->assertDoesNotMatchRegularExpression( '#<[a-z]|https?://#i', (string) wp_json_encode( $result ) );
	}

	public function test_paging_resumes_exactly_under_a_small_budget(): void {
		$records = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$records[] = array( 'id' => $i, 'text' => str_repeat( 'x', 300 ) );
		}
		$budget = 2048 + strlen( (string) wp_json_encode( $records[0] ) ) * 3 + 10; // three whole records fit.
		$seen   = array();
		$page   = 1;
		$offset = 0;
		$guard  = 0;
		do {
			$reply = Paging::build( 'items', $records, 7, $page, 7, $offset, $budget );
			$this->assertIsArray( $reply );
			$this->assertLessThanOrEqual( $budget, strlen( (string) wp_json_encode( $reply ) ) );
			$seen = array_merge( $seen, array_column( $reply['items'], 'id' ) );
			$next = $reply['next'];
			$this->assertNotNull( $next ?? array() ?: $reply['items'] );
			$page   = $next['page'] ?? 0;
			$offset = $next['offset'] ?? 0;
		} while ( null !== $next && ++$guard < 10 );
		$this->assertSame( array( 1, 2, 3, 4, 5, 6, 7 ), $seen );
	}

	public function test_a_single_oversized_record_is_an_explicit_error(): void {
		$reply = Paging::build( 'items', array( array( 'text' => str_repeat( 'x', 5000 ) ) ), 1, 1, 1, 0, 2048 + 100 );
		$this->assertInstanceOf( WP_Error::class, $reply );
		$this->assertSame( Paging::CODE_RECORD_TOO_LARGE, $reply->get_error_code() );
		$empty = Paging::build( 'items', array(), 0, 1, 20, 0, 4096 );
		$this->assertSame( array(), $empty['items'] );
		$this->assertNull( $empty['next'] );
	}
}
