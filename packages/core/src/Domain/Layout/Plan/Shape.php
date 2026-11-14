<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout\Plan;

/**
 * One tile of a {@see LayoutPlan} in percent of the plan container (0..100), rounded to three decimals. Rectangles are
 * the only shape used so far; `points` (polygon vertices in the same units) is reserved for later layouts and stays empty.
 */
final class Shape
{
    public const PRECISION = 3;

    public readonly float $x;
    public readonly float $y;
    public readonly float $w;
    public readonly float $h;

    /**
     * @param list<array{0: float, 1: float}> $points Reserved for polygon layouts; unused by rectangles.
     */
    public function __construct(
        float $x,
        float $y,
        float $w,
        float $h,
        public readonly array $points = [],
    ) {
        foreach ([$x, $y, $w, $h] as $value) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException('A shape needs finite numbers.');
            }
        }
        if ($w < 0.0 || $h < 0.0) {
            throw new \InvalidArgumentException('A shape cannot have a negative size.');
        }
        $this->x = round($x, self::PRECISION);
        $this->y = round($y, self::PRECISION);
        $this->w = round($w, self::PRECISION);
        $this->h = round($h, self::PRECISION);
    }

    /** Area in percent points squared (a full container is 10000). */
    public function area(): float
    {
        return $this->w * $this->h;
    }

    /**
     * @return array{x: float, y: float, w: float, h: float}
     */
    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y, 'w' => $this->w, 'h' => $this->h];
    }
}
