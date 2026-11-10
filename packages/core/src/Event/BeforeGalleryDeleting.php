<?php

declare(strict_types=1);

namespace SWGallery\Core\Event;

/** Fired before a gallery is permanently deleted; may veto. */
final class BeforeGalleryDeleting extends AbstractVetoableEvent
{
    public function __construct(public readonly int $galleryId, public readonly int $actorId)
    {
    }

    public function name(): string
    {
        return 'before_gallery_deleting';
    }

    public function payload(): array
    {
        return ['gallery_id' => $this->galleryId, 'actor_id' => $this->actorId];
    }
}
