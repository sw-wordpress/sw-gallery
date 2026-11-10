<?php

declare(strict_types=1);

namespace SWGallery\Core\Contract;

interface MediaRepositoryInterface
{
    /**
     * @param list<int> $attachmentIds
     * @return list<int> The subset that exists and is an image attachment usable in a gallery.
     */
    public function existingImageIds(array $attachmentIds): array;
}
