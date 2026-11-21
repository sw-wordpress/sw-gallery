<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Core\Application\Render\RenderAsset;
use SWGallery\Core\Application\Render\RenderContext;
use SWGallery\Core\Application\Render\RenderDiagnostic;
use SWGallery\Core\Application\Render\RenderGallery;
use SWGallery\Core\Application\Render\RenderResult;
use SWGallery\Core\Application\Service\LayoutRegistry;
use SWGallery\Core\Contract\GalleryLayoutInterface;
use SWGallery\Core\Contract\LayoutAssetsProviderInterface;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Access\RenderAccess;
use SWGallery\Core\Domain\Gallery\Gallery;
use SWGallery\Core\Domain\Gallery\GalleryItem;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Gallery\HoverAnimation;
use SWGallery\Core\Domain\Gallery\HoverEffect;
use SWGallery\Core\Domain\Gallery\ItemOverrides;
use SWGallery\Core\Domain\Gallery\SortMode;
use SWGallery\Core\Domain\Layout\ImageRole;
use SWGallery\Core\Domain\Layout\LayoutRenderRequest;
use SWGallery\Core\Domain\Layout\LoadingStrategy;
use SWGallery\Core\Domain\Media\MediaReference;
use SWGallery\Tests\Support\ConfigurableAccessPolicy;
use SWGallery\Tests\Support\FakeThumbnailResolver;
use SWGallery\Tests\Support\FixedGalleries;
use SWGallery\Tests\Support\InMemoryMediaReferences;
use SWGallery\Tests\Support\ListRenderItemSource;
use SWGallery\Tests\Support\RecordingLayout;
use SWGallery\Tests\Support\RecordingSimpleMarkup;

final class RenderGalleryTest extends TestCase
{
    private const OWNER = 5;
    private const ADMIN = 1;
    private const STRANGER = 9;

    private RecordingLayout $grid;
    private RecordingSimpleMarkup $simple;
    private FakeThumbnailResolver $thumbnails;
    private ListRenderItemSource $source;
    private InMemoryMediaReferences $media;

    /**
     * @param array<int, ItemOverrides> $overrides
     */
    private function pipeline(
        Gallery $gallery,
        int $itemCount = 3,
        array $unusableAttachments = [],
        array $overrides = [],
        array $unresolvable = [],
        bool $withMedia = true,
    ): RenderGallery {
        $items = [];
        $this->media = new InMemoryMediaReferences();
        for ($i = 1; $i <= $itemCount; ++$i) {
            $items[] = new GalleryItem($i, $gallery->id, 100 + $i, $i, $overrides[$i] ?? new ItemOverrides());
            if ($withMedia) {
                $this->media->add(new MediaReference(100 + $i, "Title $i", "Caption $i", "Alt $i", 'image/jpeg', 800, 600));
            }
        }
        $this->source = new ListRenderItemSource($items, $unusableAttachments);
        $this->thumbnails = new FakeThumbnailResolver($unresolvable);
        $this->grid = new RecordingLayout('grid');
        $this->simple = new RecordingSimpleMarkup();
        $registry = new LayoutRegistry();
        $registry->register($this->grid);

        return new RenderGallery(
            new FixedGalleries($gallery),
            new ConfigurableAccessPolicy([self::ADMIN]),
            $this->source,
            $this->media,
            $this->thumbnails,
            $registry,
            $this->simple
        );
    }

    private function gallery(
        GalleryStatus $status = GalleryStatus::Publish,
        bool $password = false,
        ?GallerySettings $settings = null,
    ): Gallery {
        return new Gallery(
            7,
            'Holidays',
            '',
            $status,
            self::OWNER,
            null,
            $settings ?? new GallerySettings(),
            new \DateTimeImmutable('2026-01-01'),
            $password
        );
    }

    private function context(
        int $userId = 0,
        EntryPoint $entry = EntryPoint::Shortcode,
        int $page = 1,
        int $perPage = 60,
        ?string $layout = null,
        array $settings = [],
        bool $unlocked = false,
        bool $eager = true,
        bool $priority = true,
        ?HoverEffect $hover = null,
    ): RenderContext {
        return new RenderContext(
            7,
            $entry,
            new Actor($userId),
            'swg-7-1',
            $page,
            $perPage,
            $layout,
            $settings,
            $unlocked,
            $eager,
            $priority,
            $hover,
        );
    }

