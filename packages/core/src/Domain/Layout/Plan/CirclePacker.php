<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout\Plan;

/**
 * Circle packing into a container of fixed aspect ratio (pure PHP, no WordPress). Circles are returned as the bounding
 * boxes ({@see Shape}, percent of the container) of the circles, so the view can draw them with `border-radius: 50%`;
 * the boxes are square in pixels because width and height percentages follow the container ratio.
 *
 * Radii. The area of a circle follows the emphasis weight like the treemap (1 : 1.8 : 3), so the radius grows with its
 * square root.
 *
 * Algorithm: greedy tangent placement with a bisected scale. For a given scale `s` (radius = s * relative radius) the
 * circles are placed in input order; every circle goes to the best position among all points where it touches two
 * circles that are already placed (or a circle and a wall, or two walls) and overlaps nothing; "best" means the
 * topmost position, with the leftmost as the second criterion (score = top edge + 0.15 * left edge). The scale is the
 * largest one (bisection from 45 % of the density 1 bound, 11 steps, never above that bound) for which every circle finds a place. The packing
 * is finally centred in the container. Reading order. Because every circle takes the topmost free position in input
 * order, the packing reads roughly in rows from the top: a circle never lies far above one that came before it. The
 * rule is not strict (a small circle can slip into a gap above an earlier larger one), exact order is not promised.
 * Complexity per scale O(n^3) worst case, in practice far less (candidates beaten by the best score are not checked);
 * 60 circles take well under a second.
 *
 * Guarantees: no overlaps (the radii are shrunk by 0.01 % so the rounding of the shapes cannot create one), every
 * circle lies inside the container, same input gives the same output, never throws for finite input, no NaN.
 */
final class CirclePacker
{
    /** Area weight per emphasis level: 1 : 1.8 : 3 (the same as the treemap). */
    public const EMPHASIS_WEIGHTS = TreemapPlanner::EMPHASIS_WEIGHTS;

    /** The three container variants: narrow 4:5, medium 1:1, wide 16:10 (width divided by height). */
    public const DEFAULT_ASPECTS = TreemapPlanner::DEFAULT_ASPECTS;

    private const MIN_CONTAINER_ASPECT = 0.2;
    private const MAX_CONTAINER_ASPECT = 5.0;
    private const LEFT_WEIGHT = 0.15;
    private const START_FRACTION = 0.45;
    private const BISECTIONS = 11;
    private const FULL_BISECTIONS = 24;
    private const SAFETY = 0.9999;
    private const EPSILON = 1e-9;

    /**
     * @param list<PlanItem> $items       Circles in display order (the emphasis gives the area).
     * @param float          $aspectRatio Container width divided by height (clamped to 1:5..5:1).
     */
    public function plan(array $items, float $aspectRatio): LayoutPlan
    {
        $aspect = is_finite($aspectRatio)
            ? max(self::MIN_CONTAINER_ASPECT, min(self::MAX_CONTAINER_ASPECT, $aspectRatio))
            : 1.0;
        $count = count($items);
        if ($count === 0) {
            return new LayoutPlan($aspect, []);
        }

        $radii = [];
        $areaSum = 0.0;
        foreach ($items as $item) {
            $weight = self::EMPHASIS_WEIGHTS[$item->emphasis];
            $radii[] = sqrt($weight);
            $areaSum += $weight;
        }
        $upper = min(sqrt($aspect / (M_PI * $areaSum)), min($aspect, 1.0) / (2.0 * max($radii)));
        $lower = 0.0;
        $best = null;
        $placed = $this->place($radii, $upper, $aspect);
        if ($placed !== null) {
            $best = [$upper, $placed];
        } else {
            $high = $upper;
            // Every real gallery fits at 45 % of the density 1 scale; start there and fall back to the full range.
            $start = $this->place($radii, $upper * self::START_FRACTION, $aspect);
            if ($start !== null) {
                $lower = $upper * self::START_FRACTION;
                $best = [$lower, $start];
            }
            for ($step = 0; $step < ($start !== null ? self::BISECTIONS : self::FULL_BISECTIONS); ++$step) {
                $middle = ($lower + $high) / 2.0;
                $placed = $this->place($radii, $middle, $aspect);
                if ($placed !== null) {
                    $lower = $middle;
                    $best = [$middle, $placed];
                } else {
                    $high = $middle;
                }
            }
        }
        if ($best === null) {
            // Cannot happen for finite input (a tiny scale always fits); keep a defined result anyway.
            return new LayoutPlan($aspect, $this->fallback($radii, $aspect));
        }

        return new LayoutPlan($aspect, $this->shapes($best[1], $best[0] * self::SAFETY, $radii, $aspect));
    }

