<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Import;

/** Per-object outcomes of an import run. Counts are derived, so they can never disagree with the details. */
final class ImportResult
{
    /**
     * @param list<ImportOutcome> $outcomes
     */
    public function __construct(public readonly array $outcomes)
    {
    }

    public function count(ImportStatus $status): int
    {
        return count(array_filter($this->outcomes, static fn (ImportOutcome $o): bool => $o->status === $status));
    }

    public function total(): int
    {
        return count($this->outcomes);
    }

    /** True only when every object was imported, skipped as unchanged or recognised as duplicate. */
    public function isFullySuccessful(): bool
    {
        return $this->count(ImportStatus::Failed) === 0 && $this->count(ImportStatus::Unsupported) === 0;
    }

    /**
     * @return list<int> Attachment ids created by this run.
     */
    public function importedAttachmentIds(): array
    {
        $ids = [];
        foreach ($this->outcomes as $outcome) {
            if ($outcome->status === ImportStatus::Imported && $outcome->attachmentId !== null) {
                $ids[] = $outcome->attachmentId;
            }
        }

        return $ids;
    }
}
