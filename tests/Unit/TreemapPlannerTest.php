<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWGallery\Core\Domain\Layout\Plan\LayoutPlan;
use SWGallery\Core\Domain\Layout\Plan\PlanItem;
use SWGallery\Core\Domain\Layout\Plan\PlanSeed;
use SWGallery\Core\Domain\Layout\Plan\Shape;
use SWGallery\Core\Domain\Layout\Plan\TreemapPlanner;

final class TreemapPlannerTest extends TestCase
{
    private const RATIOS = [0.667, 0.75, 1.0, 1.333, 1.5, 1.778, 0.5625, 2.0, 3.0, 0.4];

    /**
     * Seeded pseudo random gallery (a small LCG, so the test does not depend on mt_rand's implementation).
     *
     * @return list<PlanItem>
     */
    private function gallery(int $seed, int $size): array
    {
        $state = $seed * 2654435761 % 4294967296;
        $next = static function (int $modulo) use (&$state): int {
            $state = ($state * 1103515245 + 12345) % 2147483648;

            return intdiv($state, 65536) % $modulo;
        };
        $items = [];
        for ($i = 0; $i < $size; ++$i) {
            $roll = $next(100);
            $emphasis = $roll < 60 ? 1 : ($roll < 88 ? 2 : 3);
            $items[] = new PlanItem(self::RATIOS[$next(10)], $emphasis);
        }

        return $items;
    }

    /** @param list<Shape> $shapes */
    private static function totalArea(array $shapes): float
    {
        return array_sum(array_map(static fn (Shape $s): float => $s->area(), $shapes));
    }

    private static function tileAspect(Shape $shape, LayoutPlan $plan): float
    {
        return $shape->w * $plan->aspectRatio / $shape->h;
    }

    /**
     * Pairs (i < j) that violate the reading order: the later picture must lie to the end side of the earlier one or
     * below it (the dominance order of rectangles: rows top to bottom, cells left to right, a stack top to bottom).
     */
    private static function discordantPairs(LayoutPlan $plan): int
    {
        $eps = 0.0011;
        $count = 0;
        $shapes = $plan->shapes;
        for ($i = 0; $i < count($shapes); ++$i) {
            for ($j = $i + 1; $j < count($shapes); ++$j) {
                $a = $shapes[$i];
                $b = $shapes[$j];
                if ($b->x < $a->x + $a->w - $eps && $b->y < $a->y + $a->h - $eps) {
                    ++$count;
                }
            }
        }

        return $count;
    }

    public function testEmptyAndSingleItem(): void
    {
        $planner = new TreemapPlanner();
        self::assertSame([], $planner->plan([], 1, 1.0)->shapes);
        $single = $planner->plan([new PlanItem(2.0)], 1, 1.6);
        self::assertCount(1, $single->shapes);
        self::assertSame(['x' => 0.0, 'y' => 0.0, 'w' => 100.0, 'h' => 100.0], $single->shapes[0]->toArray());
        self::assertSame(1.6, $single->aspectRatio);
    }

    /** @return iterable<string, array{int}> */
    public static function sizes(): iterable
    {
        foreach ([2, 3, 4, 5, 7, 10, 13, 24, 37, 60] as $size) {
            yield "n=$size" => [$size];
        }
    }

    #[DataProvider('sizes')]
    public function testTilesTheContainerExactly(int $size): void
    {
        $planner = new TreemapPlanner();
        foreach ($planner->variants($this->gallery($size, $size), 99) as $plan) {
            self::assertCount($size, $plan->shapes);
            self::assertEqualsWithDelta(10000.0, self::totalArea($plan->shapes), 0.5);
            foreach ($plan->shapes as $shape) {
                self::assertGreaterThanOrEqual(0.0, $shape->x);
                self::assertGreaterThanOrEqual(0.0, $shape->y);
                self::assertLessThanOrEqual(100.0, round($shape->x + $shape->w, 3));
                self::assertLessThanOrEqual(100.0, round($shape->y + $shape->h, 3));
                self::assertGreaterThan(0.0, $shape->w);
                self::assertGreaterThan(0.0, $shape->h);
            }
            // No overlaps: pairwise intersections have no area (shared edges are rounded identically).
            foreach ($plan->shapes as $i => $a) {
                foreach ($plan->shapes as $j => $b) {
                    if ($j <= $i) {
                        continue;
                    }
                    $w = min($a->x + $a->w, $b->x + $b->w) - max($a->x, $b->x);
                    $h = min($a->y + $a->h, $b->y + $b->h) - max($a->y, $b->y);
                    self::assertFalse($w > 0.0015 && $h > 0.0015, "tiles $i and $j overlap");
                }
            }
        }
    }

