<?php

declare(strict_types=1);

namespace SWGallery\Core\Exception;

/** The actor may not perform the operation (maps to HTTP 403). */
final class AccessDeniedException extends GalleryException
{
    public function __construct()
    {
        parent::__construct('You are not allowed to do this.');
    }

    public function errorCode(): string
    {
        return 'swg_forbidden';
    }
}
