<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Core\Application\Render\RenderContext;
use SWGallery\Core\Application\Render\RenderGallery;
use SWGallery\Core\Application\Service\LayoutRegistry;
use SWGallery\Core\Contract\RevisionStoreInterface;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\Gallery;
use SWGallery\Core\Domain\Gallery\GalleryItem;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Gallery\ItemOverrides;
use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\Core\Domain\Layout\LayoutRenderRequest;
use SWGallery\Core\Domain\Media\MediaReference;
use SWGallery\Tests\Support\ConfigurableAccessPolicy;
use SWGallery\Tests\Support\FakeThumbnailResolver;
use SWGallery\Tests\Support\FixedGalleries;
use SWGallery\Tests\Support\InMemoryMediaReferences;
use SWGallery\Tests\Support\ListRenderItemSource;
use SWGallery\Tests\Support\RecordingLayout;
use SWGallery\Tests\Support\RecordingSimpleMarkup;

/** The additive request data of plan layouts: the stored revision (seed) and the item settings. */
final class RenderRequestSeedTest extends TestCase
{
    private function request(?RevisionStoreInterface $revisions): LayoutRenderRequest
    {
        $gallery = new Gallery(7, 'G', '', GalleryStatus::Publish, 5, null, new GallerySettings(), new \DateTimeImmutable('2026-01-01'), false);
        $media = new InMemoryMediaReferences();
        $media->add(new MediaReference(101, 'T', 'C', 'A', 'image/jpeg', 800, 600));
        $layout = new RecordingLayout('grid');
        $registry = new LayoutRegistry();
        $registry->register($layout);
        $pipeline = new RenderGallery(
            new FixedGalleries($gallery),
            new ConfigurableAccessPolicy([1]),
            new ListRenderItemSource([new GalleryItem(1, 7, 101, 1, new ItemOverrides(), ['emphasis' => 3])]),
            $media,
            new FakeThumbnailResolver([]),
            $registry,
            new RecordingSimpleMarkup(),
            revisions: $revisions,
        );
        $pipeline->render(new RenderContext(7, EntryPoint::Shortcode, new Actor(0), 'swg-7-1'));
        self::assertNotNull($layout->last);

        return $layout->last;
    }

    public function testTheRequestCarriesTheStoredRevisionAndTheItemSettings(): void
    {
        $store = new class implements RevisionStoreInterface {
            public function current(int $galleryId): Revision
            {
                return new Revision($galleryId * 10 + 2);
            }

            public function compareAndIncrement(int $galleryId, Revision $expected): Revision
            {
                return $expected->next();
            }
        };
        $request = $this->request($store);
        self::assertSame(72, $request->revision);
        self::assertSame(['emphasis' => 3], $request->items[0]->settings);
    }

    public function testWithoutARevisionStoreTheRevisionIsZero(): void
    {
        self::assertSame(0, $this->request(null)->revision);
    }

    public function testANegativeRevisionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LayoutRenderRequest(7, 'swg-7-1', [], new GallerySettings(), -1);
    }
}
