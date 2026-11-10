<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Import;

/** Outcome of one remote object. Partial results are reported honestly, never as complete. */
enum ImportStatus: string
{
    case Imported = 'imported';
    case Skipped = 'skipped';
    case Duplicate = 'duplicate';
    case Failed = 'failed';
    case Unsupported = 'unsupported';
}
