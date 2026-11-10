<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Access;

/** The user on whose behalf an operation runs. User id 0 is the anonymous visitor. */
final class Actor
{
    public function __construct(public readonly int $userId)
    {
        if ($userId < 0) {
            throw new \InvalidArgumentException('Invalid user id.');
        }
    }

    public static function anonymous(): self
    {
        return new self(0);
    }

    public function isAnonymous(): bool
    {
        return $this->userId === 0;
    }
}
