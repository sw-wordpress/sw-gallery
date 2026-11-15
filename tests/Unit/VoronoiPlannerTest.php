<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWGallery\Core\Domain\Layout\Plan\PlanItem;
use SWGallery\Core\Domain\Layout\Plan\PlanSeed;
use SWGallery\Core\Domain\Layout\Plan\ShardPlan;
use SWGallery\Core\Domain\Layout\Plan\VoronoiPlanner;

final class VoronoiPlannerTest extends TestCase
{
    private const ASPECTS = [0.8, 1.0, 1.6, 2.4];

    /** @return list<PlanItem> */
    private function gallery(int $seed, int $size): array
    {
        $state = $seed * 2654435761 % 4294967296;
        $items = [];
        for ($i = 0; $i < $size; ++$i) {
            $state = ($state * 1103515245 + 12345) % 2147483648;
            $roll = intdiv($state, 65536) % 100;
            $items[] = new PlanItem(1.5, $roll < 60 ? 1 : ($roll < 88 ? 2 : 3));
        }

        return $items;
    }

    private static function assertValidPlan(ShardPlan $plan, int $count): void
    {
        self::assertCount($count, $plan->shards);
        $total = 0.0;
        foreach ($plan->shards as $shard) {
            self::assertGreaterThanOrEqual(3, $shard->cell->count());
            self::assertTrue($shard->cell->isConvex(1e-3), 'cell is convex');
            self::assertTrue($shard->polygon->isConvex(1e-3), 'drawn polygon is convex');
            self::assertGreaterThan(1.0, $shard->cell->area(), 'cell is not degenerate');
            self::assertLessThanOrEqual($shard->cell->area() + 0.01, $shard->polygon->area());
            self::assertGreaterThanOrEqual(0.2 * $shard->cell->area(), $shard->polygon->area());
            $total += $shard->cell->area();
            foreach ($shard->cell->points as $point) {
                self::assertGreaterThanOrEqual(0.0, $point[0]);
                self::assertLessThanOrEqual(100.0, $point[0]);
                self::assertGreaterThanOrEqual(0.0, $point[1]);
                self::assertLessThanOrEqual(100.0, $point[1]);
            }
            foreach ($shard->clip() as $point) {
                self::assertTrue(is_finite($point[0]) && is_finite($point[1]));
                self::assertGreaterThanOrEqual(0.0, $point[0]);
                self::assertLessThanOrEqual(100.0, $point[0]);
            }
            $box = $shard->box();
            self::assertGreaterThan(0.0, $box['w']);
            self::assertGreaterThan(0.0, $box['h']);
        }
        if ($count > 0) {
            self::assertEqualsWithDelta(10000.0, $total, 0.5, 'the cells tile the container exactly');
        }
    }

    public function testEmptyAndSingleGalleries(): void
    {
        $planner = new VoronoiPlanner();

        self::assertSame([], $planner->plan([], 1, 1.6)->shards);
        $one = $planner->plan([new PlanItem()], 1, 1.6);
        self::assertValidPlan($one, 1);
        self::assertSame(['x' => 0.0, 'y' => 0.0, 'w' => 100.0, 'h' => 100.0], $one->shards[0]->cell->boundingBox());
    }

    /** @return iterable<string, array{int}> */
    public static function sizes(): iterable
    {
        foreach ([2, 3, 4, 7, 12, 25, 60] as $size) {
            yield "$size items" => [$size];
        }
    }

    #[DataProvider('sizes')]
    public function testTilesTheContainerExactlyInEveryVariant(int $size): void
    {
        $plans = (new VoronoiPlanner())->variants($this->gallery(5, $size), PlanSeed::from(3, 1));

        self::assertSame(['n', 'm', 'w'], array_keys($plans));
        foreach ($plans as $plan) {
            self::assertValidPlan($plan, $size);
        }
        self::assertEqualsWithDelta(0.8, $plans['n']->aspectRatio, 1e-9);
        self::assertEqualsWithDelta(1.6, $plans['w']->aspectRatio, 1e-9);
    }

    public function testSameSeedGivesTheSamePlanAndAnotherSeedAnotherOne(): void
    {
        $planner = new VoronoiPlanner();
        $items = $this->gallery(9, 12);
        $a = $planner->plan($items, PlanSeed::from(1, 1), 1.6);
        $b = $planner->plan($items, PlanSeed::from(1, 1), 1.6);
        $c = $planner->plan($items, PlanSeed::from(1, 1, 1), 1.6);

        self::assertEquals($a, $b);
        self::assertNotEquals($a->shards[0]->polygon->points, $c->shards[0]->polygon->points);
        self::assertValidPlan($c, 12);
    }

