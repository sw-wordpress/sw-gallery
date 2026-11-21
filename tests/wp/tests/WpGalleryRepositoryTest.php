<?php
/**
 * WordPress specifics of the gallery repository: status mapping, password flag, private post type, sanitising,
 * settings storage, trash semantics and the guarantee that attachments are never touched.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Gallery\GalleryQuery;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Gallery\NewGallery;
use SWGallery\Core\Domain\Gallery\HoverAnimation;
use SWGallery\Core\Domain\Gallery\HoverEffect;
use SWGallery\Core\Domain\Gallery\SortMode;
use SWGallery\Core\Exception\GalleryNotFoundException;
use SWGallery\Tests\Support\WpFixtures;
use SWGallery\WordPress\Database\Schema;
use SWGallery\WordPress\PostTypes;
use SWGallery\WordPress\Repository\WpdbGalleryItemRepository;
use SWGallery\WordPress\Repository\WpdbGalleryRelations;
use SWGallery\WordPress\Repository\WpGalleryRepository;

class WpGalleryRepositoryTest extends SwgTestCase {

	use WpFixtures;

	private WpGalleryRepository $repository;

	public function set_up(): void {
		parent::set_up();
		$this->purgeGalleries();
		$this->repository = new WpGalleryRepository( new WpdbGalleryRelations() );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->purgeGalleries();
		parent::tear_down();
	}

	public function test_post_type_is_private_slugless_and_without_revisions(): void {
		$type = get_post_type_object( PostTypes::GALLERY );

		$this->assertNotNull( $type );
		$this->assertFalse( $type->public );
		$this->assertFalse( $type->publicly_queryable );
		$this->assertFalse( $type->rewrite );
		$this->assertFalse( $type->has_archive );
		$this->assertFalse( is_post_type_viewable( $type ) );
		$this->assertFalse( post_type_supports( PostTypes::GALLERY, 'revisions' ), 'Decision F-09.' );
		$this->assertTrue( post_type_supports( PostTypes::GALLERY, 'thumbnail' ) );
		$this->assertFalse( get_post_type_archive_link( PostTypes::GALLERY ) );
	}

	public function test_all_meta_keys_are_registered_with_schemas_and_stay_out_of_rest(): void {
		$keys = array(
			PostTypes::META_REV            => 'integer',
			PostTypes::META_LAYOUT         => 'string',
			PostTypes::META_SETTINGS       => 'string',
			PostTypes::META_SORT_MODE      => 'string',
			PostTypes::META_PER_PAGE       => 'integer',
			PostTypes::META_HOVER          => 'string',
			PostTypes::META_SCHEMA_VERSION => 'integer',
		);
		foreach ( $keys as $key => $type ) {
			$registered = get_registered_meta_keys( 'post', PostTypes::GALLERY );
			$this->assertArrayHasKey( $key, $registered, $key );
			$this->assertSame( $type, $registered[ $key ]['type'], $key );
			$this->assertTrue( $registered[ $key ]['single'], $key );
			$this->assertFalse( $registered[ $key ]['show_in_rest'], $key );
		}
		$this->assertSame( '{}', get_registered_meta_keys( 'post', PostTypes::GALLERY )[ PostTypes::META_SETTINGS ]['default'] );
		$this->assertSame( 'manual', get_registered_meta_keys( 'post', PostTypes::GALLERY )[ PostTypes::META_SORT_MODE ]['default'] );
	}

	public function test_meta_auth_callbacks_follow_edit_rights_and_protect_the_revision(): void {
		$owner    = $this->makeUser( 'author' );
		$stranger = $this->makeUser( 'author' );
		$gallery  = $this->repository->create( new NewGallery( 'G', $owner ) );

		wp_set_current_user( $owner );
		$this->assertTrue( current_user_can( 'edit_post_meta', $gallery->id, PostTypes::META_SETTINGS ) );
		$this->assertFalse( current_user_can( 'edit_post_meta', $gallery->id, PostTypes::META_REV ), 'Only the revision store writes the counter.' );
		wp_set_current_user( $stranger );
		$this->assertFalse( current_user_can( 'edit_post_meta', $gallery->id, PostTypes::META_SETTINGS ) );
	}

	public function test_meta_sanitisers_normalise_untrusted_values(): void {
		$this->assertSame( 'grid', PostTypes::sanitize_layout( '!!' ) );
		$this->assertSame( 'grid', PostTypes::sanitize_layout( array() ) );
		$this->assertSame( 'masonry', PostTypes::sanitize_layout( 'Masonry' ) );
		$this->assertSame( '{}', PostTypes::sanitize_settings( 'not json' ) );
		$this->assertSame( '{}', PostTypes::sanitize_settings( 5 ) );
		$this->assertSame( '{"a":1}', PostTypes::sanitize_settings( '{"a":1}' ) );
		$this->assertSame( 'manual', PostTypes::sanitize_sort_mode( 'random' ) );
		$this->assertSame( 'manual', PostTypes::sanitize_sort_mode( 5 ) );
		$this->assertSame( 'title_asc', PostTypes::sanitize_sort_mode( 'title_asc' ) );
		$this->assertSame( 1, PostTypes::sanitize_schema_version( '-4' ) );
		$this->assertSame( 1, PostTypes::sanitize_schema_version( array() ) );
		$this->assertSame( 7, PostTypes::sanitize_schema_version( '7' ) );
	}

	public function test_every_gallery_status_maps_to_the_post_status_and_back(): void {
		foreach ( array( GalleryStatus::Publish, GalleryStatus::Private, GalleryStatus::Draft, GalleryStatus::Pending ) as $status ) {
			$gallery = $this->repository->create( new NewGallery( 'S ' . $status->value, 1, '', $status ) );

			$this->assertSame( $status->value, get_post_status( $gallery->id ) );
			$this->assertSame( $status, $this->repository->find( $gallery->id )?->status );
		}
	}

	public function test_scheduled_galleries_cannot_be_created_and_only_kept_when_already_scheduled(): void {
		try {
			$this->repository->create( new NewGallery( 'F', 1, '', GalleryStatus::Future ) );
			$this->fail( 'A scheduled gallery needs a date.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 0, $this->repository->search( new GalleryQuery( statuses: array( GalleryStatus::Future ) ) )->total );
		}

		$draft = $this->repository->create( new NewGallery( 'D', 1 ) );
		try {
			$this->repository->update( $draft->withStatus( GalleryStatus::Future ) );
			$this->fail( 'A draft cannot become scheduled without a date.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( GalleryStatus::Draft, $this->repository->find( $draft->id )?->status );
		}

		$scheduled = self::factory()->post->create(
			array(
				'post_type'   => PostTypes::GALLERY,
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		$found     = $this->repository->find( $scheduled );
		$this->assertSame( GalleryStatus::Future, $found?->status );
		$this->repository->update( $found->withTitle( 'Renamed' ) );
		$this->assertSame( 'future', get_post_status( $scheduled ) );
		$this->assertSame( array( $scheduled ), array_map( static fn( $g ): int => $g->id, $this->repository->search( new GalleryQuery( statuses: array( GalleryStatus::Future ) ) )->items ) );
	}

	public function test_unknown_post_statuses_are_read_as_draft(): void {
		$id = self::factory()->post->create(
			array(
				'post_type'   => PostTypes::GALLERY,
				'post_status' => 'auto-draft',
			)
		);

		$this->assertSame( GalleryStatus::Draft, $this->repository->find( $id )?->status );
	}

	public function test_password_flag_comes_from_the_post_password(): void {
		$gallery = $this->repository->create( new NewGallery( 'Locked', 1, '', GalleryStatus::Publish ) );
		$this->assertFalse( $this->repository->find( $gallery->id )?->passwordProtected );

		wp_update_post(
			array(
				'ID'            => $gallery->id,
				'post_password' => 'secret',
			)
		);
		$this->assertTrue( $this->repository->find( $gallery->id )?->passwordProtected );

		// A repository update keeps the password: it is not part of the entity.
		$this->repository->update( $this->repository->find( $gallery->id )->withTitle( 'Still locked' ) );
		$this->assertSame( 'secret', get_post( $gallery->id )->post_password );
	}

	public function test_title_and_description_are_plain_text_and_keep_ampersands_and_newlines(): void {
		// Users without unfiltered_html would otherwise get kses-filtered ("&" becomes "&amp;").
		$author = $this->makeUser( 'author' );
		wp_set_current_user( $author );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );

		$gallery = $this->repository->create( new NewGallery( "<b>Tom</b> & 'Jerry'", $author, "Line 1 & <script>alert(1)</script>\nLine 2 \\ backslash" ) );
		$found   = $this->repository->find( $gallery->id );

		$this->assertSame( "Tom & 'Jerry'", $found?->title );
		$this->assertSame( "Line 1 & \nLine 2 \\ backslash", $found?->description );

		$this->repository->update( $found->withDescription( "A\r\nB" )->withTitle( 'T' ) );
		$this->assertSame( "A\nB", $this->repository->find( $gallery->id )?->description );
		$this->assertTrue( current_user_can( 'edit_post', $gallery->id ), 'The kses filters are restored for the current user.' );
		$this->assertNotFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
	}

	public function test_settings_roundtrip_keeps_unknown_keys_and_newer_schema_versions(): void {
		$gallery = $this->repository->create( new NewGallery( 'S', 1 ) );
		update_post_meta( $gallery->id, PostTypes::META_SETTINGS, wp_slash( '{"columns":3,"future":{"a":[1,2]},"text":"ä \"q\""}' ) );
		update_post_meta( $gallery->id, PostTypes::META_SCHEMA_VERSION, 9 );
		update_post_meta( $gallery->id, PostTypes::META_SORT_MODE, 'title_desc' );

		$loaded = $this->repository->find( $gallery->id );
		$this->assertSame( 9, $loaded?->settings->schemaVersion );
		$this->assertSame( SortMode::TitleDesc, $loaded->settings->sortMode );

		$this->repository->update( $loaded->withTitle( 'Renamed' ) );

		$again = $this->repository->find( $gallery->id );
		$this->assertSame( 9, $again?->settings->schemaVersion, 'A newer schema version is never downgraded.' );
		$this->assertSame(
			array(
				'columns' => 3,
				'future'  => array( 'a' => array( 1, 2 ) ),
				'text'    => 'ä "q"',
			),
			$again->settings->values
		);
	}

	public function test_per_page_roundtrips_and_null_removes_the_meta(): void {
		$gallery = $this->repository->create( new NewGallery( 'P', 1 ) );
		$this->assertNull( $this->repository->find( $gallery->id )?->settings->perPage );

		$this->repository->update( $gallery->withSettings( $gallery->settings->withPerPage( 12 ) ) );
		$loaded = $this->repository->find( $gallery->id );
		$this->assertSame( 12, $loaded?->settings->perPage );
		$this->assertSame( 12, (int) get_post_meta( $gallery->id, PostTypes::META_PER_PAGE, true ) );

		$this->repository->update( $loaded->withSettings( $loaded->settings->withPerPage( null ) ) );
		$this->assertNull( $this->repository->find( $gallery->id )?->settings->perPage );
		$this->assertFalse( metadata_exists( 'post', $gallery->id, PostTypes::META_PER_PAGE ) );
	}

	public function test_hover_roundtrips_and_null_removes_the_meta(): void {
		$gallery = $this->repository->create( new NewGallery( 'H', 1 ) );
		$this->assertNull( $this->repository->find( $gallery->id )?->settings->hover );

		$hover = new HoverEffect( true, false, HoverAnimation::SlideDown );
		$this->repository->update( $gallery->withSettings( $gallery->settings->withHover( $hover ) ) );
		$loaded = $this->repository->find( $gallery->id );
		$this->assertTrue( $hover->equals( $loaded?->settings->hover ) );
		$this->assertSame( '{"title":true,"caption":false,"animation":"slide-down"}', get_post_meta( $gallery->id, PostTypes::META_HOVER, true ) );

		$this->repository->update( $loaded->withSettings( $loaded->settings->withHover( null ) ) );
		$this->assertNull( $this->repository->find( $gallery->id )?->settings->hover );
		$this->assertFalse( metadata_exists( 'post', $gallery->id, PostTypes::META_HOVER ) );
	}

	public function test_invalid_stored_hover_means_site_default(): void {
		$gallery = $this->repository->create( new NewGallery( 'H', 1 ) );
		foreach ( array( 'not json', '"str"', '{"title":"yes"}', '{"animation":"spin"}', '[]x' ) as $value ) {
			update_post_meta( $gallery->id, PostTypes::META_HOVER, $value );
			$this->assertNull( $this->repository->find( $gallery->id )?->settings->hover, $value );
		}
		$this->assertSame( '', PostTypes::sanitize_hover( array( 1 ) ) );
		$this->assertSame( '{"title":false,"caption":true,"animation":"fade"}', PostTypes::sanitize_hover( '{"caption":true,"x":1}' ) );
	}

	public function test_invalid_stored_per_page_means_site_default(): void {
		$gallery = $this->repository->create( new NewGallery( 'P', 1 ) );
		foreach ( array( '0', '-3', '999', 'abc' ) as $value ) {
			update_post_meta( $gallery->id, PostTypes::META_PER_PAGE, $value );
			$this->assertNull( $this->repository->find( $gallery->id )?->settings->perPage, $value );
		}
		$this->assertSame( 0, PostTypes::sanitize_per_page( array( 5 ) ) );
		$this->assertSame( 200, PostTypes::sanitize_per_page( '200' ) );
	}

	public function test_corrupt_settings_fall_back_to_defaults(): void {
		global $wpdb;
		$gallery = $this->repository->create( new NewGallery( 'S', 1 ) );
		foreach (
			array(
				PostTypes::META_LAYOUT         => 'Bad Layout!',
				PostTypes::META_SETTINGS       => '"just a string"',
				PostTypes::META_SORT_MODE      => 'shuffle',
				PostTypes::META_SCHEMA_VERSION => '0',
			) as $key => $value
		) {
			$wpdb->update(
				$wpdb->postmeta,
				array( 'meta_value' => $value ),
				array(
					'post_id'  => $gallery->id,
					'meta_key' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				)
			);
		}
		wp_cache_delete( $gallery->id, 'post_meta' );

		$loaded = $this->repository->find( $gallery->id );

		$this->assertSame( GallerySettings::DEFAULT_LAYOUT, $loaded?->settings->layout );
		$this->assertSame( SortMode::Manual, $loaded->settings->sortMode );
		$this->assertSame( array(), $loaded->settings->values );
		$this->assertSame( 1, $loaded->settings->schemaVersion );
	}

	public function test_missing_settings_meta_reads_the_registered_defaults(): void {
		$id = self::factory()->post->create( array( 'post_type' => PostTypes::GALLERY ) );

		$settings = $this->repository->find( $id )?->settings;

		$this->assertSame( 'grid', $settings?->layout );
		$this->assertSame( SortMode::Manual, $settings->sortMode );
		$this->assertSame( array(), $settings->values );
	}

	public function test_cover_is_the_featured_image_and_any_id_is_stored(): void {
		$image   = $this->makeAttachment();
		$gallery = $this->repository->create( new NewGallery( 'C', 1 ) );

		$this->repository->update( $gallery->withCover( $image ) );
		$this->assertSame( $image, (int) get_post_thumbnail_id( $gallery->id ) );
		$this->assertSame( $image, $this->repository->find( $gallery->id )?->coverAttachmentId );

		$this->repository->update( $gallery->withCover( 424242 ) );
		$this->assertSame( 424242, $this->repository->find( $gallery->id )?->coverAttachmentId, 'Usability of a cover is the policy\'s decision, not the storage\'s.' );

		$this->repository->update( $gallery->withCover( null ) );
		$this->assertSame( 0, (int) get_post_thumbnail_id( $gallery->id ) );
	}

	public function test_search_is_case_insensitive_over_title_and_description_and_bounded(): void {
		$a = $this->repository->create( new NewGallery( 'Zürich Altstadt', 1, 'Fotos vom See' ) );
		$this->repository->create( new NewGallery( 'Berlin', 2, 'Mauer' ) );

		$this->assertSame( array( $a->id ), array_map( static fn( $g ): int => $g->id, $this->repository->search( new GalleryQuery( 'ALTSTADT' ) )->items ) );
		$this->assertSame( array( $a->id ), array_map( static fn( $g ): int => $g->id, $this->repository->search( new GalleryQuery( 'vom see' ) )->items ), 'The whole phrase is matched.' );
		$this->assertSame( 0, $this->repository->search( new GalleryQuery( 'nothing like this' ) )->total );
		$this->assertSame( 2, $this->repository->search( new GalleryQuery( '   ' ) )->total, 'A blank search is no filter.' );
		$this->assertSame( 0, $this->repository->search( new GalleryQuery( '%' ) )->total, 'LIKE wildcards are escaped.' );
	}

	public function test_search_is_independent_of_the_current_user_and_lists_private_galleries(): void {
		$private = $this->repository->create( new NewGallery( 'Hidden', 5, '', GalleryStatus::Private ) );
		wp_set_current_user( 0 );

		$page = $this->repository->search( new GalleryQuery( statuses: array( GalleryStatus::Private ) ) );

		$this->assertSame( array( $private->id ), array_map( static fn( $g ): int => $g->id, $page->items ) );
	}

	public function test_owner_filter_includes_owner_zero_and_the_default_excludes_the_trash(): void {
		$orphan = $this->repository->create( new NewGallery( 'No owner', 0 ) );
		$this->repository->create( new NewGallery( 'Owned', 3 ) );
		$trashed = $this->repository->create( new NewGallery( 'Gone', 0 ) );
		$this->repository->trash( $trashed->id );

		$page = $this->repository->search( new GalleryQuery( ownerId: 0 ) );

		$this->assertSame( array( $orphan->id ), array_map( static fn( $g ): int => $g->id, $page->items ) );
	}

	public function test_trash_keeps_relations_and_restore_returns_a_draft_whatever_it_was(): void {
		$items   = new WpdbGalleryItemRepository();
		$gallery = $this->repository->create( new NewGallery( 'T', 1, '', GalleryStatus::Publish ) );
		$items->append( $gallery->id, 11 );
		$items->append( $gallery->id, 12 );

		$this->repository->trash( $gallery->id );
		$this->repository->trash( $gallery->id );
		$this->assertSame( 'trash', get_post_status( $gallery->id ) );
		$this->assertSame( 2, $items->count( $gallery->id ), 'Trashing never removes relations.' );

		$restored = $this->repository->restore( $gallery->id );
		$this->assertSame( GalleryStatus::Draft, $restored->status );
		$this->assertSame( 2, $items->count( $gallery->id ) );
		$this->assertSame( GalleryStatus::Draft, $this->repository->restore( $gallery->id )->status, 'Restoring a live gallery changes nothing.' );
	}

	public function test_restore_of_a_published_gallery_does_not_downgrade_it(): void {
		$gallery = $this->repository->create( new NewGallery( 'P', 1, '', GalleryStatus::Publish ) );

		$this->assertSame( GalleryStatus::Publish, $this->repository->restore( $gallery->id )->status );
	}

	public function test_a_filter_that_changes_the_untrash_status_is_overruled(): void {
		$gallery = $this->repository->create( new NewGallery( 'T', 1, '', GalleryStatus::Publish ) );
		$this->repository->trash( $gallery->id );
		$filter = static fn(): string => 'publish';
		add_filter( 'wp_untrash_post_status', $filter );

		$this->assertSame( GalleryStatus::Draft, $this->repository->restore( $gallery->id )->status );
		remove_filter( 'wp_untrash_post_status', $filter );
	}

	public function test_trash_works_when_wordpress_has_the_trash_disabled(): void {
		$repository = new WpGalleryRepository( new WpdbGalleryRelations(), false );
		$items      = new WpdbGalleryItemRepository();
		$gallery    = $repository->create( new NewGallery( 'T', 1, '', GalleryStatus::Publish ) );
		$items->append( $gallery->id, 11 );

		$repository->trash( $gallery->id );

		$this->assertNotNull( $repository->find( $gallery->id ), 'wp_trash_post() would have deleted it.' );
		$this->assertSame( GalleryStatus::Trash, $repository->find( $gallery->id )?->status );
		$this->assertSame( 1, $items->count( $gallery->id ) );
		$restored = $repository->restore( $gallery->id );
		$this->assertSame( GalleryStatus::Draft, $restored->status );
		$this->assertSame( '', (string) get_post_meta( $gallery->id, '_wp_trash_meta_status', true ) );
	}

	public function test_the_trash_flag_defaults_to_the_wordpress_constant(): void {
		$this->assertTrue( EMPTY_TRASH_DAYS > 0, 'The suite runs with the trash enabled; the disabled branch is covered by the explicit flag.' );
		$gallery = $this->repository->create( new NewGallery( 'T', 1 ) );

		$this->repository->trash( $gallery->id );

		$this->assertSame( 'trash', get_post_status( $gallery->id ) );
	}

	public function test_update_refuses_a_trashed_gallery_and_unknown_ids(): void {
		$gallery = $this->repository->create( new NewGallery( 'T', 1 ) );
		$this->repository->trash( $gallery->id );

		try {
			$this->repository->update( $gallery->withTitle( 'x' ) );
			$this->fail( 'A trashed gallery must be restored first.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'T', $this->repository->find( $gallery->id )?->title );
		}
		$this->assertNull( $this->repository->find( 0 ) );
		$this->assertNull( $this->repository->find( -5 ) );
		$post = self::factory()->post->create();
		$this->assertNull( $this->repository->find( $post ), 'Posts of other types are not galleries.' );
		$this->expectException( GalleryNotFoundException::class );
		$this->repository->trash( $post );
	}

	public function test_duplicate_copies_overrides_settings_and_cover_but_no_files(): void {
		global $wpdb;
		$table  = Schema::items_table();
		$items  = new WpdbGalleryItemRepository();
		$image1 = $this->makeAttachment();
		$image2 = $this->makeAttachment();
		$source = $this->repository->create( new NewGallery( 'Source', 1, 'Desc', GalleryStatus::Publish, new GallerySettings( 'justified', SortMode::DateAsc, array( 'gap' => 4 ) ) ) );
		$this->repository->update( $source->withCover( $image2 ) );
		$first  = $items->append( $source->id, $image1 );
		$second = $items->append( $source->id, $image2 );
		$items->applyOrder( $source->id, array( $second->id, $first->id ) );
		$wpdb->update(
			$table,
			array(
				'title_override'   => 'T',
				'caption_override' => '',
				'settings'         => '{"zoom":true}',
			),
			array( 'id' => $first->id )
		);
		$attachments_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" );

		$copy = $this->repository->duplicate( $source->id, 'Copy', 8 );

		$rows = $items->findPage( $copy->id, 0, 10 );
		$this->assertSame( array( $image2, $image1 ), array_map( static fn( $i ): int => $i->attachmentId, $rows ), 'Order is kept.' );
		$this->assertSame( array( 1, 2 ), array_map( static fn( $i ): int => $i->position, $rows ) );
		$this->assertNull( $rows[0]->overrides->title );
		$this->assertSame( 'T', $rows[1]->overrides->title );
		$this->assertSame( '', $rows[1]->overrides->caption, 'An intentional blank override stays blank.' );
		$this->assertNull( $rows[1]->overrides->alt );
		$this->assertSame( '{"zoom":true}', $wpdb->get_var( $wpdb->prepare( "SELECT settings FROM {$table} WHERE gallery_id = %d AND attachment_id = %d", $copy->id, $image1 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertNotSame( $rows[0]->id, $second->id, 'The copy has its own rows.' );
		$this->assertSame( 2, $items->count( $source->id ) );
		$this->assertSame( array( 'gap' => 4 ), $copy->settings->values );
		$this->assertSame( SortMode::DateAsc, $copy->settings->sortMode );
		$this->assertSame( $image2, $copy->coverAttachmentId );
		$this->assertSame( 8, $copy->ownerId );
		$this->assertSame( GalleryStatus::Draft, $copy->status );
		$this->assertSame( $attachments_before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" ), 'No attachment was created.' );
	}

	public function test_duplicate_cleans_up_when_the_copy_fails(): void {
		global $wpdb;
		$source = $this->repository->create( new NewGallery( 'Source', 1 ) );
		( new WpdbGalleryItemRepository() )->append( $source->id, 11 );
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'swg_gallery'" );
		$fail   = static function ( string $query ): string {
			return str_starts_with( $query, 'INSERT INTO' ) && str_contains( $query, 'swg_gallery_items' ) ? 'SELECT * FROM swg_missing_table_for_copy' : $query;
		};
		add_filter( 'query', $fail );
		$wpdb->suppress_errors( true );
		try {
			$this->repository->duplicate( $source->id, 'Copy', 2 );
			$this->fail( 'The failing copy must raise an exception.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The gallery items could not be copied.', $e->getMessage() );
		} finally {
			$wpdb->suppress_errors( false );
			remove_filter( 'query', $fail );
		}

		$this->assertSame( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'swg_gallery'" ) );
		$this->assertSame( 1, ( new WpdbGalleryItemRepository() )->count( $source->id ), 'The source is untouched.' );
	}

	public function test_delete_removes_relations_but_leaves_every_attachment_and_its_file(): void {
		$file = tempnam( sys_get_temp_dir(), 'swg' );
		$this->assertIsString( $file );
		file_put_contents( $file, 'image' );
		$items   = new WpdbGalleryItemRepository();
		$gallery = $this->repository->create( new NewGallery( 'D', 1 ) );
		$image   = $this->makeAttachment( 0, 'image/jpeg', array( 'post_parent' => $gallery->id ) );
		update_attached_file( $image, $file );
		$items->append( $gallery->id, $image );
		$other = $this->repository->create( new NewGallery( 'Other', 1 ) );
		$items->append( $other->id, $image );
		$this->repository->update( $gallery->withCover( $image ) );

		$this->repository->delete( $gallery->id );

		$this->assertNull( $this->repository->find( $gallery->id ) );
		$this->assertSame( 0, $items->count( $gallery->id ) );
		$this->assertSame( 1, $items->count( $other->id ), 'Other galleries keep the attachment.' );
		clean_post_cache( $image ); // wp_delete_post() re-parents attachments with a direct query.
		$attachment = get_post( $image );
		$this->assertInstanceOf( WP_Post::class, $attachment );
		$this->assertSame( 0, $attachment->post_parent, 'The attachment is detached from the deleted post, not deleted.' );
		$this->assertFileExists( $file );
		$this->assertSame( 'image', file_get_contents( $file ) );
		unlink( $file );
	}

	public function test_delete_fails_cleanly_when_wordpress_refuses(): void {
		$gallery = $this->repository->create( new NewGallery( 'D', 1 ) );
		( new WpdbGalleryItemRepository() )->append( $gallery->id, 11 );
		add_filter( 'pre_delete_post', '__return_false' );

		try {
			$this->repository->delete( $gallery->id );
			$this->fail( 'A refused delete must raise an exception.' );
		} catch ( \RuntimeException $e ) {
			$this->assertNotNull( $this->repository->find( $gallery->id ) );
			$this->assertSame( 1, ( new WpdbGalleryItemRepository() )->count( $gallery->id ), 'Relations survive a failed delete.' );
		} finally {
			remove_filter( 'pre_delete_post', '__return_false' );
		}
	}

	public function test_restore_and_trash_failures_are_reported(): void {
		$gallery = $this->repository->create( new NewGallery( 'T', 1 ) );
		add_filter( 'pre_trash_post', '__return_false' );
		try {
			$this->repository->trash( $gallery->id );
			$this->fail( 'A refused trash must raise an exception.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'draft', get_post_status( $gallery->id ) );
		} finally {
			remove_filter( 'pre_trash_post', '__return_false' );
		}

		$this->repository->trash( $gallery->id );
		add_filter( 'pre_untrash_post', '__return_false' );
		try {
			$this->repository->restore( $gallery->id );
			$this->fail( 'A refused restore must raise an exception.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'trash', get_post_status( $gallery->id ) );
		} finally {
			remove_filter( 'pre_untrash_post', '__return_false' );
		}
	}

	public function test_create_and_update_report_storage_failures(): void {
		$gallery = $this->repository->create( new NewGallery( 'G', 1 ) );
		add_filter( 'wp_insert_post_empty_content', '__return_true' );
		try {
			$this->repository->create( new NewGallery( 'Fails', 1 ) );
			$this->fail( 'A rejected insert must raise an exception.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The gallery could not be created.', $e->getMessage() );
		}
		try {
			$this->repository->update( $gallery->withTitle( 'Fails too' ) );
			$this->fail( 'A rejected update must raise an exception.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The gallery could not be stored.', $e->getMessage() );
		} finally {
			remove_filter( 'wp_insert_post_empty_content', '__return_true' );
		}
		$this->assertSame( 'G', $this->repository->find( $gallery->id )?->title );
	}

	public function test_unencodable_settings_are_rejected_before_anything_is_written(): void {
		$bad = new GallerySettings( 'grid', SortMode::Manual, array( 'broken' => NAN ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->repository->create( new NewGallery( 'X', 1, '', GalleryStatus::Draft, $bad ) );
	}

	public function test_modified_time_is_utc_and_falls_back_for_zero_dates(): void {
		global $wpdb;
		$gallery = $this->repository->create( new NewGallery( 'M', 1 ) );
		$this->assertEqualsWithDelta( time(), $gallery->modifiedAt->getTimestamp(), 5 );
		$this->assertSame( 'UTC', $gallery->modifiedAt->getTimezone()->getName() );

		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '0000-00-00 00:00:00' ), array( 'ID' => $gallery->id ) );
		clean_post_cache( $gallery->id );
		$this->assertEqualsWithDelta( time(), $this->repository->find( $gallery->id )?->modifiedAt->getTimestamp(), 5 );
	}

	public function test_search_primes_the_meta_cache_in_one_query(): void {
		global $wpdb;
		$measure = function ( int $limit ) use ( $wpdb ): int {
			wp_cache_flush();
			$before = $wpdb->num_queries;
			$page   = $this->repository->search( new GalleryQuery( limit: $limit ) );
			$this->assertCount( $limit, $page->items );
			return $wpdb->num_queries - $before;
		};
		for ( $i = 0; $i < 3; ++$i ) {
			$this->repository->create( new NewGallery( 'G' . $i, 1 ) );
		}
		$small = $measure( 3 );
		for ( $i = 3; $i < 15; ++$i ) {
			$this->repository->create( new NewGallery( 'G' . $i, 1 ) );
		}

		$this->assertSame( $small, $measure( 15 ), 'The query count does not grow with the number of galleries.' );
		$this->assertLessThanOrEqual( 6, $small );
	}
}
