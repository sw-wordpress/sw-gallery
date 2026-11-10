<?php

declare(strict_types=1);

namespace SWGallery\Core\Event;

use SWGallery\Core\Contract\LoggerInterface;
use SWGallery\Core\Logging\LogLevel;

final class EventDispatcher implements EventDispatcherInterface
{
    /** @var list<array{priority: int, sequence: int, class: class-string<EventInterface>, listener: callable}> */
    private array $listeners = [];
    private int $sequence = 0;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function listen(string $eventClass, callable $listener, int $priority = 10): void
    {
        $this->listeners[] = [
            'priority' => $priority,
            'sequence' => $this->sequence++,
            'class' => $eventClass,
            'listener' => $listener,
        ];
    }

    public function dispatch(EventInterface $event): void
    {
        $this->notify($event);
    }

    public function dispatchBefore(VetoableEventInterface $event): bool
    {
        $this->notify($event);

        return !$event->isVetoed();
    }

    private function notify(EventInterface $event): void
    {
        $matching = array_filter(
            $this->listeners,
            static fn (array $entry): bool => $event instanceof $entry['class']
        );
        usort(
            $matching,
            static fn (array $a, array $b): int => [$a['priority'], $a['sequence']] <=> [$b['priority'], $b['sequence']]
        );

        foreach ($matching as $entry) {
            try {
                ($entry['listener'])($event);
            } catch (\Throwable $e) {
                // Isolated by policy: never log the message, it may contain secrets.
                $this->logger->log(
                    LogLevel::Error,
                    'swg_listener_failed',
                    ['event' => $event->name(), 'exception' => $e::class]
                );
            }
        }
    }
}
