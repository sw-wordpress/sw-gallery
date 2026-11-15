<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout\Scatter;

/**
 * Seeded arrangement of "photo prints lying on a table" (pure PHP, no WordPress). A print is a grid cell scaled up by the
 * overlap, rotated about its centre and moved by a small offset; the stylesheet draws exactly that transform, so the
 * geometry below is the geometry on screen.
 *
 * Determinism: every number follows from the seed (gallery id + revision + the `variation` setting) with a xorshift
 * generator. Rotation is the tilt times a signed magnitude of 0.25..1 whose sign mostly alternates between neighbours
 * (so neighbouring prints rarely lean the same way); offsets reach 0.4 times the overlap percent of a cell at most (8 %).
 *
 * Visibility guarantee. The stacking order starts as a seeded permutation (reading order with a jitter of 2.5 positions, like prints dealt one after another). For every grid the page can show (each
 * column count, the narrow two column grid with halved tilt and offsets, touch devices with the reduced overlap) the
 * visible share of every print is measured on the rotated rectangles with an 10 x 10 sample grid. A print below
 * {@see TARGET_VISIBILITY} is lifted to the top; if lifting cannot fix everything, the overlap (and with it the offsets) is
 * bisected down to the largest value that works. The measured minimum is returned; the target leaves a margin above the promised
 * {@see MIN_VISIBILITY} (60 %) for the sampling error.
 */
final class ScatterPlanner
{
    /** Promised minimum visible share of every print. */
    public const MIN_VISIBILITY = 0.6;
    /** What the search aims for (margin for the sampling error). */
    public const TARGET_VISIBILITY = 0.68;

    public const MAX_TILT = 12.0;
    public const MAX_OVERLAP = 40.0;
    public const MIN_COLUMNS = 2;
    public const MAX_COLUMNS = 8;

    /** Narrow containers: two columns, tilt and offsets halved. */
    public const NARROW_FACTOR = 0.5;
    /** Touch devices: tilt and offsets times 0.6, overlap times 0.5. */
    public const TOUCH_FACTOR = 0.6;
    public const TOUCH_OVERLAP_FACTOR = 0.5;

    private const MAX_OFFSET = 8.0;
    private const OFFSET_PER_OVERLAP = 0.4;
    private const SAMPLES = 10;
    private const BISECTIONS = 5;
    private const Z_JITTER = 2.5;

    /** @var list<array{0: float, 1: float}> Sample points in the unit square around the centre (-0.5..0.5). */
    private array $samples = [];

    public function __construct()
    {
        for ($i = 0; $i < self::SAMPLES; ++$i) {
            for ($j = 0; $j < self::SAMPLES; ++$j) {
                $this->samples[] = [($i + 0.5) / self::SAMPLES - 0.5, ($j + 0.5) / self::SAMPLES - 0.5];
            }
        }
    }

    /**
     * @param int       $count        Number of prints (0 gives an empty plan).
     * @param int       $seed         32 bit seed, see PlanSeed (include the `variation` as its shuffle part).
     * @param float     $tilt         Maximum rotation in degrees (0..12).
     * @param float     $overlap      Overlap in percent of the cell width (0..40).
     * @param float     $cellRatio    Cell width divided by height (> 0).
     * @param list<int> $columnCounts Column counts the grid can have (each clamped to 2..8).
     */
    public function plan(int $count, int $seed, float $tilt, float $overlap, float $cellRatio, array $columnCounts): ScatterPlan
    {
        $tilt = is_finite($tilt) ? max(0.0, min(self::MAX_TILT, $tilt)) : 0.0;
        $overlap = is_finite($overlap) ? max(0.0, min(self::MAX_OVERLAP, $overlap)) : 0.0;
        $ratio = is_finite($cellRatio) && $cellRatio > 0.0 ? max(0.25, min(4.0, $cellRatio)) : 1.5;
        if ($count < 1) {
            return new ScatterPlan([], round($overlap, 2), 0.0, 0.0, 1.0);
        }

        $grids = $this->grids($columnCounts);
        $random = $this->draw($count, $seed);
        $plan = $this->attempt($count, $random, $tilt, $overlap, $ratio, $grids);
        if ($plan->minVisibility >= self::TARGET_VISIBILITY) {
            return $plan;
        }
        // Too much overlap for the guarantee: bisect the overlap (and with it the offsets) for the largest that works.
        $low = 0.0;
        $high = 1.0;
        $best = null;
        for ($round = 0; $round < self::BISECTIONS; ++$round) {
            $middle = ($low + $high) / 2.0;
            $candidate = $this->attempt($count, $random, $tilt, round($overlap * $middle, 2), $ratio, $grids);
            if ($candidate->minVisibility >= self::TARGET_VISIBILITY) {
                $best = $candidate;
                $low = $middle;
            } else {
                $high = $middle;
            }
        }

        return $best ?? $this->attempt($count, $random, $tilt, 0.0, $ratio, $grids);
    }

