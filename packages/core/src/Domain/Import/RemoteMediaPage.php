<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Import;

/** One cursor-paginated page of remote media; `nextCursor` is null on the last page. */
final class RemoteMediaPage
{
    /**
     * @param list<RemoteMediaObject> $objects
     */
    public function __construct(public readonly array $objects, public readonly ?string $nextCursor = null)
    {
    }
}
