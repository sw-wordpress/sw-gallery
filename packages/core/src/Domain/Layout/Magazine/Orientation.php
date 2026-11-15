<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout\Magazine;

/** Coarse shape of a picture, by width / height. Used for documentation, tests and statistics; the planner scores real ratios. */
enum Orientation: string
{
    case Landscape = 'landscape';
    case Portrait = 'portrait';
    case Square = 'square';

    /** At or above this ratio a picture is a landscape. */
    public const LANDSCAPE_FROM = 1.15;

    /** At or below this ratio a picture is a portrait. */
    public const PORTRAIT_UP_TO = 0.87;

    public static function fromRatio(float $ratio): self
    {
        if ($ratio >= self::LANDSCAPE_FROM) {
            return self::Landscape;
        }

        return $ratio <= self::PORTRAIT_UP_TO ? self::Portrait : self::Square;
    }
}
