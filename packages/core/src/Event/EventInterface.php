<?php

declare(strict_types=1);

namespace SWGallery\Core\Event;

/**
 * A domain event, dispatched only after persistent state is consistent. Events are read-only value objects:
 * the payload contains IDs and scalars, never paths, tokens or private URLs.
 */
interface EventInterface
{
    /** Stable snake_case name without prefix; the WordPress adapter maps it to the public `swg_*` hook. */
    public function name(): string;

    /**
     * @return array<string, scalar|list<int>>
     */
    public function payload(): array;
}
