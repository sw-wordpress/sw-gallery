<?php

declare(strict_types=1);

namespace SWGallery\Core\Contract;

use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Access\RenderAccess;
use SWGallery\Core\Domain\Gallery\Gallery;

/**
 * The single access policy shared by REST, admin, block, shortcode, PHP API and preview.
 * Implementations answer from real authorization (WordPress capabilities in the adapter), never from client input.
 */
interface GalleryAccessPolicyInterface
{
    public function canCreate(Actor $actor): bool;

    /** Decides what an embed may output (decision D4); implementations delegate to StatusAccessRules. */
    public function embedAccess(Actor $actor, Gallery $gallery, bool $passwordUnlocked = false): RenderAccess;

    /** View in the admin app, including drafts of one's own galleries. */
    public function canView(Actor $actor, Gallery $gallery): bool;

    /** Edit the gallery definition, settings and items. */
    public function canEdit(Actor $actor, Gallery $gallery): bool;

    /** Trash, restore and permanently delete the gallery (never any attachment). */
    public function canDelete(Actor $actor, Gallery $gallery): bool;

    /** Decision D11: any image the actor can read in the Media Library may be assigned. */
    public function canUseAttachment(Actor $actor, int $attachmentId): bool;

    /** Changing global attachment metadata needs edit rights on the attachment itself. */
    public function canEditAttachment(Actor $actor, int $attachmentId): bool;
}
