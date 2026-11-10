<?php

declare(strict_types=1);

namespace SWGallery\Core\Logging;

/** Severity of a log entry, ordered from least to most severe. */
enum LogLevel: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}