    /**
     * Visible share (0..1) of every print for one grid, measured on the rotated rectangles; for callers that want to
     * double check a plan.
     *
     * @param list<ScatterPrint> $prints
     *
     * @return list<float>
     */
    public function visibility(array $prints, float $overlap, float $cellRatio, int $columns, float $factor = 1.0, float $overlapFactor = 1.0): array
    {
        $grid = ['cols' => max(1, $columns), 'k' => $factor, 'o' => $overlapFactor];
        $geometry = $this->geometry($prints, $overlap, $cellRatio, $grid);
        $z = array_map(static fn (ScatterPrint $p): int => $p->z, $prints);
        $result = [];
        foreach (array_keys($prints) as $index) {
            $result[] = $this->share($geometry, $z, $index, $this->neighbours(count($prints), $grid['cols'], $index));
        }

        return $result;
    }

    /**
     * @param list<int> $columnCounts
     *
     * The grids the stacking order is repaired for. The touch variants (reduced tilt and overlap) are only verified
     * afterwards by {@see verified()}: they are almost always easier than the full strength grid of the same columns.
     *
     * @return list<array{cols: int, k: float, o: float}>
     */
    private function grids(array $columnCounts): array
    {
        $counts = [];
        foreach ($columnCounts as $columns) {
            $counts[max(self::MIN_COLUMNS, min(self::MAX_COLUMNS, $columns))] = true;
        }
        if ($counts === []) {
            $counts[3] = true;
        }
        $grids = [];
        foreach (array_keys($counts) as $columns) {
            $grids[] = ['cols' => $columns, 'k' => 1.0, 'o' => 1.0];
        }
        $grids[] = ['cols' => 2, 'k' => self::NARROW_FACTOR, 'o' => 1.0];

        return $grids;
    }

    /**
     * Random draws that do not depend on the settings, so a smaller overlap keeps the look of the arrangement.
     *
     * @return array{rot: list<float>, dx: list<float>, dy: list<float>, z: array<int, int>}
     */
    private function draw(int $count, int $seed): array
    {
        $state = ($seed & 0xFFFFFFFF) ?: 0x9E3779B9;
        $next = static function () use (&$state): float {
            $state ^= ($state << 13) & 0xFFFFFFFF;
            $state ^= $state >> 17;
            $state ^= ($state << 5) & 0xFFFFFFFF;

            return $state / 4294967296.0;
        };
        for ($i = 0; $i < 8; ++$i) {
            $next();
        }
        $rot = [];
        $dx = [];
        $dy = [];
        $sign = $next() < 0.5 ? 1.0 : -1.0;
        for ($i = 0; $i < $count; ++$i) {
            $sign = $next() < 0.8 ? -$sign : $sign;
            $rot[] = $sign * (0.25 + 0.75 * $next());
            $dx[] = 2.0 * $next() - 1.0;
            $dy[] = 2.0 * $next() - 1.0;
        }
        // Stacking: DOM order with a seeded jitter (like prints dealt one after the other and shuffled a little), as a permutation.
        $keys = [];
        for ($i = 0; $i < $count; ++$i) {
            $keys[$i] = $i + (2.0 * $next() - 1.0) * self::Z_JITTER;
        }
        asort($keys);
        $order = array_fill(0, $count, 0);
        $position = 1;
        foreach (array_keys($keys) as $i) {
            $order[$i] = $position++;
        }

        return ['rot' => $rot, 'dx' => $dx, 'dy' => $dy, 'z' => $order];
    }

