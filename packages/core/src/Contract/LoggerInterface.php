<?php

declare(strict_types=1);

namespace SWGallery\Core\Contract;

use SWGallery\Core\Logging\LogLevel;

/**
 * Minimal structured logger. The WordPress adapter provides the sink (M1-08).
 *
 * Entries carry a stable machine-readable code and a context of scalars (IDs, counts, error codes, class names).
 * Never pass secrets, tokens, private URLs, file paths, exception messages or personal data in the context.
 */
interface LoggerInterface
{
    /**
     * @param string                          $code    Stable snake_case code, for example "swg_listener_failed".
     * @param array<string, scalar|null>      $context Safe scalar context only.
     */
    public function log(LogLevel $level, string $code, array $context = []): void;
}
