<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout\Plan;

/**
 * The arrangement of one page of shards: the container's width/height ratio and one {@see Shard} per input item, in
 * input order. Pure value object; views turn it into CSS custom properties.
 */
final class ShardPlan
{
    /**
     * @param float       $aspectRatio Container width divided by height.
     * @param list<Shard> $shards      Same order as the items the plan was made for.
     */
    public function __construct(
        public readonly float $aspectRatio,
        public readonly array $shards,
    ) {
        if (!is_finite($aspectRatio) || $aspectRatio <= 0.0) {
            throw new \InvalidArgumentException('A plan needs a positive container aspect ratio.');
        }
    }

    public function count(): int
    {
        return count($this->shards);
    }
}
