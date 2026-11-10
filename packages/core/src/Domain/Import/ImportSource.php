<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Import;

/**
 * A configured import source (account/scope of one provider). Holds no secrets: credentials live behind a
 * separate credential store (external-source milestone) and never appear here, in API output or in logs.
 */
final class ImportSource
{
    /**
     * @param array<string, mixed> $config Non-secret settings only (folder ids, import policy, sync preferences).
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $providerId,
        public readonly string $label,
        public readonly string $accountReference,
        public readonly string $scopeReference,
        public readonly array $config,
        public readonly ImportSourceStatus $status,
        public readonly int $createdBy,
    ) {
        if (
            ($id !== null && $id <= 0)
            || $providerId === ''
            || $label === ''
            || $accountReference === ''
            || $createdBy < 0
        ) {
            throw new \InvalidArgumentException('Invalid import source.');
        }
    }
}