    /**
     * @return iterable<string, array{GalleryStatus, bool, bool, int, RenderAccess, bool}>
     */
    public static function decisionTable(): iterable
    {
        // status, password protected, unlocked, user, expected access, diagnostic expected
        yield 'published visitor' => [GalleryStatus::Publish, false, false, 0, RenderAccess::Render, false];
        yield 'published password locked' => [GalleryStatus::Publish, true, false, 0, RenderAccess::PasswordForm, false];
        yield 'published password unlocked' => [GalleryStatus::Publish, true, true, 0, RenderAccess::Render, false];
        yield 'published password owner still locked' => [GalleryStatus::Publish, true, false, self::OWNER, RenderAccess::PasswordForm, false];
        yield 'private visitor' => [GalleryStatus::Private, false, false, 0, RenderAccess::Nothing, false];
        yield 'private stranger' => [GalleryStatus::Private, false, false, self::STRANGER, RenderAccess::Nothing, false];
        yield 'private owner' => [GalleryStatus::Private, false, false, self::OWNER, RenderAccess::Render, false];
        yield 'private admin' => [GalleryStatus::Private, false, false, self::ADMIN, RenderAccess::Render, false];
        yield 'draft visitor' => [GalleryStatus::Draft, false, false, 0, RenderAccess::Nothing, false];
        yield 'draft stranger' => [GalleryStatus::Draft, false, false, self::STRANGER, RenderAccess::Nothing, false];
        yield 'draft owner' => [GalleryStatus::Draft, false, false, self::OWNER, RenderAccess::Diagnostic, true];
        yield 'pending admin' => [GalleryStatus::Pending, false, false, self::ADMIN, RenderAccess::Diagnostic, true];
        yield 'future visitor' => [GalleryStatus::Future, false, false, 0, RenderAccess::Nothing, false];
        yield 'trash owner' => [GalleryStatus::Trash, false, false, self::OWNER, RenderAccess::Diagnostic, true];
        yield 'trash visitor' => [GalleryStatus::Trash, false, false, 0, RenderAccess::Nothing, false];
    }

    /**
     * @return iterable<string, array{GalleryStatus, bool, int, RenderAccess}>
     */
    public static function previewTable(): iterable
    {
        yield 'draft owner' => [GalleryStatus::Draft, false, self::OWNER, RenderAccess::Render];
        yield 'private admin' => [GalleryStatus::Private, false, self::ADMIN, RenderAccess::Render];
        yield 'published password owner ignores the password' => [GalleryStatus::Publish, true, self::OWNER, RenderAccess::Render];
        yield 'pending owner' => [GalleryStatus::Pending, false, self::OWNER, RenderAccess::Render];
        yield 'trash owner' => [GalleryStatus::Trash, false, self::OWNER, RenderAccess::Nothing];
        yield 'draft stranger' => [GalleryStatus::Draft, false, self::STRANGER, RenderAccess::Nothing];
        yield 'published visitor' => [GalleryStatus::Publish, false, 0, RenderAccess::Nothing];
        yield 'password visitor' => [GalleryStatus::Publish, true, 0, RenderAccess::Nothing];
    }

    #[DataProvider('previewTable')]
    public function testPreviewUsesTheExplicitPreviewRule(GalleryStatus $status, bool $password, int $user, RenderAccess $expected): void
    {
        $result = $this->pipeline($this->gallery($status, $password))
            ->render($this->context($user, EntryPoint::Preview, unlocked: false));

        self::assertSame($expected, $result->access);
        self::assertSame($expected === RenderAccess::Render, $result->html !== '');
        self::assertFalse($result->needsPasswordForm());
    }

