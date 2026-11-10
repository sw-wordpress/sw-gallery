<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Import;

/** One cursor-paginated page of folders; `nextCursor` is null on the last page. */
final class FolderPage
{
    /**
     * @param list<RemoteFolder> $folders
     */
    public function __construct(public readonly array $folders, public readonly ?string $nextCursor = null)
    {
    }
}