    /**
     * @param array{rot: list<float>, dx: list<float>, dy: list<float>, z: array<int, int>} $random
     * @param list<array{cols: int, k: float, o: float}>                              $grids
     */
    private function attempt(int $count, array $random, float $tilt, float $overlap, float $ratio, array $grids): ScatterPlan
    {
        $offset = min(self::MAX_OFFSET, self::OFFSET_PER_OVERLAP * $overlap);
        $z = $random['z'];
        $prints = $this->prints($count, $random, $tilt, $offset, $z);

        $geometries = [];
        $neighbours = [];
        $share = [];
        foreach ($grids as $g => $grid) {
            $geometries[$g] = $this->geometry($prints, $overlap, $ratio, $grid);
            for ($i = 0; $i < $count; ++$i) {
                $neighbours[$g][$i] = $this->neighbours($count, $grid['cols'], $i);
            }
            for ($i = 0; $i < $count; ++$i) {
                $share[$g][$i] = $this->share($geometries[$g], $z, $i, $neighbours[$g][$i]);
            }
        }

        for ($step = 0; $step < 2 * $count + 4; ++$step) {
            $worst = -1;
            $lowest = self::TARGET_VISIBILITY;
            for ($i = 0; $i < $count; ++$i) {
                $low = 1.0;
                foreach (array_keys($grids) as $g) {
                    $low = min($low, $share[$g][$i]);
                }
                if ($low < $lowest) {
                    $lowest = $low;
                    $worst = $i;
                }
            }
            if ($worst < 0) {
                break;
            }
            // Lift the print to the top; every print above it moves down by one (their relative order is kept).
            $old = $z[$worst];
            foreach ($z as $i => $value) {
                if ($value > $old) {
                    $z[$i] = $value - 1;
                }
            }
            $z[$worst] = $count;
            foreach (array_keys($grids) as $g) {
                foreach ([$worst, ...$neighbours[$g][$worst]] as $i) {
                    $share[$g][$i] = $this->share($geometries[$g], $z, $i, $neighbours[$g][$i]);
                }
            }
        }

        $min = 1.0;
        foreach (array_keys($grids) as $g) {
            $min = min($min, min($share[$g]));
        }
        $prints = $this->prints($count, $random, $tilt, $offset, $z);
        $min = min($min, $this->verified($prints, $overlap, $ratio, $grids));
        [$bleedX, $bleedY] = $this->bleed($prints, $overlap, $ratio);

        return new ScatterPlan($prints, $overlap, $bleedX, $bleedY, $min);
    }

    /**
     * Smallest visible share on the touch variants of the repaired grids (tilt and offsets times 0.6, overlap halved).
     *
     * @param list<ScatterPrint>                         $prints
     * @param list<array{cols: int, k: float, o: float}> $grids
     */
    private function verified(array $prints, float $overlap, float $ratio, array $grids): float
    {
        $count = count($prints);
        $z = array_map(static fn (ScatterPrint $p): int => $p->z, $prints);
        $min = 1.0;
        foreach ($grids as $grid) {
            $touch = ['cols' => $grid['cols'], 'k' => $grid['k'] === 1.0 ? self::TOUCH_FACTOR : $grid['k'], 'o' => self::TOUCH_OVERLAP_FACTOR];
            $geometry = $this->geometry($prints, $overlap, $ratio, $touch);
            for ($i = 0; $i < $count; ++$i) {
                $min = min($min, $this->share($geometry, $z, $i, $this->neighbours($count, $touch['cols'], $i)));
            }
        }

        return $min;
    }

    /**
     * @param array{rot: list<float>, dx: list<float>, dy: list<float>, z: array<int, int>} $random
     * @param array<int, int>                                                        $z
     *
     * @return list<ScatterPrint>
     */
    private function prints(int $count, array $random, float $tilt, float $offset, array $z): array
    {
        $prints = [];
        for ($i = 0; $i < $count; ++$i) {
            $prints[] = new ScatterPrint(
                round($tilt * $random['rot'][$i], 2),
                round($offset * $random['dx'][$i], 2),
                round($offset * $random['dy'][$i], 2),
                $z[$i],
            );
        }

        return $prints;
    }

