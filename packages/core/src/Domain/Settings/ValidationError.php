<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Settings;

/**
 * One validation failure. The code is stable and safe, the path is a JSON pointer ("/columns/md") and the
 * message never contains submitted values.
 */
final class ValidationError
{
    public function __construct(
        public readonly string $code,
        public readonly string $path,
        public readonly string $message,
    ) {
    }

    /**
     * @return array{code: string, path: string, message: string}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'path' => $this->path, 'message' => $this->message];
    }
}
