<?php

declare(strict_types=1);

namespace SWGallery\Core\Contract;

use SWGallery\Core\Domain\Import\ImportJobState;
use SWGallery\Core\Domain\Import\ImportResult;

/** Read side of a durable, resumable import job; the queue implementation arrives with the external-source milestone. */
interface ImportJobInterface
{
    public function getId(): string;

    public function getState(): ImportJobState;

    /** Partial results are available while the job is still running. */
    public function getResult(): ImportResult;

    public function isCancellationRequested(): bool;
}
