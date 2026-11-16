<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout\Plan;

/**
 * Order-preserving treemap ("strip treemap with stacked pairs"): the rectangles tile the container exactly, the area
 * of a tile follows the emphasis weight of its picture, and the reading order (rows top to bottom, cells left to right,
 * a stacked pair top to bottom) is exactly the input order. Pure PHP, no WordPress.
 *
 * Algorithm. The sequence is cut into full-width rows of at most {@see MAX_ROW} pictures. A row holds "cells": one
 * picture, or two consecutive pictures stacked on top of each other (a big picture next to a stacked pair is the
 * typical mosaic motif). With the weights fixed, every geometry follows from the cut: a row is as high as its weight
 * share, a cell as wide as its share of the row, stacked pictures split the cell height by weight. The cuts are chosen
 * by dynamic programming (inner programme: cells of a row; outer programme: rows of the sequence) minimising the
 * weighted squared logarithmic deviation of every tile from a square, plus a smaller term for the crop of the picture
 * (tile ratio against the picture ratio) and a steep penalty outside {@see MIN_TILE_ASPECT}..{@see MAX_TILE_ASPECT}.
 * Complexity O(n * MAX_ROW^2), i.e. linear. The seed only multiplies the cost of every candidate row by 1..1.1
 * (a deterministic hash), so it breaks near-ties and varies the arrangement between galleries without ever
 * trading away quality noticeably.
 */
final class TreemapPlanner
{
    /** Area weight per emphasis level: 1 : 1.8 : 3. */
    public const EMPHASIS_WEIGHTS = [1 => 1.0, 2 => 1.8, 3 => 3.0];

    /** Pictures per row at most (bounds the work and keeps tiles from becoming slivers). */
    public const MAX_ROW = 10;

    public const MIN_TILE_ASPECT = 0.4;
    public const MAX_TILE_ASPECT = 2.5;

    /** The three container variants: narrow 4:5, medium 1:1, wide 16:10 (width divided by height). */
    public const DEFAULT_ASPECTS = ['n' => 0.8, 'm' => 1.0, 'w' => 1.6];

    private const MIN_CONTAINER_ASPECT = 0.2;
    private const MAX_CONTAINER_ASPECT = 5.0;
    private const CROP_WEIGHT = 0.35;
    private const BOUND_PENALTY = 120.0;
    private const SEED_VARIATION = 0.1;

    /**
     * @param list<PlanItem> $items       Pictures of the page in display order.
     * @param int            $seed        Deterministic seed, see {@see PlanSeed}.
     * @param float          $aspectRatio Container width divided by height (clamped to 1:5..5:1).
     */
    public function plan(array $items, int $seed, float $aspectRatio): LayoutPlan
    {
        $aspect = is_finite($aspectRatio)
            ? max(self::MIN_CONTAINER_ASPECT, min(self::MAX_CONTAINER_ASPECT, $aspectRatio))
            : 1.0;
        $count = count($items);
        if ($count === 0) {
            return new LayoutPlan($aspect, []);
        }
        if ($count === 1) {
            return new LayoutPlan($aspect, [new Shape(0.0, 0.0, 100.0, 100.0)]);
        }

        $weights = [];
        $prefix = [0.0];
        foreach ($items as $index => $item) {
            $weights[] = self::EMPHASIS_WEIGHTS[$item->emphasis];
            $prefix[] = $prefix[$index] + $weights[$index];
        }
        $rows = $this->cutRows($items, $weights, $prefix, $aspect, $seed);

        return new LayoutPlan($aspect, $this->shapes($rows, $weights, $prefix, $count));
    }

    /**
     * One plan per container variant.
     *
     * @param list<PlanItem>        $items
     * @param array<string, float>  $aspects Container aspect ratio per variant key.
     *
     * @return array<string, LayoutPlan>
     */
    public function variants(array $items, int $seed, array $aspects = self::DEFAULT_ASPECTS): array
    {
        $plans = [];
        foreach ($aspects as $key => $aspect) {
            $plans[$key] = $this->plan($items, $seed, $aspect);
        }

        return $plans;
    }

    /**
     * Outer programme: cheapest cut of the sequence into rows.
     *
     * @param list<PlanItem> $items
     * @param list<float>    $weights
     * @param list<float>    $prefix
     *
     * @return list<array{0: int, 1: int, 2: list<int>}> Row start, row end (exclusive) and cell sizes (1 or 2).
     */
    private function cutRows(array $items, array $weights, array $prefix, float $aspect, int $seed): array
    {
        $count = count($items);
        $best = array_fill(0, $count + 1, INF);
        $best[0] = 0.0;
        $choice = array_fill(0, $count + 1, [0, []]);
        for ($end = 1; $end <= $count; ++$end) {
            for ($length = 1; $length <= min(self::MAX_ROW, $end); ++$length) {
                $start = $end - $length;
                [$cost, $cells] = $this->bestCells($items, $weights, $start, $end, $prefix[$count], $aspect);
                $noise = crc32(sprintf('%d:%d:%d', $seed, $start, $end)) / 4294967296;
                $total = $best[$start] + $cost * (1.0 + self::SEED_VARIATION * $noise);
                if ($total < $best[$end]) {
                    $best[$end] = $total;
                    $choice[$end] = [$start, $cells];
                }
            }
        }
        $rows = [];
        for ($end = $count; $end > 0; $end = $choice[$end][0]) {
            array_unshift($rows, [$choice[$end][0], $end, $choice[$end][1]]);
        }

        return $rows;
    }

