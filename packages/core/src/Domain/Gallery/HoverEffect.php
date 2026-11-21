<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Gallery;

/**
 * Hover texts of the images of a gallery (uniform for every layout): the image title and/or the caption, shown with
 * one of the {@see HoverAnimation}s. Without any text the effect is inactive and nothing is rendered.
 */
final class HoverEffect
{
    public function __construct(
        public readonly bool $showTitle = false,
        public readonly bool $showCaption = false,
        public readonly HoverAnimation $animation = HoverAnimation::Fade,
    ) {
    }

    public function isActive(): bool
    {
        return $this->showTitle || $this->showCaption;
    }

    /**
     * Strict parser for stored or submitted data; every invalid shape is rejected (null), never repaired.
     * Missing keys fall back to "off" / fade; unknown keys are ignored.
     *
     * @param mixed $data
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }
        $title = $data['title'] ?? false;
        $caption = $data['caption'] ?? false;
        $animation = $data['animation'] ?? HoverAnimation::Fade->value;
        if (!is_bool($title) || !is_bool($caption) || !is_string($animation)) {
            return null;
        }
        $parsed = HoverAnimation::tryFrom($animation);

        return $parsed === null ? null : new self($title, $caption, $parsed);
    }

    /**
     * @return array{title: bool, caption: bool, animation: string}
     */
    public function toArray(): array
    {
        return ['title' => $this->showTitle, 'caption' => $this->showCaption, 'animation' => $this->animation->value];
    }

    public function equals(?self $other): bool
    {
        return $other !== null && $this->toArray() === $other->toArray();
    }
}
