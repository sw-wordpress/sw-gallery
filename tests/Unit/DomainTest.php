<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWGallery\Core\Domain\Gallery\GalleryItem;
use SWGallery\Core\Domain\Gallery\ItemOverrides;
use SWGallery\Core\Domain\Gallery\Revision;

final class DomainTest extends TestCase
{
    public function testNullInheritsButEmptyStringOverrides(): void
    {
        $inherit = new ItemOverrides();
        self::assertSame('T', $inherit->resolveTitle('T'));
        self::assertSame('C', $inherit->resolveCaption('C'));
        self::assertSame('A', $inherit->resolveAlt('A'));

        $blank = new ItemOverrides('', '', '');
        self::assertSame('', $blank->resolveTitle('T'));
        self::assertSame('', $blank->resolveCaption('C'));
        self::assertSame('', $blank->resolveAlt('A'));
    }

    public function testRevisionIsMonotonicAndNonNegative(): void
    {
        self::assertSame(4, (new Revision(3))->next()->value);
        self::assertTrue((new Revision(3))->equals(new Revision(3)));
        $this->expectException(\InvalidArgumentException::class);
        new Revision(-1);
    }

    public function testItemRejectsInvalidIdentifiers(): void
    {
        self::assertSame(5, (new GalleryItem(1, 2, 3, 5))->position);
        $this->expectException(\InvalidArgumentException::class);
        new GalleryItem(1, 0, 3, 1);
    }
}
