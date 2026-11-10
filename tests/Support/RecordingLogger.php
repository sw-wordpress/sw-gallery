<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

use SWGallery\Core\Contract\LoggerInterface;
use SWGallery\Core\Logging\LogLevel;

/** Test double that keeps every entry for assertions. */
final class RecordingLogger implements LoggerInterface
{
    /** @var list<array{level: LogLevel, code: string, context: array<string, scalar|null>}> */
    public array $entries = [];

    public function log(LogLevel $level, string $code, array $context = []): void
    {
        $this->entries[] = ['level' => $level, 'code' => $code, 'context' => $context];
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_map(static fn (array $e): string => $e['code'], $this->entries);
    }
}
