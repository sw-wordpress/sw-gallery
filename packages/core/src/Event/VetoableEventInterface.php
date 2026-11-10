<?php

declare(strict_types=1);

namespace SWGallery\Core\Event;

/**
 * An explicit "before_*" event fired before any state change. A listener may veto it; the operation then
 * fails cleanly with nothing written. No other event can veto.
 */
interface VetoableEventInterface extends EventInterface
{
    /** @param string $code Safe snake_case reason code; anything else is replaced by "swg_vetoed". */
    public function veto(string $code): void;

    public function isVetoed(): bool;

    public function vetoCode(): ?string;
}
