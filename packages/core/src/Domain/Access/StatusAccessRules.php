<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Access;

use SWGallery\Core\Domain\Gallery\GalleryStatus;

/**
 * Decision table of decision D4. The adapter supplies the capability answers; this class holds the rule,
 * so every entry point (block, shortcode, PHP API, paging) shares exactly one decision.
 *
 * - publish: render; with a password the form is shown until it was unlocked (no editor bypass, as in
 *   WordPress core; editors use the authorized preview).
 * - private: render only for users who may read private galleries.
 * - draft, pending, future, trash: nothing for visitors, a diagnostic for users who can edit.
 */
final class StatusAccessRules
{
    public static function forEmbed(
        GalleryStatus $status,
        bool $passwordProtected,
        bool $passwordUnlocked,
        bool $canReadPrivate,
        bool $canEdit,
    ): RenderAccess {
        return match ($status) {
            GalleryStatus::Publish => $passwordProtected && !$passwordUnlocked
                ? RenderAccess::PasswordForm
                : RenderAccess::Render,
            GalleryStatus::Private => match (true) {
                $canReadPrivate => RenderAccess::Render,
                $canEdit => RenderAccess::Diagnostic,
                default => RenderAccess::Nothing,
            },
            GalleryStatus::Draft,
            GalleryStatus::Pending,
            GalleryStatus::Future,
            GalleryStatus::Trash => $canEdit ? RenderAccess::Diagnostic : RenderAccess::Nothing,
        };
    }
}