    #[DataProvider('decisionTable')]
    public function testEveryEntryPointFollowsTheSameDecisionTable(
        GalleryStatus $status,
        bool $password,
        bool $unlocked,
        int $user,
        RenderAccess $expected,
        bool $diagnostic
    ): void {
        foreach (EntryPoint::cases() as $entry) {
            if ($entry === EntryPoint::Preview) {
                continue; // The authorised preview has its own rule (previewAccess, tested below).
            }
            $pipeline = $this->pipeline($this->gallery($status, $password));
            $result = $pipeline->render($this->context($user, $entry, unlocked: $unlocked));

            self::assertSame($expected, $result->access, $entry->value);
            if ($expected === RenderAccess::Render) {
                self::assertNotSame('', $result->html, $entry->value);
                continue;
            }
            self::assertSame('', $result->html, $entry->value);
            self::assertSame([], $result->assets);
            self::assertSame($expected === RenderAccess::PasswordForm, $result->needsPasswordForm());
            // The editor diagnostic never reaches feeds, visitors never get one.
            $expectedDiagnostics = $diagnostic && $entry !== EntryPoint::Feed ? 1 : 0;
            self::assertCount($expectedDiagnostics, $result->diagnostics, $entry->value);
            self::assertSame(0, $this->source->calls, 'Denied embeds must not load any item.');
        }
    }

    public function testTheDiagnosticOfAnUnpublishedGalleryNamesTheStatusButNothingElse(): void
    {
        $result = $this->pipeline($this->gallery(GalleryStatus::Draft))->render($this->context(self::OWNER));

        self::assertSame(RenderDiagnostic::GALLERY_NOT_PUBLIC, $result->diagnostics[0]->code);
        self::assertSame(['status' => 'draft'], $result->diagnostics[0]->context);
    }

    public function testAMissingGalleryRendersNothingAndOnlyCreatorsSeeADiagnostic(): void
    {
        $pipeline = $this->pipeline($this->gallery());
        $missing = new RenderContext(404, EntryPoint::Block, Actor::anonymous(), 'swg-404-1');
        $editor = new RenderContext(404, EntryPoint::Block, new Actor(self::OWNER), 'swg-404-1');

        $visitor = $pipeline->render($missing);
        $creator = $pipeline->render($editor);

        self::assertSame(RenderAccess::Nothing, $visitor->access);
        self::assertSame([], $visitor->diagnostics);
        self::assertSame(RenderDiagnostic::GALLERY_NOT_FOUND, $creator->diagnostics[0]->code);
        self::assertSame('', $creator->html);
    }

    public function testAPublishedGalleryRendersThroughTheLayoutWithResolvedItems(): void
    {
        $result = $this->pipeline($this->gallery())->render($this->context());

        self::assertSame('<div data-layout="grid">3</div>', $result->html);
        self::assertSame([RenderAsset::Lightbox], $result->assets);
        self::assertSame(3, $result->renderedItems);
        self::assertSame(3, $result->totalItems);
        self::assertSame(1, $result->totalPages);
        self::assertSame('grid', $result->layoutId);
        self::assertSame([], $result->diagnostics);
        $first = $this->grid->last?->items[0];
        self::assertNotNull($first);
        self::assertSame('Title 1', $first->title);
        self::assertSame('Alt 1', $first->alt);
        self::assertSame('https://example.test/101-thumbnail.jpg', $first->thumbnail->url);
        self::assertSame('https://example.test/101-lightbox.jpg', $first->full?->url);
        self::assertSame('swg-7-1', $this->grid->last->instanceId);
        self::assertSame(1, $this->media->calls(), 'All attachments are looked up in one batch.');
    }

    public function testAnActiveHoverEffectReachesTheItemsOfInteractiveEntryPointsOnly(): void
    {
        $hover = new HoverEffect(true, false, HoverAnimation::SlideUp);

        $this->pipeline($this->gallery())->render($this->context(hover: $hover));
        self::assertSame($hover, $this->grid->last?->items[0]->hover);

        $this->pipeline($this->gallery())->render($this->context(userId: self::ADMIN, entry: EntryPoint::Preview, hover: $hover));
        self::assertSame($hover, $this->grid->last?->items[0]->hover);
    }

    public function testAnInactiveOrMissingHoverEffectAndFeedsCarryNoHover(): void
    {
        $this->pipeline($this->gallery())->render($this->context(hover: new HoverEffect()));
        self::assertNull($this->grid->last?->items[0]->hover);

        $this->pipeline($this->gallery())->render($this->context());
        self::assertNull($this->grid->last?->items[0]->hover);

        $this->pipeline($this->gallery())->render($this->context(entry: EntryPoint::Feed, hover: new HoverEffect(true)));
        self::assertNull($this->grid->last?->items[0]->hover);
    }

