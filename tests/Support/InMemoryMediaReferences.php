<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

use SWGallery\Core\Contract\MediaReferenceRepositoryInterface;
use SWGallery\Core\Domain\Media\MediaReference;

/** In-memory fake of the batched media reference lookup; counts calls so tests can assert batching. */
final class InMemoryMediaReferences implements MediaReferenceRepositoryInterface
{
    public const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];

    /** @var array<int, MediaReference> */
    private array $references = [];
    private int $calls = 0;

    public function add(MediaReference $reference): void
    {
        $this->references[$reference->attachmentId] = $reference;
    }

    public function calls(): int
    {
        return $this->calls;
    }

    public function findReferences(array $attachmentIds): array
    {
        ++$this->calls;
        $found = [];
        foreach ($attachmentIds as $id) {
            $reference = $this->references[$id] ?? null;
            if ($reference !== null && in_array($reference->mimeType, self::ALLOWED_MIME_TYPES, true)) {
                $found[$id] = $reference;
            }
        }

        return $found;
    }
}
