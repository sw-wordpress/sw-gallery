<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout\Plan;

/**
 * Shattered glass: a weighted Voronoi tessellation of the container, one convex cell per picture. Pure PHP, no
 * WordPress.
 *
 * Algorithm.
 *  1. Sites: seeded best-candidate sampling (Mitchell, blue noise) of one point per picture in a container of width
 *     A (the aspect ratio) and height 1, so distances are real distances.
 *  2. Lloyd relaxation ({@see FREE_STEPS} steps) evens out the cells; the relaxed sites are then sorted in reading
 *     order (bands of about one cell height, left to right inside a band) and the pictures are bound to them in
 *     input order. DOM order = manual order stays; the shards only follow it approximately, see readingOrderRank().
 *  3. Weighted relaxation: the cells are the cells of a power diagram (a Voronoi diagram with an additive weight per
 *     site, still convex, still found by half-plane clipping). {@see WEIGHTED_STEPS} steps move the sites to the
 *     centroids and nudge the weights so that the cell areas approach the targets 1 : 1.8 : 3 (`emphasis`), then
 *     {@see FIT_STEPS} weight-only steps remove most of the remaining area error.
 *  4. Cells are clipped against the container rectangle (exact tiling), converted to percent, and every cell is
 *     moved inwards by half the grout (a pixel distance at a reference container width, see
 *     {@see Polygon::inset()}); container edges stay flush.
 * Complexity O(steps * n^2), irrelevant for the pages of at most 60 pictures. The same seed gives the same plan on
 * every PHP version (an own xorshift generator, no mt_rand).
 */
final class VoronoiPlanner
{
    public const FREE_STEPS = 1;
    public const WEIGHTED_STEPS = 2;
    public const FIT_STEPS = 16;

    private const MIN_ASPECT = 0.2;
    private const MAX_ASPECT = 5.0;
    private const CANDIDATES = 12;
    private const WEIGHT_GAIN = 0.8;
    private const MIN_INSET_SHARE = 0.25;

    /** Container variants: narrow 4:5, medium 1:1, wide 16:10, like the treemap mosaic. */
    public const DEFAULT_ASPECTS = TreemapPlanner::DEFAULT_ASPECTS;

    /** Container width in pixels used to turn the grout into percent, per variant (widths typical for the breakpoints). */
    public const DEFAULT_REFERENCE_WIDTHS = ['n' => 600.0, 'm' => 880.0, 'w' => 1200.0];

    /**
     * @param list<PlanItem> $items          Pictures of the page in display order.
     * @param int            $seed           Deterministic seed, see {@see PlanSeed}.
     * @param float          $aspectRatio    Container width divided by height (clamped to 1:5..5:1).
     * @param float          $groutPx        Gap between neighbouring shards in pixels (0 = none).
     * @param float          $referenceWidth Container width in pixels the grout is converted at.
     */
    public function plan(array $items, int $seed, float $aspectRatio, float $groutPx = 3.0, float $referenceWidth = 900.0): ShardPlan
    {
        $aspect = is_finite($aspectRatio) ? max(self::MIN_ASPECT, min(self::MAX_ASPECT, $aspectRatio)) : 1.0;
        $grout = is_finite($groutPx) ? max(0.0, min(48.0, $groutPx)) : 0.0;
        $width = is_finite($referenceWidth) && $referenceWidth > 0.0 ? $referenceWidth : 900.0;
        $count = count($items);
        if ($count === 0) {
            return new ShardPlan($aspect, []);
        }

        $cells = $count === 1
            ? [[[0.0, 0.0], [$aspect, 0.0], [$aspect, 1.0], [0.0, 1.0]]]
            : $this->cells($items, $seed, $aspect);

        $shards = [];
        foreach ($cells as $cell) {
            $percent = [];
            foreach ($cell as $point) {
                $percent[] = [$point[0] / $aspect * 100, $point[1] * 100];
            }
            $polygon = new Polygon($percent);
            $shards[] = new Shard($polygon, $this->inset($polygon, $grout / 2, $width / 100, $width / $aspect / 100));
        }

        return new ShardPlan($aspect, $shards);
    }

