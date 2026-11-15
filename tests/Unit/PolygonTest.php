<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWGallery\Core\Domain\Layout\Plan\Polygon;

final class PolygonTest extends TestCase
{
    private static function square(float $x0, float $y0, float $x1, float $y1): Polygon
    {
        return new Polygon([[$x0, $y0], [$x1, $y0], [$x1, $y1], [$x0, $y1]]);
    }

    public function testRoundsAndDropsDuplicateVertices(): void
    {
        $polygon = new Polygon([[0.00049, 0], [10.0004, 0], [10.0004, 0.0001], [10, 10], [0, 10], [0.0001, 0.0002]]);

        self::assertSame([[0.0, 0.0], [10.0, 0.0], [10.0, 10.0], [0.0, 10.0]], $polygon->points);
        self::assertSame(4, $polygon->count());
    }

    public function testRejectsNonFiniteCoordinates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Polygon([[0, 0], [NAN, 1], [1, 1]]);
    }

    public function testAreaCentroidAndBoundingBox(): void
    {
        $polygon = self::square(10, 20, 30, 60);

        self::assertEqualsWithDelta(800.0, $polygon->area(), 1e-9);
        self::assertEqualsWithDelta(20.0, $polygon->centroid()[0], 1e-9);
        self::assertEqualsWithDelta(40.0, $polygon->centroid()[1], 1e-9);
        self::assertSame(['x' => 10.0, 'y' => 20.0, 'w' => 20.0, 'h' => 40.0], $polygon->boundingBox());
    }

    public function testDegenerateAndEmptyPolygonsAreHandled(): void
    {
        $empty = new Polygon([]);
        self::assertSame(0.0, $empty->area());
        self::assertSame([0.0, 0.0], $empty->centroid());
        self::assertSame(['x' => 0.0, 'y' => 0.0, 'w' => 0.0, 'h' => 0.0], $empty->boundingBox());
        self::assertSame([], $empty->relativePoints());
        self::assertFalse($empty->isConvex());
        self::assertNull($empty->inset(2.0, 10.0, 10.0));

        $line = new Polygon([[0, 0], [10, 10], [20, 20]]);
        self::assertSame([10.0, 10.0], $line->centroid());
        self::assertFalse($line->isConvex());

        $point = new Polygon([[5, 5]]);
        self::assertSame([[0.0, 0.0]], $point->relativePoints());
    }

    public function testRelativePointsAreInsideZeroToHundred(): void
    {
        $polygon = new Polygon([[10, 20], [30, 20], [20, 60]]);

        self::assertSame([[0.0, 0.0], [100.0, 0.0], [50.0, 100.0]], $polygon->relativePoints());
    }

    public function testConvexity(): void
    {
        self::assertTrue(self::square(0, 0, 10, 10)->isConvex());
        self::assertFalse((new Polygon([[0, 0], [10, 0], [5, 2], [10, 10], [0, 10]]))->isConvex());
    }

    public function testInsetMovesInnerEdgesByAPixelDistanceAndKeepsBorderEdges(): void
    {
        // Left edge on the container border (x = 0) stays, the other three move by 5 px = 0.5 percent at 10 px per percent.
        $inset = self::square(0, 10, 30, 30)->inset(5.0, 10.0, 10.0);

        self::assertNotNull($inset);
        self::assertSame(['x' => 0.0, 'y' => 10.5, 'w' => 29.5, 'h' => 19.0], $inset->boundingBox());
    }

    public function testInsetUsesPixelsOnBothAxes(): void
    {
        // 20 px per percent horizontally, 10 vertically: the same 10 px distance is 0.5 percent resp. 1 percent.
        $inset = self::square(10, 10, 30, 30)->inset(10.0, 20.0, 10.0);

        self::assertNotNull($inset);
        self::assertSame(['x' => 10.5, 'y' => 11.0, 'w' => 19.0, 'h' => 18.0], $inset->boundingBox());
    }

    public function testInsetOfClockwisePolygonAndZeroDistance(): void
    {
        $clockwise = new Polygon([[10, 10], [10, 30], [30, 30], [30, 10]]);
        $inset = $clockwise->inset(10.0, 10.0, 10.0);

        self::assertNotNull($inset);
        self::assertSame(['x' => 11.0, 'y' => 11.0, 'w' => 18.0, 'h' => 18.0], $inset->boundingBox());
        self::assertSame($clockwise, $clockwise->inset(0.0, 10.0, 10.0));
    }

    public function testInsetOfATriangleKeepsItConvexAndSmaller(): void
    {
        $triangle = new Polygon([[10, 10], [50, 10], [30, 50]]);
        $inset = $triangle->inset(8.0, 10.0, 10.0);

        self::assertNotNull($inset);
        self::assertTrue($inset->isConvex());
        self::assertLessThan($triangle->area(), $inset->area());
        self::assertGreaterThan(0.0, $inset->area());
    }

    public function testInsetCollapsesToNullWhenTheDistanceIsTooLarge(): void
    {
        self::assertNull(self::square(10, 10, 12, 12)->inset(200.0, 10.0, 10.0));
        self::assertNull(self::square(10, 10, 12, 12)->inset(1.0, 0.0, 10.0));
    }
}
