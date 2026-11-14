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

    /**
     * Authorised preview (FU-13): what the preview entry point may output for an actor. Users who can edit the gallery
     * see any non-trashed gallery regardless of status or password; everybody else gets nothing. Never used for the
     * public entry points, which stay on embedAccess.
     */
    public function previewAccess(Actor $actor, Gallery $gallery): RenderAccess;

    /** View in the admin app, including drafts of one's own galleries. */
    public function canView(Actor $actor, Gallery $gallery): bool;

    /** Edit the gallery definition, settings and items. */
    public function canEdit(Actor $actor, Gallery $gallery): bool;

    /** Trash, restore and permanently delete the gallery (never any attachment). */
    public function canDelete(Actor $actor, Gallery $gallery): bool;

    /** Decision S-04: handing a gallery to another owner needs the right to edit other users' galleries. */
    public function canChangeOwner(Actor $actor, Gallery $gallery): bool;

    /** Decision S-04: whether the user exists and may own galleries (holds the right to write posts). */
    public function canOwnGalleries(int $userId): bool;

    /** Decision D11: any image the actor may read may be assigned (FU-16: no upload right needed). */
    public function canUseAttachment(Actor $actor, int $attachmentId): bool;

    /** Changing global attachment metadata needs edit rights on the attachment itself. */
    public function canEditAttachment(Actor $actor, int $attachmentId): bool;
}
