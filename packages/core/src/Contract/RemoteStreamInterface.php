<?php

declare(strict_types=1);

namespace SWGallery\Core\Contract;

/** Bounded sequential reader for a remote file; callers copy it to a temp file, never into memory as a whole. */
interface RemoteStreamInterface
{
    /** Reads at most $length bytes; returns an empty string at the end. */
    public function read(int $length): string;

    public function isEof(): bool;

    public function close(): void;
}