    public function testALayoutCanReportAdditionalAssets(): void
    {
        $pipeline = $this->pipeline($this->gallery());
        $registry = new LayoutRegistry();
        $registry->register(new class implements GalleryLayoutInterface, LayoutAssetsProviderInterface {
            public function getId(): string
            {
                return 'grid';
            }

            public function getLabel(): string
            {
                return 'Assets';
            }

            public function getSettingsSchema(): array
            {
                return ['type' => 'object'];
            }

            public function render(LayoutRenderRequest $request): string
            {
                return '<div></div>';
            }

            public function renderAssets(): array
            {
                return [RenderAsset::Masonry];
            }
        });
        $withAssets = new RenderGallery(
            new FixedGalleries($this->gallery()),
            new ConfigurableAccessPolicy([self::ADMIN]),
            $this->source,
            $this->media,
            $this->thumbnails,
            $registry,
            $this->simple
        );

        self::assertSame([RenderAsset::Lightbox], $pipeline->render($this->context())->assets);
        self::assertSame([RenderAsset::Lightbox, RenderAsset::Masonry], $withAssets->render($this->context())->assets);
    }

    public function testImageRequestsUseTheLayoutHintsAndTheLightboxRole(): void
    {
        $this->pipeline($this->gallery(), 1)->render($this->context());

        self::assertCount(2, $this->thumbnails->requests);
        self::assertSame(300, $this->thumbnails->requests[0]->displayWidth);
        self::assertSame('50vw', $this->thumbnails->requests[0]->sizes);
        self::assertSame(ImageRole::Thumbnail, $this->thumbnails->requests[0]->role);
        self::assertSame(ImageRole::Lightbox, $this->thumbnails->requests[1]->role);
        self::assertSame(RenderGallery::LIGHTBOX_DISPLAY_WIDTH, $this->thumbnails->requests[1]->displayWidth);
    }

    public function testLocalOverridesBeatAttachmentTextsAndABlankOverrideStaysBlank(): void
    {
        $overrides = [
            1 => new ItemOverrides('Local title', 'Local caption', 'Local alt'),
            2 => new ItemOverrides('', '', ''),
            3 => new ItemOverrides(null, null, null),
        ];
        $this->pipeline($this->gallery(), 3, overrides: $overrides)->render($this->context());
        $items = $this->grid->last?->items ?? [];

        self::assertSame(['Local title', 'Local caption', 'Local alt'], [$items[0]->title, $items[0]->caption, $items[0]->alt]);
        self::assertSame(['', '', ''], [$items[1]->title, $items[1]->caption, $items[1]->alt]);
        self::assertSame(['Title 3', 'Caption 3', 'Alt 3'], [$items[2]->title, $items[2]->caption, $items[2]->alt]);
    }

    public function testTheSortModeOfTheGalleryReachesTheItemSource(): void
    {
        $settings = new GallerySettings('grid', SortMode::TitleDesc);
        $this->pipeline($this->gallery(settings: $settings))->render($this->context());

        self::assertSame(SortMode::TitleDesc, $this->source->lastSortMode);
    }

    public function testUnknownLayoutFallsBackToGridAndOnlyEditorsAreTold(): void
    {
        $settings = new GallerySettings('mosaic');
        $pipeline = $this->pipeline($this->gallery(settings: $settings));

        $visitor = $pipeline->render($this->context());
        $editor = $pipeline->render($this->context(self::OWNER));
        $feed = $pipeline->render($this->context(self::OWNER, EntryPoint::Feed));

        self::assertSame('grid', $visitor->layoutId);
        self::assertSame([], $visitor->diagnostics);
        self::assertNotSame('', $visitor->html);
        self::assertSame(RenderDiagnostic::UNKNOWN_LAYOUT, $editor->diagnostics[0]->code);
        self::assertSame(['layout' => 'mosaic'], $editor->diagnostics[0]->context);
        self::assertSame([], $feed->diagnostics);
    }

    public function testALayoutOverrideBeatsTheStoredLayoutAndFallsBackWhenUnknown(): void
    {
        $pipeline = $this->pipeline($this->gallery());

        $known = $pipeline->render($this->context(layout: 'grid'));
        $unknown = $pipeline->render($this->context(self::ADMIN, layout: 'nope'));

        self::assertSame('grid', $known->layoutId);
        self::assertSame('grid', $unknown->layoutId);
        self::assertSame('nope', $unknown->diagnostics[0]->context['layout']);
    }

