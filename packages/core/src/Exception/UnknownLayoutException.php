<?php

declare(strict_types=1);

namespace SWGallery\Core\Exception;

/** A layout id is not registered (maps to HTTP 422 when it comes from a request). */
final class UnknownLayoutException extends GalleryException
{
    public function __construct()
    {
        parent::__construct('The layout is not registered.');
    }

    public function errorCode(): string
    {
        return 'swg_unknown_layout';
    }
}
