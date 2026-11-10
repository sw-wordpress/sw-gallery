<?php

declare(strict_types=1);

namespace SWGallery\Core\Exception;

/** The gallery does not exist (maps to HTTP 404). */
final class GalleryNotFoundException extends GalleryException
{
    public function __construct()
    {
        parent::__construct('The gallery was not found.');
    }

    public function errorCode(): string
    {
        return 'swg_gallery_not_found';
    }
}