    public function testValidSettingsOverridesAreMergedOverTheStoredValues(): void
    {
        $settings = new GallerySettings('grid', SortMode::Manual, ['columns' => 2, 'extra' => 'kept']);
        $this->pipeline($this->gallery(settings: $settings))->render($this->context(settings: ['columns' => 5]));

        self::assertSame(['columns' => 5, 'extra' => 'kept'], $this->grid->last?->settings->values);
    }

    public function testInvalidSettingsOverridesAreIgnoredAndReportedToEditors(): void
    {
        $settings = new GallerySettings('grid', SortMode::Manual, ['columns' => 2]);
        $pipeline = $this->pipeline($this->gallery(settings: $settings));

        $visitor = $pipeline->render($this->context(settings: ['columns' => 99]));
        self::assertSame(['columns' => 2], $this->grid->last?->settings->values);
        self::assertSame([], $visitor->diagnostics);

        $editor = $pipeline->render($this->context(self::ADMIN, settings: ['columns' => 99]));
        self::assertSame(RenderDiagnostic::INVALID_OVERRIDE, $editor->diagnostics[0]->code);
    }

    public function testOverridesNeverChangeTheStoredSettingsObject(): void
    {
        $settings = new GallerySettings('grid', SortMode::Manual, ['columns' => 2]);
        $gallery = $this->gallery(settings: $settings);
        $this->pipeline($gallery)->render($this->context(settings: ['columns' => 5]));

        self::assertSame(['columns' => 2], $gallery->settings->values);
    }

    public function testAnEmptyGalleryRendersNothingAndEditorsGetADiagnostic(): void
    {
        $pipeline = $this->pipeline($this->gallery(), 0);

        $visitor = $pipeline->render($this->context());
        $editor = $pipeline->render($this->context(self::OWNER));

        self::assertSame('', $visitor->html);
        self::assertSame([], $visitor->assets);
        self::assertSame([], $visitor->diagnostics);
        self::assertSame(0, $visitor->totalPages);
        self::assertSame(RenderDiagnostic::EMPTY_GALLERY, $editor->diagnostics[0]->code);
    }

    public function testPagingSlicesTheItemsAndReportsTheTotals(): void
    {
        $pipeline = $this->pipeline($this->gallery(), 5);

        $second = $pipeline->render($this->context(page: 2, perPage: 2));

        self::assertSame([3, 4], array_map(static fn ($i): int => $i->itemId, $this->grid->last?->items ?? []));
        self::assertSame(2, $second->page);
        self::assertSame(3, $second->totalPages);
        self::assertSame(5, $second->totalItems);
        self::assertSame(2, $second->renderedItems);
    }

    public function testAPagePastTheEndIsClampedToTheLastPage(): void
    {
        $pipeline = $this->pipeline($this->gallery(), 5);

        $result = $pipeline->render($this->context(page: 99, perPage: 2));

        self::assertSame(3, $result->page);
        self::assertSame([5], array_map(static fn ($i): int => $i->itemId, $this->grid->last?->items ?? []));
        self::assertSame(2, $this->source->calls);
    }

    public function testUnusableAttachmentsAreSkippedAndNotCounted(): void
    {
        $result = $this->pipeline($this->gallery(), 4, unusableAttachments: [102])->render($this->context());

        self::assertSame([1, 3, 4], array_map(static fn ($i): int => $i->itemId, $this->grid->last?->items ?? []));
        self::assertSame(3, $result->totalItems);
    }

    public function testItemsWithoutReferenceOrThumbnailAreSkippedAndEditorsAreTold(): void
    {
        $pipeline = $this->pipeline($this->gallery(), 3, unresolvable: [101]);
        $editor = $pipeline->render($this->context(self::OWNER));
        $visitor = $pipeline->render($this->context());

        self::assertSame(2, $editor->renderedItems);
        self::assertSame(RenderDiagnostic::IMAGES_UNAVAILABLE, $editor->diagnostics[0]->code);
        self::assertSame(1, $editor->diagnostics[0]->context['count']);
        self::assertSame([], $visitor->diagnostics);
    }

