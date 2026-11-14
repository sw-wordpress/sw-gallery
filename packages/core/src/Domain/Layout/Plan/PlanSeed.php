<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout\Plan;

/** Deterministic seed of a plan (L-00b): gallery id plus stored revision, so the arrangement only changes when the gallery does. */
final class PlanSeed
{
    public static function from(int $galleryId, int $revision, int $shuffle = 0): int
    {
        return (int) hexdec(substr(hash('sha256', sprintf('swg-plan:%d:%d:%d', $galleryId, $revision, $shuffle)), 0, 8));
    }
}
