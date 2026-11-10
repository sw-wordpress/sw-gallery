<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWGallery\Core\Application\Service\LayoutRegistry;
use SWGallery\Core\Contract\GalleryLayoutInterface;
use SWGallery\Core\Contract\LayoutRegistryInterface;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Layout\ImageRequest;
use SWGallery\Core\Domain\Layout\LayoutRenderRequest;
use SWGallery\Core\Domain\Layout\RenderItem;
use SWGallery\Core\Domain\Layout\ResolvedImage;
use SWGallery\Core\Domain\Media\MediaReference;
use SWGallery\Core\Exception\UnknownLayoutException;

final class LayoutAndMediaTest extends TestCase
{
    private function layout(string $id): GalleryLayoutInterface
    {
        return new class ($id) implements GalleryLayoutInterface {
            public function __construct(private readonly string $id)
            {
            }

            public function getId(): string
            {
                return $this->id;
            }

            public function getLabel(): string
            {
                return ucfirst($this->id);
            }

            public function getSettingsSchema(): array
            {
                return ['type' => 'object'];
            }

            public function render(LayoutRenderRequest $request): string
            {
                return '<div></div>';
            }
        };
    }

    public function testRegistryResolvesRegisteredLayoutWithoutFallback(): void
    {
        $registry = new LayoutRegistry();
        $registry->register($this->layout('grid'));
        $registry->register($this->layout('masonry'));

        $resolution = $registry->resolve('masonry');
        self::assertSame('masonry', $resolution->layout->getId());
        self::assertFalse($resolution->fellBack);
        self::assertTrue($registry->has('grid'));
        self::assertSame(['grid', 'masonry'], array_map(
            static fn (GalleryLayoutInterface $l): string => $l->getId(),
            $registry->all()
        ));
        self::assertSame('masonry', $registry->get('masonry')->getId());
    }

    public function testUnknownLayoutFallsBackToGridAndFlagsIt(): void
    {
        $registry = new LayoutRegistry();
        $registry->register($this->layout(LayoutRegistryInterface::FALLBACK_ID));

        $resolution = $registry->resolve('does-not-exist');
        self::assertSame('grid', $resolution->layout->getId());
        self::assertTrue($resolution->fellBack);
        self::assertSame('does-not-exist', $resolution->requestedId);
    }

    public function testRegistryRejectsDuplicatesUnknownGetAndMissingFallback(): void
    {
        $registry = new LayoutRegistry();
        $registry->register($this->layout('masonry'));

        try {
            $registry->register($this->layout('masonry'));
            self::fail('Duplicate ids must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertCount(1, $registry->all());
        }
        try {
            $registry->get('nope');
            self::fail('Unknown layout must raise.');
        } catch (UnknownLayoutException $e) {
            self::assertSame('swg_unknown_layout', $e->errorCode());
        }
        $this->expectException(\LogicException::class);
        $registry->resolve('nope');
    }

    public function testMediaReferenceAspectRatio(): void
    {
        $m = new MediaReference(5, 'T', 'C', 'A', 'image/jpeg', 300, 200);
        self::assertTrue($m->hasDimensions());
        self::assertSame(1.5, $m->aspectRatio());

        $unknown = new MediaReference(5, 'T', 'C', 'A', 'image/jpeg', 0, 0);
        self::assertFalse($unknown->hasDimensions());
        self::assertNull($unknown->aspectRatio());
    }

    /** @return iterable<string, array{callable(): object}> */
    public static function invalidValueObjects(): iterable
    {
        yield 'media id' => [static fn () => new MediaReference(0, '', '', '', 'image/png', 1, 1)];
        yield 'media width' => [static fn () => new MediaReference(1, '', '', '', 'image/png', -1, 1)];
        yield 'resolved url' => [static fn () => new ResolvedImage('', 1, 1)];
        yield 'resolved height' => [static fn () => new ResolvedImage('u', 1, -1)];
        yield 'request attachment' => [static fn () => new ImageRequest(0, 100)];
        yield 'request width' => [static fn () => new ImageRequest(1, 0)];
        yield 'request sizes' => [static fn () => new ImageRequest(1, 100, '')];
        yield 'render item id' => [static fn () => new RenderItem(0, 1, '', '', '', new ResolvedImage('u', 1, 1))];
        yield 'render item attachment' => [static fn () => new RenderItem(1, 0, '', '', '', new ResolvedImage('u', 1, 1))];
        yield 'request gallery' => [static fn () => new LayoutRenderRequest(0, 'g1', [], new GallerySettings())];
        yield 'request instance' => [static fn () => new LayoutRenderRequest(1, 'Bad Id', [], new GallerySettings())];
    }

    /** @param callable(): object $factory */
    #[DataProvider('invalidValueObjects')]
    public function testValueObjectsRejectInvalidInput(callable $factory): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $factory();
    }

    public function testValidValueObjectsKeepTheirData(): void
    {
        $image = new ResolvedImage('https://example.test/a.jpg', 300, 200, 'a.jpg 300w', '100vw');
        $item = new RenderItem(1, 2, 'T', 'C', 'A', $image, $image);
        $request = new LayoutRenderRequest(3, 'swg-3-1', [$item], new GallerySettings());
        $imageRequest = new ImageRequest(2, 320);

        self::assertSame('a.jpg 300w', $request->items[0]->thumbnail->srcset);
        self::assertSame('100vw', $imageRequest->sizes);
    }
}
