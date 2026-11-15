<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout\Plan;

/**
 * One shard of a {@see ShardPlan}: the Voronoi `cell` (tiles the container exactly) and the `polygon` that is drawn
 * (the cell moved inwards by the grout). Everything is in percent of the plan container.
 */
final class Shard
{
    public function __construct(
        public readonly Polygon $cell,
        public readonly Polygon $polygon,
    ) {
    }

    /**
     * Bounding box of the drawn polygon (the tile of the shard) in percent of the container.
     *
     * @return array{x: float, y: float, w: float, h: float}
     */
    public function box(): array
    {
        return $this->polygon->boundingBox();
    }

    /**
     * Vertices of the drawn polygon in percent of the tile (the `clip-path` coordinates).
     *
     * @return list<array{0: float, 1: float}>
     */
    public function clip(): array
    {
        return $this->polygon->relativePoints();
    }
}
