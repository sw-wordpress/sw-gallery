<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Import;

enum ImportJobState: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
