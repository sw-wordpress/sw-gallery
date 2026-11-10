<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Gallery;

/**
 * A persisted gallery. Immutable; every change returns a new instance.
 *
 * The description is plain text (decision F-10). The revision is not part of the entity: it is claimed
 * through the RevisionStore when a change is persisted. The cover is any readable image attachment and
 * need not be a member of the gallery.
 */
final class Gallery
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $description,
        public readonly GalleryStatus $status,
        public readonly int $ownerId,
        public readonly ?int $coverAttachmentId,
        public readonly GallerySettings $settings,
        public readonly \DateTimeImmutable $modifiedAt,
        public readonly bool $passwordProtected = false,
    ) {
        if ($id <= 0 || $ownerId < 0) {
            throw new \InvalidArgumentException('Invalid gallery identifiers.');
        }
        if ($coverAttachmentId !== null && $coverAttachmentId <= 0) {
            throw new \InvalidArgumentException('The cover must be a positive attachment id or null.');
        }
    }

    public function withTitle(string $title): self
    {
        return new self(
            $this->id,
            $title,
            $this->description,
            $this->status,
            $this->ownerId,
            $this->coverAttachmentId,
            $this->settings,
            $this->modifiedAt,
            $this->passwordProtected
        );
    }

    public function withDescription(string $description): self
    {
        return new self(
            $this->id,
            $this->title,
            $description,
            $this->status,
            $this->ownerId,
            $this->coverAttachmentId,
            $this->settings,
            $this->modifiedAt,
            $this->passwordProtected
        );
    }

    public function withStatus(GalleryStatus $status): self
    {
        return new self(
            $this->id,
            $this->title,
            $this->description,
            $status,
            $this->ownerId,
            $this->coverAttachmentId,
            $this->settings,
            $this->modifiedAt,
            $this->passwordProtected
        );
    }

    public function withOwner(int $ownerId): self
    {
        return new self(
            $this->id,
            $this->title,
            $this->description,
            $this->status,
            $ownerId,
            $this->coverAttachmentId,
            $this->settings,
            $this->modifiedAt,
            $this->passwordProtected
        );
    }

    public function withCover(?int $coverAttachmentId): self
    {
        return new self(
            $this->id,
            $this->title,
            $this->description,
            $this->status,
            $this->ownerId,
            $coverAttachmentId,
            $this->settings,
            $this->modifiedAt,
            $this->passwordProtected
        );
    }

    public function withSettings(GallerySettings $settings): self
    {
        return new self(
            $this->id,
            $this->title,
            $this->description,
            $this->status,
            $this->ownerId,
            $this->coverAttachmentId,
            $settings,
            $this->modifiedAt,
            $this->passwordProtected
        );
    }
}