    public function testStackedMidpointsRoundOnTheIntegerPrecisionGrid(): void
    {
        // The narrow mosaic has an exact midpoint of 72.7275. Adding its decimal top first
        // gives 72.72749999999999, which PHP 8.4 correctly rounds down instead of up.
        $items = [
            new PlanItem(4 / 3, 3), new PlanItem(0.75), new PlanItem(2.0, 2),
            new PlanItem(4 / 3), new PlanItem(1.0), new PlanItem(1.5),
        ];
        $plan = (new TreemapPlanner())->plan($items, PlanSeed::from(12, 3), 0.8);
        self::assertSame(45.455, $plan->shapes[4]->y);
        self::assertSame(27.273, $plan->shapes[4]->h);
        self::assertSame(72.728, $plan->shapes[5]->y);
        self::assertSame(27.272, $plan->shapes[5]->h);
        self::assertEqualsWithDelta(100.0, $plan->shapes[5]->y + $plan->shapes[5]->h, 1e-9);
    }

    public function testAreaFollowsTheEmphasisWeights(): void
    {
        $planner = new TreemapPlanner();
        $items = $this->gallery(5, 12);
        $weights = array_map(static fn (PlanItem $i): float => TreemapPlanner::EMPHASIS_WEIGHTS[$i->emphasis], $items);
        $sum = array_sum($weights);
        foreach ($planner->variants($items, 7) as $plan) {
            foreach ($plan->shapes as $index => $shape) {
                self::assertEqualsWithDelta($weights[$index] / $sum, $shape->area() / 10000.0, 0.0006);
            }
        }
        self::assertSame([1 => 1.0, 2 => 1.8, 3 => 3.0], TreemapPlanner::EMPHASIS_WEIGHTS);
    }

    public function testDeterministicAndSeedDependent(): void
    {
        $planner = new TreemapPlanner();
        $items = $this->gallery(11, 30);
        $first = $planner->variants($items, 1234);
        self::assertEquals($first, $planner->variants($items, 1234));
        self::assertEquals($first, (new TreemapPlanner())->variants($items, 1234));
        $different = 0;
        foreach (range(1, 40) as $seed) {
            if ($planner->variants($items, $seed) != $first) {
                ++$different;
            }
        }
        self::assertGreaterThan(0, $different, 'the seed varies the arrangement');
    }

    public function testSeedFromGalleryAndRevision(): void
    {
        self::assertSame(PlanSeed::from(3, 4), PlanSeed::from(3, 4));
        self::assertNotSame(PlanSeed::from(3, 4), PlanSeed::from(3, 5));
        self::assertNotSame(PlanSeed::from(3, 4), PlanSeed::from(4, 4));
        self::assertNotSame(PlanSeed::from(3, 4), PlanSeed::from(3, 4, 1));
        self::assertGreaterThanOrEqual(0, PlanSeed::from(1, 1));
        self::assertLessThanOrEqual(4294967295, PlanSeed::from(1, 1));
    }

    public function testReadingOrderIsTheInputOrder(): void
    {
        $planner = new TreemapPlanner();
        foreach (range(1, 40) as $g) {
            $items = $this->gallery($g, 2 + $g % 50);
            foreach ($planner->variants($items, $g) as $plan) {
                self::assertSame(0, self::discordantPairs($plan), "gallery $g");
            }
        }
    }