    /**
     * One plan per container variant.
     *
     * @param list<PlanItem>        $items
     * @param array<string, float>  $aspects Container aspect ratio per variant key.
     *
     * @return array<string, LayoutPlan>
     */
    public function variants(array $items, array $aspects = self::DEFAULT_ASPECTS): array
    {
        $plans = [];
        foreach ($aspects as $key => $aspect) {
            $plans[$key] = $this->plan($items, $aspect);
        }

        return $plans;
    }

    /**
     * Share of the container that the circles cover (sum of the circle areas divided by the container area), 0..1.
     */
    public static function density(LayoutPlan $plan): float
    {
        $area = 0.0;
        foreach ($plan->shapes as $shape) {
            $radius = $shape->w * $plan->aspectRatio / 200.0;
            $area += M_PI * $radius * $radius;
        }

        return $area / $plan->aspectRatio;
    }

    /**
     * Greedy placement at one scale (container height 1, width $aspect).
     *
     * @param list<float> $radii Relative radii.
     *
     * @return list<array{0: float, 1: float}>|null Centres or null when a circle found no place.
     */
    private function place(array $radii, float $scale, float $aspect): ?array
    {
        $x = [];
        $y = [];
        $r = [];
        foreach ($radii as $relative) {
            $rho = $scale * $relative;
            $found = $this->best($x, $y, $r, $rho, $aspect);
            if ($found === null) {
                return null;
            }
            $x[] = $found[0];
            $y[] = $found[1];
            $r[] = $rho;
        }
        $centres = [];
        foreach ($x as $i => $cx) {
            $centres[] = [$cx, $y[$i]];
        }

        return $centres;
    }