    /**
     * One plan per container variant, all computed from the same seed.
     *
     * @param list<PlanItem>       $items
     * @param array<string, float> $aspects        Container aspect ratio per variant key.
     * @param array<string, float> $referenceWidth Container width in pixels per variant key (grout conversion).
     *
     * @return array<string, ShardPlan>
     */
    public function variants(
        array $items,
        int $seed,
        array $aspects = self::DEFAULT_ASPECTS,
        float $groutPx = 3.0,
        array $referenceWidth = self::DEFAULT_REFERENCE_WIDTHS,
    ): array {
        $plans = [];
        foreach ($aspects as $key => $aspect) {
            $plans[$key] = $this->plan($items, $seed, $aspect, $groutPx, $referenceWidth[$key] ?? 900.0);
        }

        return $plans;
    }

    /**
     * Rank (0-based position in reading order) of every cell of a plan: bands of about one cell height from top to
     * bottom, left to right inside a band. Used by the planner to bind pictures to sites and by tests to measure how
     * far the final cells stray from the input order (rank[i] === i for a perfectly ordered plan).
     *
     * @param list<array{0: float, 1: float}> $centres Cell or site centres in plan space (width = aspect, height 1).
     *
     * @return list<int>
     */
    public static function readingOrderRank(array $centres, float $aspect): array
    {
        $count = count($centres);
        if ($count === 0) {
            return [];
        }
        $bands = max(1, (int) round(sqrt($count / max($aspect, 1e-6))));
        $order = range(0, $count - 1);
        usort($order, static function (int $a, int $b) use ($centres, $bands): int {
            $bandA = min($bands - 1, (int) floor($centres[$a][1] * $bands));
            $bandB = min($bands - 1, (int) floor($centres[$b][1] * $bands));

            return [$bandA, $centres[$a][0], $a] <=> [$bandB, $centres[$b][0], $b];
        });
        $rank = array_fill(0, $count, 0);
        foreach ($order as $position => $index) {
            $rank[$index] = $position;
        }

        return array_values($rank);
    }

    /**
     * @param list<PlanItem> $items
     *
     * @return list<list<array{0: float, 1: float}>> Cell polygons in plan space (width = aspect, height 1), input order.
     */
    private function cells(array $items, int $seed, float $aspect): array
    {
        $count = count($items);
        $sites = $this->sites($count, $seed, $aspect);
        $zero = array_fill(0, $count, 0.0);
        for ($step = 0; $step < self::FREE_STEPS; ++$step) {
            $sites = self::centroids(self::power($sites, $zero, $aspect), $sites);
        }

        // Bind the pictures (input order) to the sites in reading order.
        $rank = self::readingOrderRank($sites, $aspect);
        $bound = [];
        foreach ($sites as $index => $site) {
            $bound[$rank[$index]] = $site;
        }
        ksort($bound);
        $sites = array_values($bound);

        $weights = [];
        $total = 0.0;
        foreach ($items as $item) {
            $weight = TreemapPlanner::EMPHASIS_WEIGHTS[$item->emphasis];
            $weights[] = $weight;
            $total += $weight;
        }
        $targets = array_map(static fn (float $weight): float => $aspect * $weight / $total, $weights);
        $limit = 8.0 * $aspect / $count;

        $power = $zero;
        $cells = [];
        for ($step = 0; $step < self::WEIGHTED_STEPS + self::FIT_STEPS; ++$step) {
            $cells = self::power($sites, $power, $aspect);
            if ($step < self::WEIGHTED_STEPS) {
                $sites = self::centroids($cells, $sites);
            }
            $power = self::fitWeights($cells, $sites, $power, $targets, $limit, $aspect);
        }

        // Safety net: a cell that vanished or is far too small gets its weights halved until every cell is healthy.
        $cells = self::power($sites, $power, $aspect);
        for ($try = 0; $try < 6 && !self::healthy($cells, $targets); ++$try) {
            $power = array_map(static fn (float $value): float => $value / 2, $power);
            $cells = self::power($sites, $power, $aspect);
        }

        return $cells;
    }

