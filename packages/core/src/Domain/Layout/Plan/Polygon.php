<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout\Plan;

/**
 * A simple polygon in percent of a plan container (0..100 on both axes), vertices rounded to three decimals.
 * The planner only produces convex polygons; the helpers assume convexity where they say so.
 */
final class Polygon
{
    public const PRECISION = 3;

    /** @var list<array{0: float, 1: float}> */
    public readonly array $points;

    /**
     * @param list<array{0: float|int, 1: float|int}> $points Vertices in order (either winding).
     */
    public function __construct(array $points)
    {
        $clean = [];
        foreach ($points as $point) {
            $x = (float) $point[0];
            $y = (float) $point[1];
            if (!is_finite($x) || !is_finite($y)) {
                throw new \InvalidArgumentException('A polygon needs finite coordinates.');
            }
            $x = round($x, self::PRECISION);
            $y = round($y, self::PRECISION);
            $last = $clean === [] ? null : $clean[count($clean) - 1];
            if ($last === null || $last[0] !== $x || $last[1] !== $y) {
                $clean[] = [$x, $y];
            }
        }
        if (count($clean) > 1 && $clean[0] === $clean[count($clean) - 1]) {
            array_pop($clean);
        }
        $this->points = $clean;
    }

    public function count(): int
    {
        return count($this->points);
    }

    /** Unsigned area in percent points squared (a full container is 10000). */
    public function area(): float
    {
        return abs($this->signedArea());
    }

    /**
     * Area centroid (the vertex mean for a degenerate polygon, the origin for an empty one).
     *
     * @return array{0: float, 1: float}
     */
    public function centroid(): array
    {
        $n = count($this->points);
        $area = 0.0;
        $cx = 0.0;
        $cy = 0.0;
        for ($i = 0; $i < $n; ++$i) {
            $a = $this->points[$i];
            $b = $this->points[($i + 1) % $n];
            $cross = $a[0] * $b[1] - $b[0] * $a[1];
            $area += $cross;
            $cx += ($a[0] + $b[0]) * $cross;
            $cy += ($a[1] + $b[1]) * $cross;
        }
        if (abs($area) < 1e-12) {
            return $n === 0
                ? [0.0, 0.0]
                : [array_sum(array_column($this->points, 0)) / $n, array_sum(array_column($this->points, 1)) / $n];
        }

        return [$cx / (3 * $area), $cy / (3 * $area)];
    }

    /**
     * Bounding box in percent of the container.
     *
     * @return array{x: float, y: float, w: float, h: float}
     */
    public function boundingBox(): array
    {
        if ($this->points === []) {
            return ['x' => 0.0, 'y' => 0.0, 'w' => 0.0, 'h' => 0.0];
        }
        $xs = array_column($this->points, 0);
        $ys = array_column($this->points, 1);
        $x = min($xs);
        $y = min($ys);

        return ['x' => $x, 'y' => $y, 'w' => max($xs) - $x, 'h' => max($ys) - $y];
    }

    /**
     * The vertices in percent of the bounding box (what `clip-path: polygon()` of a tile sized to the box needs).
     * Always inside 0..100; a degenerate box maps every vertex to 0.
     *
     * @return list<array{0: float, 1: float}>
     */
    public function relativePoints(): array
    {
        $box = $this->boundingBox();
        $out = [];
        foreach ($this->points as $point) {
            $out[] = [
                $box['w'] > 0.0 ? round(max(0.0, min(100.0, ($point[0] - $box['x']) / $box['w'] * 100)), self::PRECISION) : 0.0,
                $box['h'] > 0.0 ? round(max(0.0, min(100.0, ($point[1] - $box['y']) / $box['h'] * 100)), self::PRECISION) : 0.0,
            ];
        }

        return $out;
    }