    /**
     * Centre, half extents and rotation of every print in a grid, in cell widths (cell height = 1 / ratio).
     *
     * @param list<ScatterPrint>                   $prints
     * @param array{cols: int, k: float, o: float} $grid
     *
     * @return list<array{cx: float, cy: float, hw: float, hh: float, cos: float, sin: float}>
     */
    private function geometry(array $prints, float $overlap, float $ratio, array $grid): array
    {
        $cellHeight = 1.0 / $ratio;
        $scale = 1.0 + $overlap * $grid['o'] / 100.0;
        $geometry = [];
        foreach ($prints as $index => $print) {
            $angle = deg2rad($print->rotation * $grid['k']);
            $geometry[] = [
                'cx' => ($index % $grid['cols']) + 0.5 + $print->dx * $grid['k'] / 100.0,
                'cy' => (intdiv($index, $grid['cols']) + 0.5) * $cellHeight + $print->dy * $grid['k'] / 100.0 * $cellHeight,
                'hw' => $scale / 2.0,
                'hh' => $scale * $cellHeight / 2.0,
                'cos' => cos($angle),
                'sin' => sin($angle),
            ];
        }

        return $geometry;
    }

    /**
     * Indices of the (up to eight) grid neighbours of a print.
     *
     * @return list<int>
     */
    private function neighbours(int $count, int $columns, int $index): array
    {
        $row = intdiv($index, $columns);
        $col = $index % $columns;
        $result = [];
        for ($r = $row - 1; $r <= $row + 1; ++$r) {
            for ($c = $col - 1; $c <= $col + 1; ++$c) {
                $other = $r * $columns + $c;
                if ($r >= 0 && $c >= 0 && $c < $columns && $other < $count && $other !== $index) {
                    $result[] = $other;
                }
            }
        }

        return $result;
    }

    /**
     * Share of the sample points of print $index that no print with a higher z covers.
     *
     * @param list<array{cx: float, cy: float, hw: float, hh: float, cos: float, sin: float}> $geometry
     * @param array<int, int>                                                                $z
     * @param list<int>                                                                       $neighbours
     */
    private function share(array $geometry, array $z, int $index, array $neighbours): float
    {
        $above = [];
        foreach ($neighbours as $other) {
            if ($z[$other] > $z[$index]) {
                $above[] = $geometry[$other];
            }
        }
        if ($above === []) {
            return 1.0;
        }
        $me = $geometry[$index];
        $visible = 0;
        foreach ($this->samples as [$u, $v]) {
            $lx = $u * 2.0 * $me['hw'];
            $ly = $v * 2.0 * $me['hh'];
            $x = $me['cx'] + $lx * $me['cos'] - $ly * $me['sin'];
            $y = $me['cy'] + $lx * $me['sin'] + $ly * $me['cos'];
            $hidden = false;
            foreach ($above as $o) {
                $px = $x - $o['cx'];
                $py = $y - $o['cy'];
                if (abs($px * $o['cos'] + $py * $o['sin']) <= $o['hw'] && abs(-$px * $o['sin'] + $py * $o['cos']) <= $o['hh']) {
                    $hidden = true;
                    break;
                }
            }
            if (!$hidden) {
                ++$visible;
            }
        }

        return $visible / count($this->samples);
    }

    /**
     * How far the prints reach beyond their cells at full strength, in cell widths (rounded up to three decimals).
     *
     * @param list<ScatterPrint> $prints
     *
     * @return array{0: float, 1: float}
     */
    private function bleed(array $prints, float $overlap, float $ratio): array
    {
        $cellHeight = 1.0 / $ratio;
        $scale = 1.0 + $overlap / 100.0;
        $bleedX = 0.0;
        $bleedY = 0.0;
        foreach ($prints as $print) {
            $angle = deg2rad($print->rotation);
            $cos = abs(cos($angle));
            $sin = abs(sin($angle));
            $halfWidth = ($scale * $cos + $scale * $cellHeight * $sin) / 2.0;
            $halfHeight = ($scale * $sin + $scale * $cellHeight * $cos) / 2.0;
            $bleedX = max($bleedX, $halfWidth + abs($print->dx) / 100.0 - 0.5);
            $bleedY = max($bleedY, $halfHeight + abs($print->dy) / 100.0 * $cellHeight - $cellHeight / 2.0);
        }

        return [ceil(max(0.0, $bleedX) * 1000.0) / 1000.0, ceil(max(0.0, $bleedY) * 1000.0) / 1000.0];
    }
}