    public function testAreasFollowTheEmphasisWeights(): void
    {
        $planner = new VoronoiPlanner();
        $errors = [];
        $byEmphasis = [1 => [], 2 => [], 3 => []];
        for ($seed = 1; $seed <= 20; ++$seed) {
            $items = $this->gallery($seed, 16);
            $weights = array_map(static fn (PlanItem $item): float => [1 => 1.0, 2 => 1.8, 3 => 3.0][$item->emphasis], $items);
            $plan = $planner->plan($items, PlanSeed::from($seed, 1), 1.6);
            foreach ($plan->shards as $index => $shard) {
                $share = $weights[$index] / array_sum($weights);
                $area = $shard->cell->area() / 10000;
                $errors[] = abs($area - $share) / $share;
                $byEmphasis[$items[$index]->emphasis][] = $area / $share;
            }
        }
        self::assertLessThan(0.08, array_sum($errors) / count($errors), 'mean relative area error');
        self::assertLessThan(0.5, max($errors), 'worst relative area error');
        foreach ($byEmphasis as $ratios) {
            if ($ratios !== []) {
                self::assertEqualsWithDelta(1.0, array_sum($ratios) / count($ratios), 0.05);
            }
        }
    }

    public function testExtremeEmphasisStillGivesHealthyShards(): void
    {
        $items = [];
        foreach ([3, 3, 1, 3, 1, 3, 1] as $emphasis) {
            $items[] = new PlanItem(1.5, $emphasis);
        }
        $plan = (new VoronoiPlanner())->plan($items, PlanSeed::from(2, 2), 1.6);

        self::assertValidPlan($plan, 7);
        self::assertGreaterThan($plan->shards[2]->cell->area() * 1.8, $plan->shards[0]->cell->area());
    }

    public function testGroutOpensGapsAndZeroGroutKeepsTheCells(): void
    {
        $planner = new VoronoiPlanner();
        $items = $this->gallery(4, 10);
        $none = $planner->plan($items, 11, 1.6, 0.0);
        $wide = $planner->plan($items, 11, 1.6, 12.0, 1000.0);

        foreach ($none->shards as $shard) {
            self::assertSame($shard->cell->points, $shard->polygon->points);
        }
        foreach ($wide->shards as $index => $shard) {
            self::assertLessThan($none->shards[$index]->cell->area(), $shard->polygon->area());
        }
        self::assertValidPlan($wide, 10);
    }

    public function testOversizedGroutIsReducedInsteadOfCollapsingShards(): void
    {
        $plan = (new VoronoiPlanner())->plan($this->gallery(6, 30), 5, 1.6, 48.0, 300.0);

        self::assertValidPlan($plan, 30);
    }

    public function testNonsenseInputIsClamped(): void
    {
        $planner = new VoronoiPlanner();
        $items = $this->gallery(1, 5);

        foreach ([NAN, INF, -3.0, 0.0, 99.0] as $aspect) {
            $plan = $planner->plan($items, 3, $aspect, NAN, -5.0);
            self::assertValidPlan($plan, 5);
            self::assertTrue(is_finite($plan->aspectRatio) && $plan->aspectRatio > 0.0);
        }
        self::assertValidPlan($planner->plan($items, 0, 1.0, INF, INF), 5);
    }

    public function testReadingOrderRankIsRowMajor(): void
    {
        // Two rows of two: the first row is the upper band, left before right.
        $rank = VoronoiPlanner::readingOrderRank([[1.5, 0.2], [0.5, 0.8], [0.5, 0.2], [1.5, 0.8]], 1.0);

        self::assertSame([1, 2, 0, 3], $rank);
    }

    public function testShardsFollowTheInputOrderApproximately(): void
    {
        $planner = new VoronoiPlanner();
        $inversions = 0;
        $pairs = 0;
        for ($seed = 1; $seed <= 20; ++$seed) {
            $items = $this->gallery($seed, 24);
            $plan = $planner->plan($items, PlanSeed::from($seed, 1), 1.6);
            $centres = [];
            foreach ($plan->shards as $shard) {
                $centre = $shard->cell->centroid();
                $centres[] = [$centre[0] / 100 * 1.6, $centre[1] / 100];
            }
            $rank = VoronoiPlanner::readingOrderRank($centres, 1.6);
            for ($i = 0; $i < 23; ++$i) {
                ++$pairs;
                $inversions += $rank[$i] > $rank[$i + 1] ? 1 : 0;
            }
        }

        self::assertLessThan(0.2, $inversions / $pairs, 'adjacent pictures that end up in reverse reading order');
    }

    public function testTwoHundredSeededGalleries(): void
    {
        $planner = new VoronoiPlanner();
        $sizes = [1, 2, 3, 5, 8, 12, 16, 24, 40, 60];
        for ($seed = 1; $seed <= 200; ++$seed) {
            $size = $sizes[$seed % 10];
            $plan = $planner->plan(
                $this->gallery($seed, $size),
                PlanSeed::from($seed, $seed % 7, $seed % 5),
                self::ASPECTS[$seed % 4],
                (float) ($seed % 13)
            );
            self::assertValidPlan($plan, $size);
        }
    }
}
