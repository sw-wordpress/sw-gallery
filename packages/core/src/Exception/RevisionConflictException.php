<?php

declare(strict_types=1);

namespace SWGallery\Core\Exception;

/** The expected revision no longer matches the stored one (maps to HTTP 409). */
final class RevisionConflictException extends GalleryException
{
    public function __construct(public readonly int $expected, public readonly int $actual)
    {
        parent::__construct('The gallery was changed by someone else.');
    }

    public function errorCode(): string
    {
        return 'swg_revision_conflict';
    }
}
