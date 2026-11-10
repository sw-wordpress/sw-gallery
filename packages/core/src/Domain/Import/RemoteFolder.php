<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Import;

/** A remote folder. The name is display metadata only; identity is the provider's stable id. */
final class RemoteFolder
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?bool $hasChildren = null,
    ) {
        if ($id === '' || strlen($id) > RemoteMediaObject::MAX_ID_LENGTH) {
            throw new \InvalidArgumentException('Invalid remote folder id.');
        }
    }
}
