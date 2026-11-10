<?php

declare(strict_types=1);

namespace SWGallery\Core\Exception;

/** Base type of all domain errors; the WordPress adapter maps subtypes to REST error codes. */
abstract class GalleryException extends \RuntimeException
{
    /** Stable, safe error code (never contains user data). */
    abstract public function errorCode(): string;
}
