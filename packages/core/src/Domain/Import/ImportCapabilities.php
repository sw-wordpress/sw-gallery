<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Import;

/** What a provider can do; callers must not assume every provider offers the same semantics. */
final class ImportCapabilities
{
    public function __construct(
        public readonly bool $browseFolders = false,
        public readonly bool $recursiveListing = false,
        public readonly bool $revisionTracking = false,
        public readonly bool $contentHash = false,
    ) {
    }
}
