<?php

/**
 * The shared GalleryRepositoryContract against the real WordPress repository. The contract is a plain PHPUnit TestCase,
 * so data is committed and cleaned up by hand; skips without the test database.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\Tests\Wp;

use SWGallery\Core\Contract\GalleryRepositoryInterface;
use SWGallery\Tests\Support\GalleryRepositoryContract;
use SWGallery\Tests\Support\WpFixtures;
use SWGallery\WordPress\Repository\WpdbGalleryItemRepository;
use SWGallery\WordPress\Repository\WpdbGalleryRelations;
use SWGallery\WordPress\Repository\WpGalleryRepository;

final class WpGalleryRepositoryContractTest extends GalleryRepositoryContract
{
    use WpFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (!SWG_WP_DB_AVAILABLE) {
            self::markTestSkipped('Test database unreachable: start it with bin/test-db.sh start.');
        }
        $this->ensureGalleryTypeRegistered();
        wp_set_current_user(0);
        $this->purgeGalleries();
    }

    protected function tearDown(): void
    {
        if (SWG_WP_DB_AVAILABLE) {
            $this->purgeGalleries();
        }
        parent::tearDown();
    }

    protected function repository(): GalleryRepositoryInterface
    {
        return new WpGalleryRepository(new WpdbGalleryRelations());
    }

    protected function seedItems(int $galleryId, array $attachmentIds): void
    {
        $items = new WpdbGalleryItemRepository();
        foreach ($attachmentIds as $attachmentId) {
            $items->append($galleryId, $attachmentId);
        }
    }

    protected function itemCount(int $galleryId): int
    {
        return (new WpdbGalleryItemRepository())->count($galleryId);
    }
}