    /**
     * One damped Newton-like step of the weights: the area of a cell changes by about `sum(edge length / (2 *
     * distance to the neighbour))` per unit of weight, so every weight moves by its area error divided by that
     * stiffness (a vanished cell, which has no edges, simply grows). Weights stay within +-`$limit`.
     *
     * @param list<list<array{0: float, 1: float}>> $cells
     * @param list<array{0: float, 1: float}>       $sites
     * @param list<float>                           $power
     * @param list<float>                           $targets
     *
     * @return list<float>
     */
    private static function fitWeights(array $cells, array $sites, array $power, array $targets, float $limit, float $aspect): array
    {
        $count = count($sites);
        $next = [];
        foreach ($cells as $i => $cell) {
            $n = count($cell);
            $stiffness = 0.0;
            for ($k = 0; $k < $n && $n >= 3; ++$k) {
                $p = $cell[$k];
                $q = $cell[($k + 1) % $n];
                $length = hypot($q[0] - $p[0], $q[1] - $p[1]);
                $mx = ($p[0] + $q[0]) / 2;
                $my = ($p[1] + $q[1]) / 2;
                $best = -1;
                $bestValue = INF;
                for ($j = 0; $j < $count; ++$j) {
                    if ($j === $i) {
                        continue;
                    }
                    $value = ($mx - $sites[$j][0]) ** 2 + ($my - $sites[$j][1]) ** 2 - $power[$j];
                    if ($value < $bestValue) {
                        $bestValue = $value;
                        $best = $j;
                    }
                }
                $onBorder = $mx < 1e-9 || $my < 1e-9 || $mx > $aspect - 1e-9 || $my > 1 - 1e-9;
                $distance = $best >= 0 ? hypot($sites[$best][0] - $sites[$i][0], $sites[$best][1] - $sites[$i][1]) : 0.0;
                if (!$onBorder && $distance > 1e-9) {
                    $stiffness += $length / (2 * $distance);
                }
            }
            $error = $targets[$i] - self::area($cell);
            $step = $stiffness > 1e-9 ? self::WEIGHT_GAIN * $error / $stiffness : 0.25 * $limit / 8;
            $next[] = max(-$limit, min($limit, $power[$i] + max(-$limit / 4, min($limit / 4, $step))));
        }

        return $next;
    }

