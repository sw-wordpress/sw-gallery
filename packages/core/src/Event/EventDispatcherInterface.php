<?php

declare(strict_types=1);

namespace SWGallery\Core\Event;

/**
 * Failure policy (docs/hooks.md): a listener that throws is isolated, logged and ignored; the remaining
 * listeners still run and the operation is unaffected. Only dispatchBefore() lets listeners veto.
 */
interface EventDispatcherInterface
{
    /**
     * Registers a listener for an event class or interface (instanceof match). Lower priority runs first;
     * equal priorities run in registration order.
     *
     * @param class-string<EventInterface> $eventClass
     * @param callable(EventInterface): void $listener
     */
    public function listen(string $eventClass, callable $listener, int $priority = 10): void;

    /** Notifies listeners of a change that already happened. Never throws because of a listener. */
    public function dispatch(EventInterface $event): void;

    /**
     * Asks listeners before a change. Never throws because of a listener.
     *
     * @return bool True when the operation may proceed, false when a listener vetoed it.
     */
    public function dispatchBefore(VetoableEventInterface $event): bool;
}
