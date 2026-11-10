<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Gallery;

/** Monotonically increasing gallery revision; bumped on every persisted change. */
final class Revision
{
    public readonly int $value;

    public function __construct(int $value)
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('A revision cannot be negative.');
        }
        $this->value = $value;
    }

    public function next(): self
    {
        return new self($this->value + 1);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
