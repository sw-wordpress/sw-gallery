<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWGallery\Core\Domain\Layout\Scatter\ScatterPlan;
use SWGallery\Core\Domain\Layout\Scatter\ScatterPlanner;
use SWGallery\Core\Domain\Layout\Scatter\ScatterPrint;

final class ScatterPlannerTest extends TestCase
{
    /**
     * Independent check of the visible share: a fine 21 x 21 sample grid on the print, polygons built from their four
     * rotated corners and a cross product containment test (the planner uses rotation matrices and a 10 x 10 grid).
     *
     * @param list<ScatterPrint> $prints
     *
     * @return list<float>
     */
    private static function visibleShares(array $prints, float $overlap, float $ratio, int $columns, float $k = 1.0, float $o = 1.0): array
    {
        $cellHeight = 1.0 / $ratio;
        $scale = 1.0 + $overlap * $o / 100.0;
        $polygons = [];
        foreach ($prints as $index => $print) {
            $cx = ($index % $columns) + 0.5 + $print->dx * $k / 100.0;
            $cy = (intdiv($index, $columns) + 0.5) * $cellHeight + $print->dy * $k / 100.0 * $cellHeight;
            $angle = deg2rad($print->rotation * $k);
            $corners = [];
            foreach ([[-1, -1], [1, -1], [1, 1], [-1, 1]] as [$sx, $sy]) {
                $lx = $sx * $scale / 2.0;
                $ly = $sy * $scale * $cellHeight / 2.0;
                $corners[] = [$cx + $lx * cos($angle) - $ly * sin($angle), $cy + $lx * sin($angle) + $ly * cos($angle)];
            }
            $polygons[] = $corners;
        }
        $inside = static function (array $polygon, float $x, float $y): bool {
            $sign = 0;
            for ($i = 0; $i < 4; ++$i) {
                [$ax, $ay] = $polygon[$i];
                [$bx, $by] = $polygon[($i + 1) % 4];
                $cross = ($bx - $ax) * ($y - $ay) - ($by - $ay) * ($x - $ax);
                if ($cross === 0.0) {
                    continue;
                }
                $current = $cross > 0 ? 1 : -1;
                if ($sign !== 0 && $current !== $sign) {
                    return false;
                }
                $sign = $current;
            }

            return true;
        };
        $near = [];
        foreach ($prints as $index => $_) {
            $near[$index] = [];
            foreach ($prints as $other => $__) {
                $far = abs(intdiv($index, $columns) - intdiv($other, $columns)) > 2 || abs($index % $columns - $other % $columns) > 2;
                if ($other !== $index && !$far) {
                    $near[$index][] = $other;
                }
            }
        }
        $shares = [];
        $steps = 21;
        foreach ($prints as $index => $print) {
            [$p0, $p1, , $p3] = $polygons[$index];
            $visible = 0;
            for ($i = 0; $i < $steps; ++$i) {
                for ($j = 0; $j < $steps; ++$j) {
                    $u = ($i + 0.5) / $steps;
                    $v = ($j + 0.5) / $steps;
                    $x = $p0[0] + $u * ($p1[0] - $p0[0]) + $v * ($p3[0] - $p0[0]);
                    $y = $p0[1] + $u * ($p1[1] - $p0[1]) + $v * ($p3[1] - $p0[1]);
                    $covered = false;
                    foreach ($near[$index] as $other) {
                        if ($prints[$other]->z > $print->z && $inside($polygons[$other], $x, $y)) {
                            $covered = true;
                            break;
                        }
                    }
                    $visible += $covered ? 0 : 1;
                }
            }
            $shares[] = $visible / ($steps * $steps);
        }

        return $shares;
    }

    public function testEmptyAndSinglePrint(): void
    {
        $planner = new ScatterPlanner();
        $none = $planner->plan(0, 5, 5.0, 12.0, 1.5, [3]);
        self::assertSame([], $none->prints);
        self::assertSame(1.0, $none->minVisibility);
        $one = $planner->plan(1, 5, 5.0, 12.0, 1.5, [3]);
        self::assertCount(1, $one->prints);
        self::assertSame(1, $one->prints[0]->z);
        self::assertSame(1.0, $one->minVisibility);
    }

