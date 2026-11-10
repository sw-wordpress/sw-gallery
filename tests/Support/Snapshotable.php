<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

/** An in-memory fake whose state can be captured and restored, so InMemoryTransactionRunner can roll back. */
interface Snapshotable
{
    public function captureState(): mixed;

    public function restoreState(mixed $snapshot): void;
}
