<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout\Plan;

/** What the planner knows about one picture. Out-of-range input is clamped, never rejected. */
final class PlanItem
{
    public const FALLBACK_RATIO = 1.5;
    public const MIN_RATIO = 0.25;
    public const MAX_RATIO = 4.0;
    public const MIN_EMPHASIS = 1;
    public const MAX_EMPHASIS = 3;

    public readonly float $aspectRatio;
    public readonly int $emphasis;
    public readonly float $focalX;
    public readonly float $focalY;

    /**
     * @param float|null $aspectRatio Width divided by height; null, zero or non-finite falls back to 3:2.
     * @param int        $emphasis    1..3.
     * @param float      $focalX      Focal point in percent (0..100).
     * @param float      $focalY      Focal point in percent (0..100).
     */
    public function __construct(?float $aspectRatio = null, int $emphasis = 1, float $focalX = 50.0, float $focalY = 50.0)
    {
        $this->aspectRatio = $aspectRatio === null || !is_finite($aspectRatio) || $aspectRatio <= 0.0
            ? self::FALLBACK_RATIO
            : max(self::MIN_RATIO, min(self::MAX_RATIO, $aspectRatio));
        $this->emphasis = max(self::MIN_EMPHASIS, min(self::MAX_EMPHASIS, $emphasis));
        $this->focalX = is_finite($focalX) ? max(0.0, min(100.0, $focalX)) : 50.0;
        $this->focalY = is_finite($focalY) ? max(0.0, min(100.0, $focalY)) : 50.0;
    }

    public static function fromSize(int $width, int $height, int $emphasis = 1, float $focalX = 50.0, float $focalY = 50.0): self
    {
        return new self($width > 0 && $height > 0 ? $width / $height : null, $emphasis, $focalX, $focalY);
    }
}