    /**
     * @param list<list<array{0: float, 1: float}>> $cells
     * @param list<float>                           $targets
     */
    private static function healthy(array $cells, array $targets): bool
    {
        foreach ($cells as $index => $cell) {
            if (count($cell) < 3 || self::area($cell) < 0.3 * $targets[$index]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Seeded best-candidate sampling: every new point is the one of a few random candidates that is farthest from
     * all points so far (blue noise: even, but not a lattice).
     *
     * @return list<array{0: float, 1: float}>
     */
    private function sites(int $count, int $seed, float $aspect): array
    {
        $state = $seed & 0xFFFFFFFF;
        $state = $state === 0 ? 0x9E3779B9 : $state;
        $next = static function () use (&$state): float {
            $state ^= ($state << 13) & 0xFFFFFFFF;
            $state ^= $state >> 17;
            $state ^= ($state << 5) & 0xFFFFFFFF;

            return $state / 4294967296.0;
        };
        $sites = [];
        for ($i = 0; $i < $count; ++$i) {
            $best = null;
            $bestDistance = -1.0;
            for ($c = 0; $c < ($i === 0 ? 1 : self::CANDIDATES); ++$c) {
                $candidate = [$next() * $aspect, $next()];
                $distance = INF;
                foreach ($sites as $site) {
                    $distance = min($distance, ($site[0] - $candidate[0]) ** 2 + ($site[1] - $candidate[1]) ** 2);
                }
                if ($distance > $bestDistance) {
                    $bestDistance = $distance;
                    $best = $candidate;
                }
            }
            $sites[] = $best ?? [0.5 * $aspect, 0.5];
        }

        return $sites;
    }

    /**
     * Power diagram cells (plain Voronoi cells for zero weights) by half-plane clipping against the container.
     *
     * @param list<array{0: float, 1: float}> $sites
     * @param list<float>                     $power
     *
     * @return list<list<array{0: float, 1: float}>>
     */
    private static function power(array $sites, array $power, float $aspect): array
    {
        $cells = [];
        $count = count($sites);
        $spread = $power === [] ? 0.0 : max($power) - min($power);
        foreach ($sites as $i => $si) {
            $distances = [];
            for ($j = 0; $j < $count; ++$j) {
                if ($j !== $i) {
                    $distances[$j] = ($sites[$j][0] - $si[0]) ** 2 + ($sites[$j][1] - $si[1]) ** 2;
                }
            }
            asort($distances);
            $cell = [[0.0, 0.0], [$aspect, 0.0], [$aspect, 1.0], [0.0, 1.0]];
            $radius = hypot($aspect, 1.0);
            foreach ($distances as $j => $squared) {
                // The bisector of a site at distance d lies at (d^2 + w_i - w_j) / 2d from this site at the
                // nearest; once that exceeds the farthest corner of the cell no later site can cut it.
                $distance = sqrt($squared);
                if ($distance > 1e-12 && ($squared - $spread) / (2 * $distance) > $radius) {
                    break;
                }
                $sj = $sites[$j];
                $cell = self::clip(
                    $cell,
                    2 * ($sj[0] - $si[0]),
                    2 * ($sj[1] - $si[1]),
                    ($sj[0] ** 2 + $sj[1] ** 2 - $power[$j]) - ($si[0] ** 2 + $si[1] ** 2 - $power[$i])
                );
                if ($cell === []) {
                    break;
                }
                $radius = 0.0;
                foreach ($cell as $corner) {
                    $radius = max($radius, hypot($corner[0] - $si[0], $corner[1] - $si[1]));
                }
            }
            $cells[] = $cell;
        }

        return $cells;
    }

    /**
     * Keeps the part of a convex polygon with `a * x + b * y <= c`.
     *
     * @param list<array{0: float, 1: float}> $polygon
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function clip(array $polygon, float $a, float $b, float $c): array
    {
        $out = [];
        $n = count($polygon);
        for ($k = 0; $k < $n; ++$k) {
            $p = $polygon[$k];
            $q = $polygon[($k + 1) % $n];
            $dp = $a * $p[0] + $b * $p[1] - $c;
            $dq = $a * $q[0] + $b * $q[1] - $c;
            if ($dp <= 0) {
                $out[] = $p;
            }
            if (($dp <= 0) !== ($dq <= 0)) {
                $t = $dp / ($dp - $dq);
                $out[] = [$p[0] + ($q[0] - $p[0]) * $t, $p[1] + ($q[1] - $p[1]) * $t];
            }
        }

        return $out;
    }

    /** @param list<array{0: float, 1: float}> $cell */
    private static function area(array $cell): float
    {
        $sum = 0.0;
        $n = count($cell);
        for ($i = 0; $i < $n; ++$i) {
            $p = $cell[$i];
            $q = $cell[($i + 1) % $n];
            $sum += $p[0] * $q[1] - $q[0] * $p[1];
        }

        return abs($sum) / 2;
    }

    /**
     * New sites at the area centroids of the cells (an empty cell keeps its site).
     *
     * @param list<list<array{0: float, 1: float}>> $cells
     * @param list<array{0: float, 1: float}>       $sites
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function centroids(array $cells, array $sites): array
    {
        $out = [];
        foreach ($cells as $index => $cell) {
            $area = 0.0;
            $cx = 0.0;
            $cy = 0.0;
            $n = count($cell);
            for ($i = 0; $i < $n; ++$i) {
                $p = $cell[$i];
                $q = $cell[($i + 1) % $n];
                $cross = $p[0] * $q[1] - $q[0] * $p[1];
                $area += $cross;
                $cx += ($p[0] + $q[0]) * $cross;
                $cy += ($p[1] + $q[1]) * $cross;
            }
            $out[] = abs($area) < 1e-12 ? $sites[$index] : [$cx / (3 * $area), $cy / (3 * $area)];
        }

        return $out;
    }

    /**
     * The drawn polygon of a cell: moved inwards by `$distance` pixels; a cell that would shrink to less than a
     * quarter of its area gets a smaller distance, a collapsed one stays as it is (defensive, the weights keep
     * every cell large enough).
     */
    private function inset(Polygon $cell, float $distance, float $pxX, float $pxY): Polygon
    {
        for ($try = 0; $try < 4 && $distance > 0.0; ++$try) {
            $inset = $cell->inset($distance, $pxX, $pxY);
            if ($inset !== null && $inset->area() >= self::MIN_INSET_SHARE * $cell->area()) {
                return $inset;
            }
            $distance /= 2;
        }

        return $cell;
    }
}
