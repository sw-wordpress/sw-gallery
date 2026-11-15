<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWGallery\Core\Domain\Layout\Plan\CirclePacker;
use SWGallery\Core\Domain\Layout\Plan\LayoutPlan;
use SWGallery\Core\Domain\Layout\Plan\PlanItem;

final class CirclePackerTest extends TestCase
{
    /**
     * Seeded pseudo random gallery (small LCG, independent of mt_rand).
     *
     * @return list<PlanItem>
     */
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

    /**
     * Circles in container units (height 1, width = aspect ratio): [cx, cy, r].
     *
     * @return list<array{0: float, 1: float, 2: float}>
     */
    private static function circles(LayoutPlan $plan): array
    {
        $circles = [];
        foreach ($plan->shapes as $shape) {
            $radius = $shape->h / 200.0;
            // Square in pixels: width percent times the ratio equals height percent.
            self::assertEqualsWithDelta($shape->h, $shape->w * $plan->aspectRatio, 0.01);
            $circles[] = [
                ($shape->x + $shape->w / 2.0) / 100.0 * $plan->aspectRatio,
                ($shape->y + $shape->h / 2.0) / 100.0,
                $radius,
            ];
        }

        return $circles;
    }

    private static function assertValid(LayoutPlan $plan, int $count): void
    {
        self::assertCount($count, $plan->shapes);
        $circles = self::circles($plan);
        $problems = [];
        foreach ($circles as $i => [$x, $y, $r]) {
            if (!is_finite($x) || !is_finite($y) || !is_finite($r) || $r <= 0.0) {
                $problems[] = "circle $i is not a finite positive circle";
            }
            if ($x - $r < -0.001 || $x + $r > $plan->aspectRatio + 0.001 || $y - $r < -0.001 || $y + $r > 1.001) {
                $problems[] = "circle $i leaves the container";
            }
            foreach ($circles as $j => [$x2, $y2, $r2]) {
                if ($j > $i && sqrt(($x - $x2) ** 2 + ($y - $y2) ** 2) < $r + $r2 - 0.001) {
                    $problems[] = "circles $i and $j overlap";
                }
            }
        }
        self::assertSame([], $problems);
    }

    public function testNoCirclesOneAndTwo(): void
    {
        $packer = new CirclePacker();
        self::assertSame([], $packer->plan([], 1.6)->shapes);

        $one = $packer->plan([new PlanItem()], 1.6);
        self::assertValid($one, 1);
        $shape = $one->shapes[0];
        self::assertEqualsWithDelta(100.0, $shape->h, 0.05, 'A single circle fills the container height.');
        self::assertEqualsWithDelta(50.0, $shape->x + $shape->w / 2.0, 0.01, 'and is centred.');

        $portrait = $packer->plan([new PlanItem()], 0.8);
        self::assertEqualsWithDelta(100.0, $portrait->shapes[0]->w, 0.05, 'In a portrait container the width limits it.');
        self::assertValid($portrait, 1);

        $two = $packer->plan([new PlanItem(), new PlanItem(null, 3)], 1.0);
        self::assertValid($two, 2);
        self::assertGreaterThan($two->shapes[0]->w, $two->shapes[1]->w);
    }

    public function testAreasFollowTheEmphasisWeights(): void
    {
        $plan = (new CirclePacker())->plan([new PlanItem(null, 1), new PlanItem(null, 2), new PlanItem(null, 3), new PlanItem(null, 1)], 1.0);
        $areas = array_map(static fn ($s): float => $s->w * $s->w, $plan->shapes);
        self::assertEqualsWithDelta(1.8, $areas[1] / $areas[0], 0.01);
        self::assertEqualsWithDelta(3.0, $areas[2] / $areas[0], 0.01);
        self::assertEqualsWithDelta(1.0, $areas[3] / $areas[0], 0.001);
    }

    public function testDeterministic(): void
    {
        $items = $this->gallery(5, 23);
        self::assertEquals((new CirclePacker())->variants($items), (new CirclePacker())->variants($items));
    }

    public function testValidAndDenseOnManySeededGalleries(): void
    {
        $packer = new CirclePacker();
        $worst = ['n' => 1.0, 'm' => 1.0, 'w' => 1.0];
        $sum = 0.0;
        $counted = 0;
        for ($seed = 1; $seed <= 200; ++$seed) {
            $size = 1 + ($seed * 7) % 60;
            if ($seed <= 40) {
                $size = 1 + $seed % 12;
            }
            $items = $this->gallery($seed, $size);
            foreach ($packer->variants($items) as $key => $plan) {
                self::assertValid($plan, $size);
                $density = CirclePacker::density($plan);
                self::assertTrue(is_finite($density));
                if ($size >= 10) {
                    $worst[$key] = min($worst[$key], $density);
                    $sum += $density;
                    ++$counted;
                }
            }
        }
        foreach ($worst as $key => $density) {
            self::assertGreaterThanOrEqual(0.55, $density, "worst density of galleries with 10+ circles ($key)");
        }
        self::assertGreaterThan(0.65, $sum / $counted, 'mean density');
    }

    public function testBadAspectRatiosAreClamped(): void
    {
        $packer = new CirclePacker();
        $items = $this->gallery(3, 9);
        self::assertSame(5.0, $packer->plan($items, 99.0)->aspectRatio);
        self::assertSame(0.2, $packer->plan($items, -1.0)->aspectRatio);
        self::assertSame(1.0, $packer->plan($items, NAN)->aspectRatio);
        self::assertValid($packer->plan($items, 5.0), 9);
        self::assertValid($packer->plan($items, 0.2), 9);
    }

    public function testReadingOrderRoughlyFollowsTheInputOrder(): void
    {
        $plan = (new CirclePacker())->plan($this->gallery(11, 30), 1.6);
        $tops = array_map(static fn ($s): float => $s->y + $s->h / 2.0, $plan->shapes);
        $inversions = 0;
        $pairs = 0;
        foreach ($tops as $i => $a) {
            foreach (array_slice($tops, $i + 1) as $b) {
                ++$pairs;
                $inversions += $b < $a - 25.0 ? 1 : 0;
            }
        }
        self::assertLessThan($pairs * 0.05, $inversions, 'Later circles are rarely far above earlier ones.');
    }
}
