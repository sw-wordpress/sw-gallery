<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

use SWGallery\Core\Contract\GalleryAccessPolicyInterface;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Access\RenderAccess;
use SWGallery\Core\Domain\Access\StatusAccessRules;
use SWGallery\Core\Domain\Gallery\Gallery;
use SWGallery\Core\Domain\Gallery\GalleryStatus;

/**
 * Access policy double: grants everything to the listed user IDs (default: user 1 is the only admin) and
 * additionally edit/view rights on their own galleries to every non-anonymous user.
 */
final class ConfigurableAccessPolicy implements GalleryAccessPolicyInterface
{
    /**
     * @param list<int> $admins
     * @param list<int> $unusableAttachments Attachment IDs nobody may use.
     */
    public function __construct(
        private readonly array $admins = [1],
        private readonly bool $ownersMayDelete = false,
        private readonly array $unusableAttachments = [],
    ) {
    }

    public function canCreate(Actor $actor): bool
    {
        return !$actor->isAnonymous();
    }

    public function embedAccess(Actor $actor, Gallery $gallery, bool $passwordUnlocked = false): RenderAccess
    {
        $canView = $this->canView($actor, $gallery);

        return StatusAccessRules::forEmbed(
            $gallery->status,
            $gallery->passwordProtected,
            $passwordUnlocked,
            $canView,
            $canView
        );
    }

    public function previewAccess(Actor $actor, Gallery $gallery): RenderAccess
    {
        return $gallery->status !== GalleryStatus::Trash && $this->canEdit($actor, $gallery)
            ? RenderAccess::Render
            : RenderAccess::Nothing;
    }

    public function canView(Actor $actor, Gallery $gallery): bool
    {
        return $this->isAdmin($actor) || $this->isOwner($actor, $gallery);
    }

    public function canEdit(Actor $actor, Gallery $gallery): bool
    {
        return $this->canView($actor, $gallery);
    }

    public function canDelete(Actor $actor, Gallery $gallery): bool
    {
        return $this->isAdmin($actor) || ($this->ownersMayDelete && $this->isOwner($actor, $gallery));
    }

    public function canUseAttachment(Actor $actor, int $attachmentId): bool
    {
        return !$actor->isAnonymous() && !in_array($attachmentId, $this->unusableAttachments, true);
    }

    public function canEditAttachment(Actor $actor, int $attachmentId): bool
    {
        return $this->isAdmin($actor);
    }

    private function isAdmin(Actor $actor): bool
    {
        return in_array($actor->userId, $this->admins, true);
    }

    private function isOwner(Actor $actor, Gallery $gallery): bool
    {
        return !$actor->isAnonymous() && $gallery->ownerId === $actor->userId;
    }
}
