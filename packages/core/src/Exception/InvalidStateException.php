<?php

declare(strict_types=1);

namespace SWGallery\Core\Exception;

/** The operation does not apply to the current state, for example restoring a gallery that is not trashed (HTTP 409). */
final class InvalidStateException extends GalleryException
{
    public function __construct()
    {
        parent::__construct('The gallery is in a state that does not allow this operation.');
    }

    public function errorCode(): string
    {
        return 'swg_invalid_state';
    }
}