    /**
     * Best free position for a circle of radius $rho.
     *
     * @param list<float> $x
     * @param list<float> $y
     * @param list<float> $r
     *
     * @return array{0: float, 1: float}|null
     */
    private function best(array $x, array $y, array $r, float $rho, float $aspect): ?array
    {
        $count = count($x);
        $best = null;
        $bestScore = INF;
        $minX = $rho;
        $maxX = $aspect - $rho;
        $minY = $rho;
        $maxY = 1.0 - $rho;
        if ($minX > $maxX + self::EPSILON || $minY > $maxY + self::EPSILON) {
            return null;
        }
        $consider = function (float $cx, float $cy) use (&$best, &$bestScore, $x, $y, $r, $rho, $count, $minX, $maxX, $minY, $maxY): void {
            if ($cx < $minX - self::EPSILON || $cx > $maxX + self::EPSILON || $cy < $minY - self::EPSILON || $cy > $maxY + self::EPSILON) {
                return;
            }
            $score = ($cy - $rho) + self::LEFT_WEIGHT * ($cx - $rho);
            if ($score >= $bestScore) {
                return;
            }
            for ($j = 0; $j < $count; ++$j) {
                $dx = $cx - $x[$j];
                $dy = $cy - $y[$j];
                $need = $rho + $r[$j] - 1e-7;
                if ($dx * $dx + $dy * $dy < $need * $need) {
                    return;
                }
            }
            $best = [min($maxX, max($minX, $cx)), min($maxY, max($minY, $cy))];
            $bestScore = $score;
        };

        foreach ([[$minX, $minY], [$maxX, $minY], [$minX, $maxY], [$maxX, $maxY]] as [$cx, $cy]) {
            $consider($cx, $cy);
        }
        for ($j = 0; $j < $count; ++$j) {
            $reach = $rho + $r[$j];
            // Touching a wall and circle j.
            foreach ([$minX, $maxX] as $wall) {
                $d = $reach * $reach - ($wall - $x[$j]) * ($wall - $x[$j]);
                if ($d >= 0.0) {
                    $root = sqrt($d);
                    $consider($wall, $y[$j] - $root);
                    $consider($wall, $y[$j] + $root);
                }
            }
            foreach ([$minY, $maxY] as $wall) {
                $d = $reach * $reach - ($wall - $y[$j]) * ($wall - $y[$j]);
                if ($d >= 0.0) {
                    $root = sqrt($d);
                    $consider($x[$j] - $root, $wall);
                    $consider($x[$j] + $root, $wall);
                }
            }
            // Touching circles j and k.
            for ($k = $j + 1; $k < $count; ++$k) {
                $dx = $x[$k] - $x[$j];
                $dy = $y[$k] - $y[$j];
                $squared = $dx * $dx + $dy * $dy;
                $reachK = $rho + $r[$k];
                if ($squared < self::EPSILON * self::EPSILON || $squared > ($reach + $reachK) * ($reach + $reachK)) {
                    continue;
                }
                $dist = sqrt($squared);
                $along = ($reach * $reach - $reachK * $reachK + $dist * $dist) / (2.0 * $dist);
                $height = $reach * $reach - $along * $along;
                if ($height < 0.0) {
                    continue;
                }
                $height = sqrt($height);
                $mx = $x[$j] + $along * $dx / $dist;
                $my = $y[$j] + $along * $dy / $dist;
                $consider($mx + $height * $dy / $dist, $my - $height * $dx / $dist);
                $consider($mx - $height * $dy / $dist, $my + $height * $dx / $dist);
            }
        }

        return $best;
    }

    /**
     * Centre the packing in the container and turn the circles into bounding box shapes.
     *
     * @param list<array{0: float, 1: float}> $centres
     * @param list<float>                     $radii
     *
     * @return list<Shape>
     */
    private function shapes(array $centres, float $scale, array $radii, float $aspect): array
    {
        $left = INF;
        $right = -INF;
        $top = INF;
        $bottom = -INF;
        foreach ($centres as $i => [$cx, $cy]) {
            $rho = $scale * $radii[$i];
            $left = min($left, $cx - $rho);
            $right = max($right, $cx + $rho);
            $top = min($top, $cy - $rho);
            $bottom = max($bottom, $cy + $rho);
        }
        $shiftX = ($aspect - ($right - $left)) / 2.0 - $left;
        $shiftY = (1.0 - ($bottom - $top)) / 2.0 - $top;
        $shapes = [];
        foreach ($centres as $i => [$cx, $cy]) {
            $rho = $scale * $radii[$i];
            $shapes[] = new Shape(
                ($cx + $shiftX - $rho) / $aspect * 100.0,
                ($cy + $shiftY - $rho) * 100.0,
                2.0 * $rho / $aspect * 100.0,
                2.0 * $rho * 100.0,
            );
        }

        return $shapes;
    }

    /**
     * Defined result for the (practically impossible) case that no scale fits: a grid of equal small circles.
     *
     * @param list<float> $radii
     *
     * @return list<Shape>
     */
    private function fallback(array $radii, float $aspect): array
    {
        $count = count($radii);
        $columns = max(1, (int) ceil(sqrt($count * $aspect)));
        $rows = (int) ceil($count / $columns);
        $size = min($aspect / $columns, 1.0 / $rows) * self::SAFETY;
        $shapes = [];
        for ($i = 0; $i < $count; ++$i) {
            $shapes[] = new Shape(
                ($i % $columns) * $size / $aspect * 100.0,
                intdiv($i, $columns) * $size * 100.0,
                $size / $aspect * 100.0,
                $size * 100.0,
            );
        }

        return $shapes;
    }
}
