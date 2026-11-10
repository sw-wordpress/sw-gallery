<?php

declare(strict_types=1);

namespace SWGallery\Core\Event;

abstract class AbstractVetoableEvent implements VetoableEventInterface
{
    public const DEFAULT_VETO_CODE = 'swg_vetoed';

    private ?string $vetoCode = null;

    public function veto(string $code): void
    {
        $this->vetoCode ??= preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code) === 1 ? $code : self::DEFAULT_VETO_CODE;
    }

    public function isVetoed(): bool
    {
        return $this->vetoCode !== null;
    }

    public function vetoCode(): ?string
    {
        return $this->vetoCode;
    }
}
