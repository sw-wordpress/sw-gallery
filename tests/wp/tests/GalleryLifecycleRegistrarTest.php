<?php
/**
 * Consistency hooks: attachment deletion, gallery deletion, trash and restore. Attachments are never deleted by us.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Gallery\NewGallery;
use SWGallery\Tests\Support\WpFixtures;
use SWGallery\WordPress\Hooks\GalleryLifecycleRegistrar;
use SWGallery\WordPress\PostTypes;
use SWGallery\WordPress\Repository\PostMetaRevisionStore;
use SWGallery\WordPress\Repository\WpdbGalleryItemRepository;
use SWGallery\WordPress\Repository\WpdbGalleryRelations;
use SWGallery\WordPress\Repository\WpGalleryRepository;

class GalleryLifecycleRegistrarTest extends SwgTestCase {

	use WpFixtures;

	private WpGalleryRepository $repository;
	private WpdbGalleryItemRepository $items;

	public function set_up(): void {
		parent::set_up();
		$this->purgeGalleries();
		$this->repository = new WpGalleryRepository( new WpdbGalleryRelations() );
		$this->items      = new WpdbGalleryItemRepository();
	}

	public function tear_down(): void {
		$this->purgeGalleries();
		parent::tear_down();
	}

	public function test_the_registrar_hooks_into_attachment_and_post_deletion_only(): void {
		$registrar = new GalleryLifecycleRegistrar();
		$registrar->register();

		$this->assertSame( 10, has_action( 'delete_attachment', array( $registrar, 'on_delete_attachment' ) ) );
		$this->assertSame( 10, has_action( 'before_delete_post', array( $registrar, 'on_before_delete_post' ) ) );
		$this->assertFalse( has_action( 'trash_post', array( $registrar, 'on_before_delete_post' ) ), 'Trash keeps relations.' );
	}

	public function test_deleting_an_attachment_removes_its_relations_and_covers_in_every_gallery(): void {
		$revisions = new PostMetaRevisionStore();
		$doomed    = $this->makeAttachment();
		$kept      = $this->makeAttachment();
		$g1        = $this->repository->create( new NewGallery( 'One', 1 ) );
		$g2        = $this->repository->create( new NewGallery( 'Two', 1, '', GalleryStatus::Publish ) );
		$g3        = $this->repository->create( new NewGallery( 'Trashed', 1 ) );
		$untouched = $this->repository->create( new NewGallery( 'Untouched', 1 ) );
		foreach ( array( $g1, $g2, $g3 ) as $gallery ) {
			$this->items->append( $gallery->id, $doomed );
			$this->items->append( $gallery->id, $kept );
		}
		$this->items->append( $untouched->id, $kept );
		$this->repository->update( $g1->withCover( $doomed ) );
		$this->repository->update( $g3->withCover( $doomed ) );
		$this->repository->update( $untouched->withCover( $kept ) );
		$this->repository->trash( $g3->id );
		$before = array(
			$g1->id        => $revisions->current( $g1->id )->value,
			$g2->id        => $revisions->current( $g2->id )->value,
			$untouched->id => $revisions->current( $untouched->id )->value,
		);

		wp_delete_attachment( $doomed, true );

		$this->assertNull( get_post( $doomed ) );
		foreach ( array( $g1, $g2, $g3 ) as $gallery ) {
			$this->assertSame( array(), $this->items->assignedAttachmentIds( $gallery->id, array( $doomed ) ), 'Relation removed.' );
			$this->assertSame( array( $kept ), $this->items->assignedAttachmentIds( $gallery->id, array( $kept, $doomed ) ), 'Other items stay.' );
		}
		$this->assertNull( $this->repository->find( $g1->id )?->coverAttachmentId );
		$this->assertNull( $this->repository->find( $g3->id )?->coverAttachmentId, 'Trashed galleries are cleaned too.' );
		$this->assertSame( $kept, $this->repository->find( $untouched->id )?->coverAttachmentId );
		$this->assertGreaterThan( $before[ $g1->id ], $revisions->current( $g1->id )->value, 'Affected galleries get a new revision.' );
		$this->assertGreaterThan( $before[ $g2->id ], $revisions->current( $g2->id )->value );
		$this->assertSame( $before[ $untouched->id ], $revisions->current( $untouched->id )->value, 'Unrelated galleries are not touched.' );
		$this->assertNotNull( get_post( $kept ), 'No other attachment is deleted.' );
	}

	public function test_the_handler_is_idempotent_and_ignores_unused_or_invalid_ids(): void {
		$registrar  = new GalleryLifecycleRegistrar();
		$image      = $this->makeAttachment();
		$gallery    = $this->repository->create( new NewGallery( 'G', 1 ) );
		$this->items->append( $gallery->id, $image );
		$this->repository->update( $gallery->withCover( $image ) );

		$registrar->on_delete_attachment( $image );
		$registrar->on_delete_attachment( (string) $image );
		$registrar->on_delete_attachment( 0 );
		$registrar->on_delete_attachment( -3 );
		$registrar->on_delete_attachment( 987654 );

		$this->assertSame( 0, $this->items->count( $gallery->id ) );
		$this->assertNull( $this->repository->find( $gallery->id )?->coverAttachmentId );
		$this->assertNotNull( get_post( $image ), 'The handler never deletes the attachment itself.' );
	}

	public function test_orphan_relations_do_not_create_revision_meta_for_missing_galleries(): void {
		$registrar = new GalleryLifecycleRegistrar();
		$this->items->append( 987100, 55 );

		$registrar->on_delete_attachment( 55 );

		$this->assertSame( 0, $this->items->count( 987100 ) );
		$this->assertSame( array(), get_post_meta( 987100, PostTypes::META_REV ) );
	}

	public function test_deleting_a_gallery_removes_only_its_relations_even_without_the_repository(): void {
		$image = $this->makeAttachment();
		$gone  = $this->repository->create( new NewGallery( 'Gone', 1 ) );
		$stays = $this->repository->create( new NewGallery( 'Stays', 1 ) );
		$this->items->append( $gone->id, $image );
		$this->items->append( $stays->id, $image );

		wp_delete_post( $gone->id, true );

		$this->assertSame( 0, $this->items->count( $gone->id ) );
		$this->assertSame( 1, $this->items->count( $stays->id ) );
		$this->assertNotNull( get_post( $image ) );
	}

	public function test_deleting_a_trashed_gallery_removes_its_relations(): void {
		$gallery = $this->repository->create( new NewGallery( 'G', 1 ) );
		$this->items->append( $gallery->id, 11 );
		wp_trash_post( $gallery->id );
		$this->assertSame( 1, $this->items->count( $gallery->id ) );

		wp_delete_post( $gallery->id, true );

		$this->assertSame( 0, $this->items->count( $gallery->id ) );
	}

	public function test_trash_and_untrash_keep_the_relations(): void {
		$gallery = $this->repository->create( new NewGallery( 'G', 1, '', GalleryStatus::Publish ) );
		$this->items->append( $gallery->id, 11 );
		$this->items->append( $gallery->id, 12 );

		wp_trash_post( $gallery->id );
		$this->assertSame( 2, $this->items->count( $gallery->id ) );
		wp_untrash_post( $gallery->id );
		$this->assertSame( 2, $this->items->count( $gallery->id ) );
	}

	public function test_deleting_other_post_types_does_not_touch_relations(): void {
		$post = self::factory()->post->create();
		$this->items->append( $post, 11 );

		wp_delete_post( $post, true );

		$this->assertSame( 1, $this->items->count( $post ), 'Only galleries own relations by gallery id.' );
	}

	public function test_a_gallery_used_in_several_places_survives_the_deletion_of_an_unrelated_attachment(): void {
		$image   = $this->makeAttachment();
		$other   = $this->makeAttachment();
		$gallery = $this->repository->create( new NewGallery( 'G', 1 ) );
		$this->items->append( $gallery->id, $image );
		$this->repository->update( $gallery->withCover( $image ) );

		wp_delete_attachment( $other, true );

		$this->assertSame( 1, $this->items->count( $gallery->id ) );
		$this->assertSame( $image, $this->repository->find( $gallery->id )?->coverAttachmentId );
	}
}
