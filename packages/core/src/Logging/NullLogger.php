<?php

declare(strict_types=1);

namespace SWGallery\Core\Logging;

use SWGallery\Core\Contract\LoggerInterface;

/** Discards every entry. */
final class NullLogger implements LoggerInterface
{
    public function log(LogLevel $level, string $code, array $context = []): void
    {
    }
}
