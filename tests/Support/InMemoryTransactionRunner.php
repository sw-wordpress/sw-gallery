<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

use SWGallery\Core\Contract\TransactionRunnerInterface;

/**
 * Transaction double. In atomic mode a failing unit of work restores the snapshot of every participant; with
 * atomic=false it mimics an engine without transactions (the work simply runs, nothing is rolled back).
 */
final class InMemoryTransactionRunner implements TransactionRunnerInterface
{
    public int $commits = 0;
    public int $rollbacks = 0;
    private int $depth = 0;

    /**
     * @param list<Snapshotable> $participants
     */
    public function __construct(private readonly array $participants, private readonly bool $atomic = true)
    {
    }

    public function run(callable $work): mixed
    {
        if ($this->depth > 0) {
            return $work();
        }

        $snapshots = array_map(static fn (Snapshotable $p): mixed => $p->captureState(), $this->participants);
        ++$this->depth;
        try {
            $result = $work();
        } catch (\Throwable $e) {
            if ($this->atomic) {
                foreach ($this->participants as $index => $participant) {
                    $participant->restoreState($snapshots[$index]);
                }
                ++$this->rollbacks;
            }
            throw $e;
        } finally {
            --$this->depth;
        }
        ++$this->commits;

        return $result;
    }
}
