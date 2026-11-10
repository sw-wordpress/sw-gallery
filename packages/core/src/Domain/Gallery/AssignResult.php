<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Gallery;

/** Honest outcome of a bulk assignment: partial results are never reported as complete. */
final class AssignResult
{
    /**
     * @param list<int> $added             Attachment IDs newly assigned.
     * @param list<int> $alreadyAssigned   Attachment IDs skipped because the gallery already contains them.
     * @param list<int> $missing           Attachment IDs that do not exist (or are not images).
     */
    public function __construct(
        public readonly array $added,
        public readonly array $alreadyAssigned,
        public readonly array $missing,
        public readonly Revision $revision,
    ) {
    }
}
