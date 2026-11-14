<?php

declare(strict_types=1);

namespace SWGallery\Core\Exception;

/** The proposed new owner of a gallery does not exist or may not own galleries (maps to HTTP 422). */
final class InvalidOwnerException extends GalleryException
{
    public function __construct()
    {
        parent::__construct('The new owner must be an existing user who may write posts.');
    }

    public function errorCode(): string
    {
        return 'swg_invalid_owner';
    }
}
