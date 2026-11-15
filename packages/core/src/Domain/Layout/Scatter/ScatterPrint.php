<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout\Scatter;

/**
 * One print of a {@see ScatterPlan}: rotation in degrees (clockwise, as CSS `rotate()`), offset of its centre in percent
 * of the grid cell (`dx` of the cell width, `dy` of the cell height) and the stacking position `z` (1 = bottom).
 * Rotation and offsets are rounded to two decimals, exactly the numbers the view writes into the markup.
 */
final class ScatterPrint
{
    public function __construct(
        public readonly float $rotation,
        public readonly float $dx,
        public readonly float $dy,
        public readonly int $z,
    ) {
        foreach ([$rotation, $dx, $dy] as $value) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException('A print needs finite numbers.');
            }
        }
        if ($z < 1) {
            throw new \InvalidArgumentException('The stacking position starts at 1.');
        }
    }
}
