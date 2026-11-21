<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\HoverAnimation;
use SWGallery\Core\Domain\Gallery\HoverEffect;
use SWGallery\Core\Domain\Gallery\SortMode;

final class HoverEffectTest extends TestCase
{
    public function testDefaultsAreInactiveWithFade(): void
    {
        $effect = new HoverEffect();

        self::assertFalse($effect->isActive());
        self::assertSame(HoverAnimation::Fade, $effect->animation);
        self::assertTrue((new HoverEffect(true))->isActive());
        self::assertTrue((new HoverEffect(false, true))->isActive());
    }

    public function testArrayRoundTripAndEquality(): void
    {
        $effect = new HoverEffect(true, false, HoverAnimation::SlideUp);

        self::assertSame(['title' => true, 'caption' => false, 'animation' => 'slide-up'], $effect->toArray());
        self::assertTrue($effect->equals(HoverEffect::fromArray($effect->toArray())));
        self::assertFalse($effect->equals(new HoverEffect(true, false, HoverAnimation::Cover)));
        self::assertFalse($effect->equals(null));
    }

    public function testFromArrayFillsMissingKeysAndIgnoresUnknownOnes(): void
    {
        $effect = HoverEffect::fromArray(['caption' => true, 'future' => 1]);

        self::assertNotNull($effect);
        self::assertSame(['title' => false, 'caption' => true, 'animation' => 'fade'], $effect->toArray());
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidShapes(): iterable
    {
        yield 'null' => [null];
        yield 'string' => ['{"title":true}'];
        yield 'title not bool' => [['title' => 'yes']];
        yield 'caption not bool' => [['caption' => 1]];
        yield 'animation not string' => [['animation' => 3]];
        yield 'animation unknown' => [['animation' => 'spin']];
    }

    #[DataProvider('invalidShapes')]
    public function testFromArrayRejectsInvalidShapes(mixed $data): void
    {
        self::assertNull(HoverEffect::fromArray($data));
    }

    public function testSettingsKeepTheHoverAcrossWithers(): void
    {
        $hover = new HoverEffect(true, true, HoverAnimation::Cover);
        $settings = (new GallerySettings())->withHover($hover);

        self::assertSame($hover, $settings->hover);
        $changed = $settings->withLayout('masonry')->withSortMode(SortMode::TitleAsc)->withValues(['a' => 1]);
        self::assertSame($hover, $changed->withPerPage(9)->hover);
        self::assertNull($settings->withHover(null)->hover);
        self::assertNull((new GallerySettings())->hover);
    }
}
