<?php

declare(strict_types=1);

namespace SWGallery\Core\Exception;

/** The item does not exist in the given gallery (maps to HTTP 404). */
final class ItemNotFoundException extends GalleryException
{
    public function __construct()
    {
        parent::__construct('The gallery item was not found.');
    }

    public function errorCode(): string
    {
        return 'swg_item_not_found';
    }
}
