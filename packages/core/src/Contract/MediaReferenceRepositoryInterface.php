<?php

declare(strict_types=1);

namespace SWGallery\Core\Contract;

use SWGallery\Core\Domain\Media\MediaReference;

/**
 * Batched read access to the global metadata of attachments (title, caption, alt, MIME type, dimensions).
 * Implementations must answer a whole batch with a bounded number of queries, never one query per attachment.
 */
interface MediaReferenceRepositoryInterface
{
    /** Upper bound of ids answered per call; larger inputs are processed in chunks of this size. */
    public const MAX_BATCH = 500;

    /**
     * @param list<int> $attachmentIds
     * @return array<int, MediaReference> Keyed by attachment id. Missing attachments and attachments that are not
     *                                    usable images (unsupported MIME type, trashed) are omitted.
     */
    public function findReferences(array $attachmentIds): array;
}
