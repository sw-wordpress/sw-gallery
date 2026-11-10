<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Import;

/**
 * Opaque handle that tells a provider which source to talk to. Carries no credentials: the provider obtains
 * them server-side from the credential service, they are never passed through core, REST or the browser.
 */
final class ImportConnection
{
    public function __construct(
        public readonly int $sourceId,
        public readonly string $providerId,
        public readonly string $accountReference,
        public readonly string $scopeReference,
    ) {
        if ($sourceId <= 0 || $providerId === '' || $accountReference === '') {
            throw new \InvalidArgumentException('Invalid import connection.');
        }
    }
}
