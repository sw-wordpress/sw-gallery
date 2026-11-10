<?php

declare(strict_types=1);

namespace SWGallery\Core\Exception;

/** A reorder request is not exactly a permutation of the gallery's items (maps to HTTP 422). */
final class InvalidOrderException extends GalleryException
{
    public function __construct()
    {
        parent::__construct('The order must contain every item of the gallery exactly once.');
    }

    public function errorCode(): string
    {
        return 'swg_invalid_order';
    }
}
