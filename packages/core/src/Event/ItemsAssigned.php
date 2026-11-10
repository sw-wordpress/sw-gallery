<?php

declare(strict_types=1);

namespace SWGallery\Core\Event;

/** Attachments were newly assigned to a gallery. */
final class ItemsAssigned extends AbstractGalleryEvent
{
    /**
     * @param list<int> $attachmentIds Attachments that were actually added.
     */
    public function __construct(int $galleryId, public readonly array $attachmentIds, int $revision)
    {
        parent::__construct($galleryId, $revision);
    }

    public function name(): string
    {
        return 'items_assigned';
    }

    public function payload(): array
    {
        return [
            'gallery_id' => $this->galleryId,
            'attachment_ids' => $this->attachmentIds,
            'revision' => $this->revision,
        ];
    }
}
