<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout\Scatter;

/**
 * Result of the {@see ScatterPlanner}: one print per item in input order, the overlap that was actually used (percent of
 * the cell width, never more than requested), the space the prints can reach beyond their grid cells (in cell widths, so
 * the view can keep the outermost prints inside the container) and the smallest visible share of any print (0..1).
 */
final class ScatterPlan
{
    /**
     * @param list<ScatterPrint> $prints
     */
    public function __construct(
        public readonly array $prints,
        public readonly float $overlap,
        public readonly float $bleedX,
        public readonly float $bleedY,
        public readonly float $minVisibility,
    ) {
    }
}
