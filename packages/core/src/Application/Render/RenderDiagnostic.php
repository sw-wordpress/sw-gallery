<?php

declare(strict_types=1);

namespace SWGallery\Core\Application\Render;

/**
 * A short message for users who can edit the gallery. The pipeline only creates diagnostics for such users;
 * the adapter maps the stable code to a translated text. Context holds safe scalars only.
 */
final class RenderDiagnostic
{
    public const GALLERY_NOT_FOUND = 'gallery_not_found';
    public const GALLERY_NOT_PUBLIC = 'gallery_not_public';
    public const UNKNOWN_LAYOUT = 'unknown_layout';
    public const LAYOUT_FAILED = 'layout_failed';
    public const INVALID_OVERRIDE = 'invalid_override';
    public const EMPTY_GALLERY = 'empty_gallery';
    public const IMAGES_UNAVAILABLE = 'images_unavailable';

    /**
     * @param array<string, scalar> $context
     */
    public function __construct(
        public readonly string $code,
        public readonly array $context = [],
    ) {
    }
}
