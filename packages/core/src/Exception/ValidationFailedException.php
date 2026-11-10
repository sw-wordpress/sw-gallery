<?php

declare(strict_types=1);

namespace SWGallery\Core\Exception;

use SWGallery\Core\Domain\Settings\ValidationError;

/** Submitted settings do not satisfy the layout schema (maps to HTTP 422). */
final class ValidationFailedException extends GalleryException
{
    /**
     * @param list<ValidationError> $errors Never empty.
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The settings are invalid.');
    }

    public function errorCode(): string
    {
        return 'swg_validation_failed';
    }
}
