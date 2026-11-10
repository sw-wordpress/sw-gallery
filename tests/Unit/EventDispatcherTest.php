<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWGallery\Core\Event\BeforeGalleryDeleting;
use SWGallery\Core\Event\BeforeGalleryTrashing;
use SWGallery\Core\Event\EventDispatcher;
use SWGallery\Core\Event\EventInterface;
use SWGallery\Core\Event\GalleryCreated;
use SWGallery\Core\Event\GalleryDeleted;
use SWGallery\Core\Event\GalleryDuplicated;
use SWGallery\Core\Event\GalleryRestored;
use SWGallery\Core\Event\GalleryTrashed;
use SWGallery\Core\Event\GalleryUpdated;
use SWGallery\Core\Event\ItemsAssigned;
use SWGallery\Core\Event\ItemsRemoved;
use SWGallery\Core\Event\ItemsReordered;
use SWGallery\Core\Event\ItemUpdated;
use SWGallery\Core\Logging\LogLevel;
use SWGallery\Core\Logging\NullLogger;
use SWGallery\Tests\Support\RecordingLogger;

final class EventDispatcherTest extends TestCase
{
    public function testListenersRunByPriorityThenRegistrationOrder(): void
    {
        $dispatcher = new EventDispatcher(new NullLogger());
        $calls = [];
        $dispatcher->listen(GalleryUpdated::class, static function () use (&$calls): void {
            $calls[] = 'late';
        }, 20);
        $dispatcher->listen(GalleryUpdated::class, static function () use (&$calls): void {
            $calls[] = 'first-a';
        });
        $dispatcher->listen(EventInterface::class, static function () use (&$calls): void {
            $calls[] = 'first-b';
        });
        $dispatcher->listen(GalleryDeleted::class, static function () use (&$calls): void {
            $calls[] = 'never';
        });

        $dispatcher->dispatch(new GalleryUpdated(1, 2));

        self::assertSame(['first-a', 'first-b', 'late'], $calls);
    }

    public function testListenerFailureIsIsolatedAndLoggedWithoutMessage(): void
    {
        $logger = new RecordingLogger();
        $dispatcher = new EventDispatcher($logger);
        $reached = false;
        $dispatcher->listen(GalleryTrashed::class, static function (): void {
            throw new \RuntimeException('secret-token');
        });
        $dispatcher->listen(GalleryTrashed::class, static function () use (&$reached): void {
            $reached = true;
        });

        $dispatcher->dispatch(new GalleryTrashed(5, 3));

        self::assertTrue($reached);
        self::assertSame(['swg_listener_failed'], $logger->codes());
        self::assertSame(LogLevel::Error, $logger->entries[0]['level']);
        self::assertSame(['event' => 'gallery_trashed', 'exception' => \RuntimeException::class], $logger->entries[0]['context']);
        self::assertStringNotContainsString('secret', (string) json_encode($logger->entries[0]['context']));
    }

    public function testOnlyBeforeEventsCanVeto(): void
    {
        $dispatcher = new EventDispatcher(new NullLogger());
        $dispatcher->listen(BeforeGalleryDeleting::class, static function (EventInterface $e): void {
            self::assertInstanceOf(BeforeGalleryDeleting::class, $e);
            $e->veto('locked_by_plugin');
            $e->veto('second_ignored');
        });

        $event = new BeforeGalleryDeleting(4, 9);
        self::assertFalse($dispatcher->dispatchBefore($event));
        self::assertTrue($event->isVetoed());
        self::assertSame('locked_by_plugin', $event->vetoCode());

        $free = new BeforeGalleryTrashing(4, 9);
        self::assertTrue($dispatcher->dispatchBefore($free));
        self::assertFalse($free->isVetoed());
        self::assertNull($free->vetoCode());
    }

    public function testUnsafeVetoCodeIsReplaced(): void
    {
        $event = new BeforeGalleryTrashing(1, 1);
        $event->veto('Bad Code <script>');

        self::assertSame('swg_vetoed', $event->vetoCode());
    }

    public function testListenerThrowingInBeforeEventDoesNotVeto(): void
    {
        $logger = new RecordingLogger();
        $dispatcher = new EventDispatcher($logger);
        $dispatcher->listen(BeforeGalleryTrashing::class, static function (): void {
            throw new \LogicException('x');
        });

        self::assertTrue($dispatcher->dispatchBefore(new BeforeGalleryTrashing(1, 1)));
        self::assertSame(['swg_listener_failed'], $logger->codes());
    }

    public function testEventNamesAndPayloadsAreScalarsAndIds(): void
    {
        $cases = [
            [new GalleryCreated(1, 7, 1), 'gallery_created', ['gallery_id' => 1, 'owner_id' => 7, 'revision' => 1]],
            [new GalleryUpdated(1, 2), 'gallery_updated', ['gallery_id' => 1, 'revision' => 2]],
            [new GalleryTrashed(1, 3), 'gallery_trashed', ['gallery_id' => 1, 'revision' => 3]],
            [new GalleryRestored(1, 4), 'gallery_restored', ['gallery_id' => 1, 'revision' => 4]],
            [new GalleryDeleted(1, 5), 'gallery_deleted', ['gallery_id' => 1, 'revision' => 5]],
            [
                new GalleryDuplicated(2, 1, 7, 1),
                'gallery_duplicated',
                ['gallery_id' => 2, 'source_id' => 1, 'owner_id' => 7, 'revision' => 1],
            ],
            [
                new ItemsAssigned(1, [10, 11], 2),
                'items_assigned',
                ['gallery_id' => 1, 'attachment_ids' => [10, 11], 'revision' => 2],
            ],
            [new ItemsRemoved(1, [3], 2), 'items_removed', ['gallery_id' => 1, 'item_ids' => [3], 'revision' => 2]],
            [new ItemsReordered(1, 2), 'items_reordered', ['gallery_id' => 1, 'revision' => 2]],
            [new ItemUpdated(1, 8, 2), 'item_updated', ['gallery_id' => 1, 'item_id' => 8, 'revision' => 2]],
            [new BeforeGalleryTrashing(1, 9), 'before_gallery_trashing', ['gallery_id' => 1, 'actor_id' => 9]],
            [new BeforeGalleryDeleting(1, 9), 'before_gallery_deleting', ['gallery_id' => 1, 'actor_id' => 9]],
        ];
        foreach ($cases as [$event, $name, $payload]) {
            self::assertSame($name, $event->name());
            self::assertSame($payload, $event->payload());
        }
    }

    public function testNullLoggerDiscards(): void
    {
        (new NullLogger())->log(LogLevel::Debug, 'swg_test', ['a' => 1]);
        self::assertSame('warning', LogLevel::Warning->value);
    }
}
