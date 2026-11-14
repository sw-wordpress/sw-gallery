<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout\Plan;

/**
 * The arrangement of one page: the container's width/height ratio and one shape per input item, in input order.
 * Pure value object; views turn it into CSS custom properties.
 */
final class LayoutPlan
{
    /**
     * @param float       $aspectRatio Container width divided by height.
     * @param list<Shape> $shapes      Same order as the items the plan was made for.
     */
    public function __construct(
        public readonly float $aspectRatio,
        public readonly array $shapes,
    ) {
        if (!is_finite($aspectRatio) || $aspectRatio <= 0.0) {
            throw new \InvalidArgumentException('A plan needs a positive container aspect ratio.');
        }
    }

    public function count(): int
    {
        return count($this->shapes);
    }

    /**
     * Plain arrays for the view.
     *
     * @return array{aspect: float, shapes: list<array{x: float, y: float, w: float, h: float}>}
     */
    public function toArray(): array
    {
        return [
            'aspect' => round($this->aspectRatio, Shape::PRECISION),
            'shapes' => array_map(static fn (Shape $shape): array => $shape->toArray(), $this->shapes),
        ];
    }
}
