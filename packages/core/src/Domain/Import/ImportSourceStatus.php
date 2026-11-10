<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Import;

enum ImportSourceStatus: string
{
    case Active = 'active';
    case Disconnected = 'disconnected';
    case Error = 'error';
}
