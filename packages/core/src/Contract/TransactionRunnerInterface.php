<?php

declare(strict_types=1);

namespace SWGallery\Core\Contract;

/**
 * Runs a unit of work atomically (all writes including the revision claim, or none).
 *
 * Contract: the work runs inside one transaction; when it throws, everything it wrote is rolled back and the
 * exception is rethrown unchanged. A nested run() joins the outer transaction. Events must be dispatched by
 * callers only after run() returned.
 *
 * Fallback for storage engines without transactions (for example MyISAM): the implementation runs the work
 * without rollback. Use cases therefore always claim the revision BEFORE their other writes, so a write that
 * fails half-way still leaves a changed revision behind and stale clients are forced to reload instead of
 * silently overwriting partial state. Adapters should additionally log a warning once per request.
 */
interface TransactionRunnerInterface
{
    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function run(callable $work): mixed;
}