    public function testAGalleryWhoseImagesAreAllUnavailableRendersNothing(): void
    {
        $result = $this->pipeline($this->gallery(), 2, withMedia: false)->render($this->context(self::OWNER));

        self::assertSame('', $result->html);
        self::assertSame([], $result->assets);
        self::assertSame(RenderDiagnostic::IMAGES_UNAVAILABLE, $result->diagnostics[0]->code);
    }

    public function testTheFirstRowIsEagerAndOnlyTheFirstImageHasHighPriority(): void
    {
        $this->pipeline($this->gallery(), 4)->render($this->context());

        $loading = array_map(static fn ($i): LoadingStrategy => $i->loading, $this->grid->last?->items ?? []);
        self::assertSame([LoadingStrategy::Priority, LoadingStrategy::Eager, LoadingStrategy::Lazy, LoadingStrategy::Lazy], $loading);
    }

    public function testEagerLoadingCanBeDisabledPerEmbedAndPriorityPerPageOccurrence(): void
    {
        $pipeline = $this->pipeline($this->gallery(), 3);

        $pipeline->render($this->context(eager: false));
        $lazy = array_map(static fn ($i): LoadingStrategy => $i->loading, $this->grid->last?->items ?? []);
        $pipeline->render($this->context(priority: false));
        $noPriority = array_map(static fn ($i): LoadingStrategy => $i->loading, $this->grid->last?->items ?? []);

        self::assertSame([LoadingStrategy::Lazy, LoadingStrategy::Lazy, LoadingStrategy::Lazy], $lazy);
        self::assertSame([LoadingStrategy::Eager, LoadingStrategy::Eager, LoadingStrategy::Lazy], $noPriority);
    }

    public function testLaterPagesAreFullyLazy(): void
    {
        $this->pipeline($this->gallery(), 6)->render($this->context(page: 2, perPage: 3));

        $loading = array_unique(array_map(static fn ($i): LoadingStrategy => $i->loading, $this->grid->last?->items ?? []), SORT_REGULAR);
        self::assertSame([LoadingStrategy::Lazy], array_values($loading));
    }

    public function testTheFeedUsesTheSimplifiedMarkupWithoutAssetsOrEagerLoading(): void
    {
        $result = $this->pipeline($this->gallery())->render($this->context(entry: EntryPoint::Feed));

        self::assertSame('<ul>simple 3</ul>', $result->html);
        self::assertSame([], $result->assets);
        self::assertNull($this->grid->last);
        $loading = array_unique(array_map(static fn ($i): LoadingStrategy => $i->loading, $this->simple->last?->items ?? []), SORT_REGULAR);
        self::assertSame([LoadingStrategy::Lazy], array_values($loading));
    }

    public function testWithHtmlKeepsEverythingElse(): void
    {
        $result = $this->pipeline($this->gallery())->render($this->context());
        $copy = $result->withHtml('<p>x</p>');

        self::assertInstanceOf(RenderResult::class, $copy);
        self::assertSame('<p>x</p>', $copy->html);
        self::assertSame($result->totalItems, $copy->totalItems);
        self::assertSame($result->assets, $copy->assets);
        self::assertSame($result->layoutId, $copy->layoutId);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidContexts(): iterable
    {
        yield 'gallery' => [['galleryId' => 0]];
        yield 'page' => [['page' => 0]];
        yield 'per page low' => [['perPage' => 0]];
        yield 'per page high' => [['perPage' => 201]];
        yield 'instance' => [['instanceId' => 'Bad Id']];
        yield 'layout' => [['layoutOverride' => '../x']];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('invalidContexts')]
    public function testInvalidContextsAreRejected(array $override): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RenderContext(...($override + [
            'galleryId' => 1,
            'entryPoint' => EntryPoint::Block,
            'actor' => Actor::anonymous(),
            'instanceId' => 'swg-1-1',
        ]));
    }

    public function testImageHintsAndPageValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new \SWGallery\Core\Application\Render\RenderItemPage([], -1);
    }

    public function testImageHintsRejectInvalidValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new \SWGallery\Core\Domain\Layout\ImageHints(0, '100vw');
    }

    public function testImageHintsFallbackIsValid(): void
    {
        self::assertSame(480, \SWGallery\Core\Domain\Layout\ImageHints::fallback()->displayWidth);
    }
}