    public function testDeterministicAndSeedDependent(): void
    {
        $planner = new ScatterPlanner();
        $a = $planner->plan(14, 4711, 5.0, 12.0, 1.5, [3, 4]);
        $b = $planner->plan(14, 4711, 5.0, 12.0, 1.5, [3, 4]);
        self::assertEquals($a, $b);
        $other = $planner->plan(14, 4712, 5.0, 12.0, 1.5, [3, 4]);
        self::assertNotEquals($a->prints, $other->prints);
        // Another planner instance gives the same numbers (no hidden state).
        self::assertEquals($a, (new ScatterPlanner())->plan(14, 4711, 5.0, 12.0, 1.5, [3, 4]));
    }

    public function testRotationOffsetsAndStackingAreBounded(): void
    {
        $planner = new ScatterPlanner();
        foreach ([1, 7, 23, 60] as $count) {
            foreach ([0.0, 3.0, 12.0] as $tilt) {
                $plan = $planner->plan($count, $count * 31, $tilt, 40.0, 1.0, [2, 5]);
                self::assertCount($count, $plan->prints);
                $z = [];
                foreach ($plan->prints as $print) {
                    self::assertLessThanOrEqual($tilt, abs($print->rotation));
                    self::assertLessThanOrEqual(8.0, abs($print->dx));
                    self::assertLessThanOrEqual(8.0, abs($print->dy));
                    self::assertEqualsWithDelta(round($print->rotation, 2), $print->rotation, 1e-9);
                    $z[] = $print->z;
                }
                sort($z);
                self::assertSame(range(1, $count), $z, 'The stacking order is a permutation of 1..n.');
                self::assertLessThanOrEqual(40.0, $plan->overlap);
                self::assertGreaterThanOrEqual(0.0, $plan->bleedX);
                self::assertLessThan(1.0, $plan->bleedX);
                self::assertLessThan(1.0, $plan->bleedY);
            }
        }
    }

    public function testNoOverlapMeansNoOffsetsAndTiltScalesTheRotation(): void
    {
        $planner = new ScatterPlanner();
        $flat = $planner->plan(12, 9, 6.0, 0.0, 1.5, [3]);
        self::assertSame(0.0, $flat->overlap);
        foreach ($flat->prints as $print) {
            self::assertSame(0.0, $print->dx + 0.0);
            self::assertSame(0.0, $print->dy + 0.0);
        }
        self::assertNotEmpty(array_filter($flat->prints, static fn (ScatterPrint $p): bool => abs($p->rotation) > 0.5));
        $straight = $planner->plan(12, 9, 0.0, 12.0, 1.5, [3]);
        foreach ($straight->prints as $print) {
            self::assertSame(0.0, $print->rotation + 0.0);
        }
    }

    public function testNeighboursRarelyLeanTheSameWay(): void
    {
        $plan = (new ScatterPlanner())->plan(60, 77, 8.0, 12.0, 1.5, [3]);
        $same = 0;
        for ($i = 1; $i < 60; ++$i) {
            $same += $plan->prints[$i]->rotation * $plan->prints[$i - 1]->rotation > 0 ? 1 : 0;
        }
        self::assertLessThan(30, $same, 'The sign mostly alternates between consecutive prints.');
    }

    /** @return iterable<string, array{float, float, list<int>, float}> */
    public static function settings(): iterable
    {
        yield 'default' => [5.0, 12.0, [3], 1.5];
        yield 'strong' => [12.0, 40.0, [2, 5, 8], 1.0];
        yield 'portrait cells' => [8.0, 25.0, [2, 3], 2 / 3];
        yield 'wide cells, many columns' => [10.0, 30.0, [6, 8], 4 / 3];
        yield 'no tilt' => [0.0, 40.0, [3, 5], 1.5];
    }

