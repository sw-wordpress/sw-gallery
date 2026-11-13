<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Core\Application\Render\RenderContext;
use SWGallery\Core\Application\Render\RenderDiagnostic;
use SWGallery\Core\Application\Render\RenderGallery;
use SWGallery\Core\Application\Service\ImportProviderRegistry;
use SWGallery\Core\Application\Service\LayoutRegistry;
use SWGallery\Core\Contract\GalleryLayoutInterface;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\Gallery;
use SWGallery\Core\Domain\Gallery\GalleryItem;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Media\MediaReference;
use SWGallery\Tests\Support\ConfigurableAccessPolicy;
use SWGallery\Tests\Support\FailingLayout;
use SWGallery\Tests\Support\FakeThumbnailResolver;
use SWGallery\Tests\Support\FixedGalleries;
use SWGallery\Tests\Support\InMemoryImportProvider;
use SWGallery\Tests\Support\InMemoryMediaReferences;
use SWGallery\Tests\Support\ListRenderItemSource;
use SWGallery\Tests\Support\RecordingLayout;
use SWGallery\Tests\Support\RecordingLogger;
use SWGallery\Tests\Support\RecordingSimpleMarkup;
use SWGallery\Tests\Support\StubLayout;

/** The versioned extension contracts (M4-07, M4-08): registration validation and containment of failing layouts. */
final class ExtensionContractTest extends TestCase
{
    private const ADMIN = 1;

    public function testTheContractVersionIsDeclared(): void
    {
        self::assertSame(1, GalleryLayoutInterface::API_VERSION);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidLayoutIds(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Masonry'];
        yield 'leading digit' => ['1grid'];
        yield 'path' => ['../grid'];
        yield 'too long' => [str_repeat('a', 65)];
    }

    #[DataProvider('invalidLayoutIds')]
    public function testLayoutsWithInvalidIdsAreRejected(string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LayoutRegistry())->register(new StubLayout($id));
    }

    public function testLayoutsWithEmptySchemaAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LayoutRegistry())->register(new StubLayout('empty', []));
    }

    public function testADuplicateNeverReplacesTheRegisteredLayout(): void
    {
        $registry = new LayoutRegistry();
        $first = new StubLayout('grid');
        $registry->register($first);
        try {
            $registry->register(new StubLayout('grid'));
            self::fail('A duplicate id must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame($first, $registry->get('grid'));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidProviderIds(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Drive'];
        yield 'slash' => ['a/b'];
        yield 'space' => ['my drive'];
    }

    #[DataProvider('invalidProviderIds')]
    public function testImportProvidersWithInvalidIdsAreRejected(string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ImportProviderRegistry())->register(new InMemoryImportProvider($id));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failurePoints(): iterable
    {
        yield 'render' => ['render'];
        yield 'image hints' => ['hints'];
        yield 'schema while merging overrides' => ['schema-late'];
    }

    #[DataProvider('failurePoints')]
    public function testAFailingThirdPartyLayoutFallsBackToGridWithAnEditorDiagnostic(string $failsIn): void
    {
        $logger = new RecordingLogger();
        $grid = new RecordingLayout('grid');
        $registry = new LayoutRegistry();
        $registry->register($grid);
        $registry->register(new FailingLayout($failsIn));
        $pipeline = $this->pipeline($registry, $logger);

        $result = $pipeline->render($this->context(self::ADMIN, ['columns' => 2]));

        self::assertSame('<div data-layout="grid">2</div>', $result->html);
        self::assertSame('grid', $result->layoutId);
        $codes = array_map(static fn (RenderDiagnostic $d): string => $d->code, $result->diagnostics);
        self::assertContains(RenderDiagnostic::LAYOUT_FAILED, $codes);
        self::assertSame(['swg_layout_failed'], $logger->codes());
        self::assertSame(
            ['layout' => 'broken', 'exception' => \RuntimeException::class],
            $logger->entries[0]['context'],
            'Only the layout id and the exception class are logged, never the message.'
        );
    }

    public function testVisitorsGetTheFallbackMarkupWithoutDiagnostics(): void
    {
        $registry = new LayoutRegistry();
        $registry->register(new RecordingLayout('grid'));
        $registry->register(new FailingLayout('render'));

        $result = $this->pipeline($registry, new RecordingLogger())->render($this->context(0, []));

        self::assertSame('<div data-layout="grid">2</div>', $result->html);
        self::assertSame([], $result->diagnostics);
    }

    public function testAFailingFallbackLayoutIsNotHidden(): void
    {
        $registry = new LayoutRegistry();
        $registry->register(new FailingLayout('render', 'grid'));

        $this->expectException(\RuntimeException::class);
        $this->pipeline($registry, new RecordingLogger(), 'grid')->render($this->context(self::ADMIN, []));
    }

    private function pipeline(LayoutRegistry $registry, RecordingLogger $logger, string $layout = 'broken'): RenderGallery
    {
        $gallery = new Gallery(
            7,
            'Holidays',
            '',
            GalleryStatus::Publish,
            5,
            null,
            new GallerySettings($layout),
            new \DateTimeImmutable('2026-01-01'),
            false
        );
        $media = new InMemoryMediaReferences();
        $items = [];
        for ($i = 1; $i <= 2; ++$i) {
            $items[] = new GalleryItem($i, 7, 100 + $i, $i);
            $media->add(new MediaReference(100 + $i, "Title $i", '', "Alt $i", 'image/jpeg', 800, 600));
        }

        return new RenderGallery(
            new FixedGalleries($gallery),
            new ConfigurableAccessPolicy([self::ADMIN]),
            new ListRenderItemSource($items, []),
            $media,
            new FakeThumbnailResolver([]),
            $registry,
            new RecordingSimpleMarkup(),
            logger: $logger
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function context(int $userId, array $overrides): RenderContext
    {
        return new RenderContext(7, EntryPoint::Shortcode, new Actor($userId), 'swg-7-1', 1, 60, null, $overrides);
    }
}