    public function testPropertyAcrossTwoHundredGalleries(): void
    {
        $planner = new TreemapPlanner();
        $worst = 1.0;
        $inBounds = 0;
        $tiles = 0;
        for ($g = 0; $g < 200; ++$g) {
            $size = 1 + $g % 60;
            $items = $this->gallery($g + 1000, $size);
            $plans = $planner->variants($items, $g * 31);
            self::assertSame(['n', 'm', 'w'], array_keys($plans));
            foreach ($plans as $plan) {
                self::assertCount($size, $plan->shapes);
                self::assertEqualsWithDelta(10000.0, self::totalArea($plan->shapes), 0.5);
                foreach ($plan->shapes as $shape) {
                    foreach ($shape->toArray() as $number) {
                        self::assertTrue(is_finite($number));
                    }
                    if ($size >= 4) {
                        $aspect = self::tileAspect($shape, $plan);
                        $worst = max($worst, $aspect, 1 / $aspect);
                        ++$tiles;
                        $inBounds += ($aspect >= TreemapPlanner::MIN_TILE_ASPECT && $aspect <= TreemapPlanner::MAX_TILE_ASPECT) ? 1 : 0;
                    }
                }
            }
        }
        // Measured: worst below 3.6:1, about 98 % of the tiles inside 1:2.5..2.5:1 (galleries of 1..3 pictures are exempt: three pictures of weights 1:3:1 cannot be tiled within the bounds).
        self::assertLessThan(3.6, $worst);
        self::assertGreaterThan(0.97, $inBounds / $tiles);
    }

    public function testClampsOutOfRangeInputAndFallsBack(): void
    {
        $items = [
            new PlanItem(null, 9),
            new PlanItem(0.0, -4),
            new PlanItem(NAN, 2),
            new PlanItem(INF, 3, -20.0, 300.0),
            new PlanItem(1000.0, 1, NAN),
        ];
        self::assertSame(PlanItem::FALLBACK_RATIO, $items[0]->aspectRatio);
        self::assertSame(3, $items[0]->emphasis);
        self::assertSame(1, $items[1]->emphasis);
        self::assertSame(PlanItem::FALLBACK_RATIO, $items[2]->aspectRatio);
        self::assertSame(0.0, $items[3]->focalX);
        self::assertSame(100.0, $items[3]->focalY);
        self::assertSame(PlanItem::MAX_RATIO, $items[4]->aspectRatio);
        self::assertSame(50.0, $items[4]->focalX);
        self::assertSame(PlanItem::FALLBACK_RATIO, PlanItem::fromSize(0, 10)->aspectRatio);
        self::assertSame(2.0, PlanItem::fromSize(200, 100)->aspectRatio);

        $planner = new TreemapPlanner();
        foreach ([NAN, INF, -1.0, 0.0, 1000.0] as $aspect) {
            $plan = $planner->plan($items, 3, $aspect);
            self::assertTrue(is_finite($plan->aspectRatio));
            self::assertCount(5, $plan->shapes);
            self::assertEqualsWithDelta(10000.0, self::totalArea($plan->shapes), 0.5);
        }
    }

    public function testPlainArraysForTheView(): void
    {
        $plans = (new TreemapPlanner())->variants($this->gallery(2, 6), 4);
        $array = $plans['w']->toArray();
        self::assertSame(1.6, $array['aspect']);
        self::assertCount(6, $array['shapes']);
        self::assertSame(['x', 'y', 'w', 'h'], array_keys($array['shapes'][0]));
        self::assertSame(6, $plans['w']->count());
        self::assertSame([], (new Shape(1, 2, 3, 4))->points);
    }

    public function testInvalidValuesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Shape(0.0, 0.0, -1.0, 1.0);
    }

    public function testShapeRejectsNonFiniteNumbers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Shape(NAN, 0.0, 1.0, 1.0);
    }

    public function testPlanRejectsNonPositiveAspect(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LayoutPlan(0.0, []);
    }

    public function testRoundsToThreeDecimals(): void
    {
        $shape = new Shape(33.33333, 66.66666, 12.34567, 1.00049);
        self::assertSame(['x' => 33.333, 'y' => 66.667, 'w' => 12.346, 'h' => 1.0], $shape->toArray());
    }
}
