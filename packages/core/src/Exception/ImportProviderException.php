<?php

declare(strict_types=1);

namespace SWGallery\Core\Exception;

/**
 * A provider operation failed. Only transient failures (rate limit, 5xx, network) may be retried, within a
 * bounded budget; the code and message must never contain tokens, private URLs or remote file names.
 */
final class ImportProviderException extends GalleryException
{
    public function __construct(
        private readonly string $errorCodeValue,
        private readonly bool $transient,
        string $message = 'The import provider reported an error.',
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCodeValue;
    }

    public function isTransient(): bool
    {
        return $this->transient;
    }
}
