<?php

declare(strict_types=1);

namespace SWGallery\Core\Exception;

/** A before_* listener vetoed the operation; nothing was changed (maps to HTTP 403). */
final class OperationVetoedException extends GalleryException
{
    /** @param string $reason Safe snake_case code chosen by the vetoing listener. */
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The operation was prevented by an extension.');
    }

    public function errorCode(): string
    {
        return 'swg_operation_vetoed';
    }
}
