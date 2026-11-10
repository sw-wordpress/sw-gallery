<?php
/**
 * Batched media references and allowed MIME types against real attachments (the shared contract plus query counts).
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\Tests\Wp;

use SWGallery\Core\Contract\MediaReferenceRepositoryInterface;
use SWGallery\Tests\Support\MediaReferenceRepositoryContract;
use SWGallery\Tests\Support\WpFixtures;
use SWGallery\WordPress\Media\AllowedMimeTypes;
use SWGallery\WordPress\Media\WpMediaRepository;

final class WpMediaRepositoryTest extends MediaReferenceRepositoryContract
{
    use WpFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (!SWG_WP_DB_AVAILABLE) {
            self::markTestSkipped('Test database unreachable: start it with bin/test-db.sh start.');
        }
        $this->purgeGalleries();
    }

    protected function tearDown(): void
    {
        if (SWG_WP_DB_AVAILABLE) {
            $this->purgeGalleries();
        }
        parent::tearDown();
    }

    protected function repository(): MediaReferenceRepositoryInterface
    {
        return new WpMediaRepository();
    }

    protected function seedImage(string $title, string $caption, string $alt, string $mime, int $width, int $height): int
    {
        $id = $this->makeAttachment(0, $mime, ['post_title' => $title, 'post_excerpt' => $caption]);
        if ($alt !== '') {
            update_post_meta($id, '_wp_attachment_image_alt', wp_slash($alt));
        }
        if ($width > 0) {
            wp_update_attachment_metadata($id, ['width' => $width, 'height' => $height, 'file' => 'x.jpg']);
        }

        return $id;
    }

    protected function seedNonImage(): int
    {
        return $this->makeAttachment(0, 'application/pdf');
    }

    public function testTheQueryCountDoesNotGrowWithTheNumberOfAttachments(): void
    {
        global $wpdb;
        $few = [];
        for ($i = 0; $i < 3; ++$i) {
            $few[] = $this->seedImage('Few ' . $i, 'c', 'a', 'image/jpeg', 100, 50);
        }
        $many = $few;
        for ($i = 0; $i < 37; ++$i) {
            $many[] = $this->seedImage('Many ' . $i, 'c', 'a', 'image/png', 100, 50);
        }
        $repository = new WpMediaRepository();
        $measure = function (array $ids) use ($repository, $wpdb): int {
            foreach ($ids as $id) {
                clean_post_cache($id);
            }
            $before = $wpdb->num_queries;
            $found = $repository->findReferences($ids);
            self::assertCount(count($ids), $found);
            // Reading every reference again must be served from the primed caches.
            self::assertSame('c', $found[$ids[0]]->caption);
            self::assertSame(100, $found[$ids[0]]->width);

            return $wpdb->num_queries - $before;
        };

        $small = $measure($few);
        $large = $measure($many);

        self::assertSame($small, $large, 'No query per attachment.');
        self::assertLessThanOrEqual(3, $large);
    }

    public function testLargeBatchesAreChunked(): void
    {
        $ids = [];
        for ($i = 0; $i < 3; ++$i) {
            $ids[] = $this->seedImage('T', '', '', 'image/jpeg', 1, 1);
        }
        $padding = range(2000000, 2000000 + MediaReferenceRepositoryInterface::MAX_BATCH + 20);

        $found = (new WpMediaRepository())->findReferences(array_merge($padding, $ids));

        self::assertEqualsCanonicalizing($ids, array_keys($found));
    }

    public function testTrashedAttachmentsAndOtherPostTypesAreNotReported(): void
    {
        $trashed = $this->makeAttachment(0, 'image/jpeg', ['post_status' => 'trash']);
        $post = wp_insert_post(['post_title' => 'A post', 'post_status' => 'publish']);
        self::assertIsInt($post);

        self::assertSame([], (new WpMediaRepository())->findReferences([$trashed, $post]));
        self::assertSame([], (new WpMediaRepository())->existingImageIds([$trashed, $post]));
        wp_delete_post($post, true);
    }

    public function testExistingImageIdsFollowTheAllowedMimeTypes(): void
    {
        $jpeg = $this->makeAttachment(0, 'image/jpeg');
        $svg = $this->makeAttachment(0, 'image/svg+xml');
        $heic = $this->makeAttachment(0, 'image/heic');

        self::assertSame([$jpeg], (new WpMediaRepository())->existingImageIds([$svg, $jpeg, $heic]));
    }

    public function testAllowedMimeTypesCoverRasterFormatsOnlyAndDependOnTheServerForWebpAndAvif(): void
    {
        $types = AllowedMimeTypes::all();

        self::assertSame(['image/jpeg', 'image/png', 'image/gif'], array_slice($types, 0, 3));
        self::assertNotContains('image/svg+xml', $types);
        self::assertNotContains('image/heic', $types);
        self::assertSame(
            wp_image_editor_supports(['mime_type' => 'image/webp']),
            AllowedMimeTypes::is_allowed('image/webp')
        );
        self::assertTrue(AllowedMimeTypes::is_allowed('image/png'));
        self::assertFalse(AllowedMimeTypes::is_allowed('image/svg+xml'));
        self::assertFalse(AllowedMimeTypes::is_allowed(''));
    }
}
