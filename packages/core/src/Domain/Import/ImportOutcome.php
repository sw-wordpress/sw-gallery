<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Import;

final class ImportOutcome
{
    /**
     * @param int|null    $attachmentId Set for imported and duplicate objects.
     * @param string|null $errorCode    Stable, safe code for failed/unsupported objects (no remote data).
     */
    public function __construct(
        public readonly string $remoteObjectId,
        public readonly ImportStatus $status,
        public readonly ?int $attachmentId = null,
        public readonly ?string $errorCode = null,
    ) {
        if ($remoteObjectId === '' || ($attachmentId !== null && $attachmentId <= 0)) {
            throw new \InvalidArgumentException('Invalid import outcome.');
        }
        if ($status === ImportStatus::Imported && $attachmentId === null) {
            throw new \InvalidArgumentException('An imported object needs its attachment id.');
        }
        if (($status === ImportStatus::Failed || $status === ImportStatus::Unsupported) && $errorCode === null) {
            throw new \InvalidArgumentException('A failed object needs an error code.');
        }
    }
}
