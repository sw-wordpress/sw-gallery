<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Import;

/**
 * Provider-neutral description of a remote file. Identity is the stable remote id (never name or path);
 * all text fields are untrusted data from a third party.
 */
final class RemoteMediaObject
{
    /** Safe maximum for stored remote ids (fits an indexed utf8mb4 column on every supported MySQL/MariaDB). */
    public const MAX_ID_LENGTH = 191;

    public function __construct(
        public readonly string $remoteObjectId,
        public readonly string $name,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly ?string $revision = null,
        public readonly ?string $etag = null,
        public readonly ?\DateTimeImmutable $modifiedAt = null,
        public readonly ?string $pathSnapshot = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
    ) {
        if ($remoteObjectId === '' || strlen($remoteObjectId) > self::MAX_ID_LENGTH || $sizeBytes < 0) {
            throw new \InvalidArgumentException('Invalid remote media object.');
        }
    }
}