    /**
     * @param list<int> $columns
     */
    #[DataProvider('settings')]
    public function testEveryPrintStaysAtLeastSixtyPercentVisible(float $tilt, float $overlap, array $columns, float $ratio): void
    {
        $planner = new ScatterPlanner();
        $worst = 1.0;
        foreach ([1, 2, 5, 13, 24, 60] as $index => $count) {
            $plan = $planner->plan($count, 1000 + 97 * $index, $tilt, $overlap, $ratio, $columns);
            self::assertGreaterThanOrEqual(ScatterPlanner::MIN_VISIBILITY, $plan->minVisibility);
            $grids = [];
            foreach ($columns as $c) {
                $grids[] = [$c, 1.0, 1.0];
                $grids[] = [$c, ScatterPlanner::TOUCH_FACTOR, ScatterPlanner::TOUCH_OVERLAP_FACTOR];
            }
            $grids[] = [2, ScatterPlanner::NARROW_FACTOR, 1.0];
            $grids[] = [2, ScatterPlanner::NARROW_FACTOR, ScatterPlanner::TOUCH_OVERLAP_FACTOR];
            foreach ($grids as [$c, $k, $o]) {
                $shares = self::visibleShares($plan->prints, $plan->overlap, $ratio, $c, $k, $o);
                $worst = min($worst, ...$shares);
                self::assertGreaterThanOrEqual(ScatterPlanner::MIN_VISIBILITY, min($shares), "count $count, $c columns, k $k, o $o");
                // The planner's own measurement agrees with the independent one within the sampling error.
                $own = $planner->visibility($plan->prints, $plan->overlap, $ratio, $c, $k, $o);
                self::assertEqualsWithDelta(array_sum($shares) / $count, array_sum($own) / $count, 0.05);
            }
        }
        self::assertGreaterThanOrEqual(ScatterPlanner::MIN_VISIBILITY, $worst);
    }

    public function testManySeededGalleriesKeepTheGuarantee(): void
    {
        $planner = new ScatterPlanner();
        $min = 1.0;
        for ($seed = 1; $seed <= 30; ++$seed) {
            $count = 1 + ($seed * 7) % 60;
            $plan = $planner->plan($count, $seed * 7919, 5.0 + $seed % 8, 12.0 + $seed % 29, 1.5, [3]);
            $min = min($min, $plan->minVisibility);
        }
        self::assertGreaterThanOrEqual(ScatterPlanner::MIN_VISIBILITY, $min);
    }

    public function testTooMuchOverlapIsReducedAndTheDefaultIsKept(): void
    {
        $planner = new ScatterPlanner();
        $default = $planner->plan(30, 5, 5.0, 12.0, 1.5, [3]);
        self::assertSame(12.0, $default->overlap, 'The default overlap needs no reduction.');
        $strong = $planner->plan(30, 5, 12.0, 40.0, 1.5, [3]);
        self::assertLessThan(40.0, $strong->overlap);
        self::assertGreaterThan(12.0, $strong->overlap);
    }

    public function testBadInputIsClampedNeverThrows(): void
    {
        $planner = new ScatterPlanner();
        $plan = $planner->plan(5, -3, NAN, INF, -2.0, [0, 99, -1]);
        self::assertCount(5, $plan->prints);
        foreach ($plan->prints as $print) {
            self::assertTrue(is_finite($print->rotation) && is_finite($print->dx) && is_finite($print->dy));
        }
        $none = $planner->plan(3, 1, 5.0, 12.0, 1.5, []);
        self::assertCount(3, $none->prints);
        self::assertSame([], $planner->plan(-4, 1, 5.0, 12.0, 1.5, [3])->prints);
    }

    public function testValueObjectsRejectBadNumbers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ScatterPrint(NAN, 0.0, 0.0, 1);
    }

    public function testStackingStartsAtOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ScatterPrint(0.0, 0.0, 0.0, 0);
    }

    public function testPlanKeepsItsParts(): void
    {
        $plan = new ScatterPlan([new ScatterPrint(1.0, 2.0, 3.0, 4)], 12.0, 0.1, 0.2, 0.9);
        self::assertSame(12.0, $plan->overlap);
        self::assertSame(4, $plan->prints[0]->z);
    }
}