    /** True for a polygon whose turns all go the same way (collinear vertices are tolerated). */
    public function isConvex(float $tolerance = 1e-6): bool
    {
        $n = count($this->points);
        if ($n < 3) {
            return false;
        }
        $sign = 0;
        for ($i = 0; $i < $n; ++$i) {
            $a = $this->points[$i];
            $b = $this->points[($i + 1) % $n];
            $c = $this->points[($i + 2) % $n];
            $cross = ($b[0] - $a[0]) * ($c[1] - $b[1]) - ($b[1] - $a[1]) * ($c[0] - $b[0]);
            if (abs($cross) <= $tolerance) {
                continue;
            }
            $current = $cross > 0 ? 1 : -1;
            if ($sign !== 0 && $sign !== $current) {
                return false;
            }
            $sign = $current;
        }

        return $sign !== 0;
    }

    /**
     * Moves every edge of a CONVEX polygon inwards by `$distance` pixels (a mitred offset: the intersection of the
     * shifted half-planes). The polygon lives in percent of a container that is `$pxPerPercentX` by `$pxPerPercentY`
     * pixels per percent, so the offset is a real pixel distance on both axes. Edges lying on the container border
     * stay where they are, so the shards stay flush with the container edges. Returns null when nothing is left.
     */
    public function inset(float $distance, float $pxPerPercentX, float $pxPerPercentY): ?self
    {
        if ($this->count() < 3 || $pxPerPercentX <= 0.0 || $pxPerPercentY <= 0.0) {
            return null;
        }
        if ($distance <= 0.0) {
            return $this;
        }
        $orientation = $this->signedArea() >= 0 ? 1.0 : -1.0;
        $n = count($this->points);
        $result = [];
        foreach ($this->points as $p) {
            $result[] = [$p[0] * $pxPerPercentX, $p[1] * $pxPerPercentY];
        }
        for ($i = 0; $i < $n && $result !== []; ++$i) {
            $a = $this->points[$i];
            $b = $this->points[($i + 1) % $n];
            if (self::onBorder($a, $b)) {
                continue;
            }
            $dx = ($b[0] - $a[0]) * $pxPerPercentX;
            $dy = ($b[1] - $a[1]) * $pxPerPercentY;
            $length = hypot($dx, $dy);
            if ($length < 1e-9) {
                continue;
            }
            $nx = -$dy / $length * $orientation;
            $ny = $dx / $length * $orientation;
            $sx = $a[0] * $pxPerPercentX + $nx * $distance;
            $sy = $a[1] * $pxPerPercentY + $ny * $distance;
            $result = self::clipHalfPlane($result, $nx, $ny, $nx * $sx + $ny * $sy);
        }
        if (count($result) < 3) {
            return null;
        }
        $points = [];
        foreach ($result as $p) {
            $points[] = [$p[0] / $pxPerPercentX, $p[1] / $pxPerPercentY];
        }

        return new self($points);
    }

    private function signedArea(): float
    {
        $sum = 0.0;
        $n = count($this->points);
        for ($i = 0; $i < $n; ++$i) {
            $a = $this->points[$i];
            $b = $this->points[($i + 1) % $n];
            $sum += $a[0] * $b[1] - $b[0] * $a[1];
        }

        return $sum / 2;
    }

    /**
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $b
     */
    private static function onBorder(array $a, array $b): bool
    {
        foreach ([0, 1] as $axis) {
            foreach ([0.0, 100.0] as $edge) {
                if (abs($a[$axis] - $edge) < 0.0006 && abs($b[$axis] - $edge) < 0.0006) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Sutherland-Hodgman against `nx * x + ny * y >= c`.
     *
     * @param list<array{0: float, 1: float}> $points
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function clipHalfPlane(array $points, float $nx, float $ny, float $c): array
    {
        $out = [];
        $n = count($points);
        for ($i = 0; $i < $n; ++$i) {
            $a = $points[$i];
            $b = $points[($i + 1) % $n];
            $da = $nx * $a[0] + $ny * $a[1] - $c;
            $db = $nx * $b[0] + $ny * $b[1] - $c;
            if ($da >= 0) {
                $out[] = $a;
            }
            if (($da >= 0) !== ($db >= 0)) {
                $t = $da / ($da - $db);
                $out[] = [$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t];
            }
        }

        return $out;
    }
}