    /**
     * Inner programme: cheapest split of one row into single and stacked-pair cells.
     *
     * @param list<PlanItem> $items
     * @param list<float>    $weights
     *
     * @return array{0: float, 1: list<int>}
     */
    private function bestCells(array $items, array $weights, int $start, int $end, float $total, float $aspect): array
    {
        $rowWeight = 0.0;
        for ($k = $start; $k < $end; ++$k) {
            $rowWeight += $weights[$k];
        }
        $rowHeight = $rowWeight / $total;
        $size = $end - $start;
        $cost = [0 => 0.0];
        $cells = [0 => []];
        for ($m = 1; $m <= $size; ++$m) {
            $k = $start + $m - 1;
            $cost[$m] = $cost[$m - 1] + $this->tileCost($items[$k], $weights[$k], $aspect * $weights[$k] / $rowWeight / $rowHeight);
            $cells[$m] = [...$cells[$m - 1], 1];
            // A pair that is the whole row is just two rows; it is never needed.
            if ($m >= 2 && $size > 2) {
                $a = $k - 1;
                $cellWeight = $weights[$a] + $weights[$k];
                $width = $cellWeight / $rowWeight;
                $pair = $cost[$m - 2]
                    + $this->tileCost($items[$a], $weights[$a], $aspect * $width / ($rowHeight * $weights[$a] / $cellWeight))
                    + $this->tileCost($items[$k], $weights[$k], $aspect * $width / ($rowHeight * $weights[$k] / $cellWeight));
                if ($pair < $cost[$m] - 1e-12) {
                    $cost[$m] = $pair;
                    $cells[$m] = [...$cells[$m - 2], 2];
                }
            }
        }

        return [$cost[$size], $cells[$size]];
    }

    /** Squared log deviation from a square, a crop term against the picture and a steep bound penalty. */
    private function tileCost(PlanItem $item, float $weight, float $tileAspect): float
    {
        $square = log($tileAspect);
        $crop = log($tileAspect / $item->aspectRatio);
        $cost = $square * $square + self::CROP_WEIGHT * $crop * $crop;
        if ($tileAspect > self::MAX_TILE_ASPECT) {
            $excess = log($tileAspect / self::MAX_TILE_ASPECT);
            $cost += self::BOUND_PENALTY * $excess * $excess;
        } elseif ($tileAspect < self::MIN_TILE_ASPECT) {
            $excess = log(self::MIN_TILE_ASPECT / $tileAspect);
            $cost += self::BOUND_PENALTY * $excess * $excess;
        }

        return $weight * $cost;
    }

    /**
     * @param list<array{0: int, 1: int, 2: list<int>}> $rows
     * @param list<float>                               $weights
     * @param list<float>                               $prefix
     *
     * @return list<Shape>
     */
    private function shapes(array $rows, array $weights, array $prefix, int $count): array
    {
        $total = $prefix[$count];
        $edges = [];
        foreach ($rows as [$start, $end, $cellSizes]) {
            $top = round($prefix[$start] / $total * 100.0, Shape::PRECISION);
            $bottom = $end === $count ? 100.0 : round($prefix[$end] / $total * 100.0, Shape::PRECISION);
            $rowWeight = 0.0;
            for ($k = $start; $k < $end; ++$k) {
                $rowWeight += $weights[$k];
            }
            $index = $start;
            $consumed = 0.0;
            $left = 0.0;
            foreach ($cellSizes as $size) {
                $cellWeight = 0.0;
                for ($k = $index; $k < $index + $size; ++$k) {
                    $cellWeight += $weights[$k];
                }
                $consumed += $cellWeight;
                $isLast = $index + $size === $end;
                $right = $isLast ? 100.0 : round($consumed / $rowWeight * 100.0, Shape::PRECISION);
                if ($size === 1) {
                    $edges[$index] = [$left, $top, $right, $bottom];
                } else {
                    // Interpolate on the integer precision grid: adding a decimal top before rounding
                    // can put an exact half below the midpoint on PHP 8.4 (e.g. 72.7275).
                    $scale = 10 ** Shape::PRECISION;
                    $topUnits = (int) round($top * $scale);
                    $heightUnits = (int) round(($bottom - $top) * $scale);
                    $weightUnits = (int) round($weights[$index] * 10);
                    $cellUnits = (int) round($cellWeight * 10);
                    $middle = round(($topUnits * $cellUnits + $heightUnits * $weightUnits) / $cellUnits) / $scale;
                    $edges[$index] = [$left, $top, $right, $middle];
                    $edges[$index + 1] = [$left, $middle, $right, $bottom];
                }
                $left = $right;
                $index += $size;
            }
        }
        ksort($edges);

        return array_map(
            static fn (array $edge): Shape => new Shape($edge[0], $edge[1], $edge[2] - $edge[0], $edge[3] - $edge[1]),
            array_values($edges)
        );
    }
}
