<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWGallery\Core\Application\Service\GalleryService;
use SWGallery\Core\Application\Service\LayoutRegistry;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Gallery\NewGallery;
use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\Core\Domain\Settings\SettingsSchemaValidator;
use SWGallery\Core\Event\BeforeGalleryDeleting;
use SWGallery\Core\Event\BeforeGalleryTrashing;
use SWGallery\Core\Event\EventDispatcher;
use SWGallery\Core\Event\EventInterface;
use SWGallery\Core\Exception\AccessDeniedException;
use SWGallery\Core\Exception\GalleryNotFoundException;
use SWGallery\Core\Exception\InvalidStateException;
use SWGallery\Core\Exception\OperationVetoedException;
use SWGallery\Core\Exception\RevisionConflictException;
use SWGallery\Core\Exception\UnknownLayoutException;
use SWGallery\Core\Exception\ValidationFailedException;
use SWGallery\Core\Logging\NullLogger;
use SWGallery\Tests\Support\ConfigurableAccessPolicy;
use SWGallery\Tests\Support\InMemoryGalleries;
use SWGallery\Tests\Support\InMemoryItems;
use SWGallery\Tests\Support\InMemoryTransactionRunner;
use SWGallery\Tests\Support\StubLayout;

final class GalleryServiceTest extends TestCase
{
    private const ADMIN = 1;
    private const OWNER = 2;
    private const STRANGER = 3;

    private InMemoryItems $items;
    private InMemoryGalleries $galleries;
    private EventDispatcher $events;
    private LayoutRegistry $layouts;
    private GalleryService $service;
    /** @var list<string> */
    private array $seen = [];

    protected function setUp(): void
    {
        $this->items = new InMemoryItems([10, 11]);
        $this->galleries = new InMemoryGalleries($this->items);
        $this->layouts = new LayoutRegistry();
        $this->layouts->register(new StubLayout('grid', [
            'type' => 'object',
            'properties' => ['columns' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8]],
        ]));
        $this->events = new EventDispatcher(new NullLogger());
        $this->events->listen(EventInterface::class, function (EventInterface $e): void {
            if (!str_starts_with($e->name(), 'before_')) {
                $this->seen[] = $e->name();
            }
        });
        $this->service = $this->serviceWith(new ConfigurableAccessPolicy([self::ADMIN]));
    }

    private function serviceWith(ConfigurableAccessPolicy $policy): GalleryService
    {
        return new GalleryService(
            $this->galleries,
            $this->items,
            new InMemoryTransactionRunner([$this->galleries, $this->items]),
            $policy,
            $this->events,
            $this->layouts,
            new SettingsSchemaValidator()
        );
    }

    private function create(int $owner = self::OWNER): int
    {
        return $this->service->create(new Actor($owner), new NewGallery('G', $owner))->gallery->id;
    }

    public function testCreateYieldsRevisionOneOwnedByTheActor(): void
    {
        $result = $this->service->create(
            new Actor(self::OWNER),
            new NewGallery(
                'Trip',
                self::ADMIN,
                'Text',
                GalleryStatus::Publish,
                new GallerySettings('grid', values: ['columns' => 3])
            )
        );

        self::assertSame(1, $result->revision->value);
        self::assertSame(1, $this->items->current($result->gallery->id)->value);
        self::assertSame(self::OWNER, $result->gallery->ownerId, 'ownership cannot be spoofed');
        self::assertSame(GalleryStatus::Publish, $result->gallery->status);
        self::assertSame(['gallery_created'], $this->seen);
    }

    public function testCreateIsDeniedForAnonymousAndValidatesSettings(): void
    {
        try {
            $this->service->create(Actor::anonymous(), new NewGallery('x', 0));
            self::fail('Anonymous users cannot create.');
        } catch (AccessDeniedException $e) {
            self::assertSame('swg_forbidden', $e->errorCode());
        }

        try {
            $this->service->create(
                new Actor(self::OWNER),
                new NewGallery('x', 2, settings: new GallerySettings('grid', values: ['columns' => 99]))
            );
            self::fail('Invalid settings must fail.');
        } catch (ValidationFailedException $e) {
            self::assertSame('/columns', $e->errors[0]->path);
        }

        try {
            $this->service->create(
                new Actor(self::OWNER),
                new NewGallery('x', 2, settings: new GallerySettings('nope'))
            );
            self::fail('Unknown layout must fail.');
        } catch (UnknownLayoutException) {
            self::assertSame(0, $this->galleries->search(new \SWGallery\Core\Domain\Gallery\GalleryQuery())->total);
            self::assertSame([], $this->seen);
        }
    }

    public function testFailingCreateLeavesNothingBehind(): void
    {
        $this->galleries->failNextWrite();

        try {
            $this->create();
            self::fail('The simulated failure must surface.');
        } catch (\RuntimeException) {
            self::assertSame([], $this->seen);
            self::assertSame(0, $this->galleries->search(new \SWGallery\Core\Domain\Gallery\GalleryQuery())->total);
        }
    }

    public function testUpdateBumpsTheRevisionAndPersists(): void
    {
        $id = $this->create();
        $gallery = $this->galleries->find($id) ?? throw new \LogicException();

        $result = $this->service->update(
            new Actor(self::OWNER),
            $gallery->withTitle('New')->withCover(10)
                ->withSettings(new GallerySettings('grid', values: ['columns' => 2])),
            new Revision(1)
        );

        self::assertSame(2, $result->revision->value);
        self::assertSame('New', $result->gallery->title);
        self::assertSame(10, $this->galleries->find($id)?->coverAttachmentId);
        self::assertSame(['gallery_created', 'gallery_updated'], $this->seen);
    }

    public function testStaleUpdateWritesNothingAndFiresNoEvent(): void
    {
        $id = $this->create();
        $gallery = $this->galleries->find($id) ?? throw new \LogicException();

        try {
            $this->service->update(new Actor(self::OWNER), $gallery->withTitle('Lost'), new Revision(0));
            self::fail('A stale revision must conflict.');
        } catch (RevisionConflictException) {
            self::assertSame('G', $this->galleries->find($id)?->title);
            self::assertSame(1, $this->items->current($id)->value);
            self::assertSame(['gallery_created'], $this->seen);
        }
    }

    public function testFailingUpdateRollsTheRevisionBack(): void
    {
        $id = $this->create();
        $gallery = $this->galleries->find($id) ?? throw new \LogicException();
        $this->galleries->failNextWrite();

        try {
            $this->service->update(new Actor(self::OWNER), $gallery->withTitle('X'), new Revision(1));
            self::fail('The simulated failure must surface.');
        } catch (\RuntimeException) {
            self::assertSame(1, $this->items->current($id)->value);
            self::assertSame('G', $this->galleries->find($id)?->title);
        }
    }

    public function testUpdateEnforcesAccessAndState(): void
    {
        $id = $this->create();
        $gallery = $this->galleries->find($id) ?? throw new \LogicException();

        $denied = [
            'stranger' => fn () => $this->service->update(new Actor(self::STRANGER), $gallery, new Revision(1)),
            'owner transfer' => fn () => $this->service->update(
                new Actor(self::OWNER),
                $gallery->withOwner(self::STRANGER),
                new Revision(1)
            ),
        ];
        foreach ($denied as $operation) {
            try {
                $operation();
                self::fail('Expected AccessDeniedException.');
            } catch (AccessDeniedException) {
                self::assertSame(1, $this->items->current($id)->value);
            }
        }

        $moved = $this->service->update(new Actor(self::ADMIN), $gallery->withOwner(self::STRANGER), new Revision(1));
        self::assertSame(self::STRANGER, $moved->gallery->ownerId);

        try {
            $this->service->update(new Actor(self::ADMIN), $gallery->withStatus(GalleryStatus::Trash), new Revision(2));
            self::fail('Trash must go through trash().');
        } catch (InvalidStateException $e) {
            self::assertSame('swg_invalid_state', $e->errorCode());
        }

        $this->service->trash(new Actor(self::ADMIN), $id, new Revision(2));
        $this->expectException(InvalidStateException::class);
        $this->service->update(new Actor(self::ADMIN), $gallery, new Revision(3));
    }

    public function testUpdateOnlyValidatesChangedSettings(): void
    {
        $id = $this->service->create(
            new Actor(self::OWNER),
            new NewGallery('G', self::OWNER, settings: new GallerySettings('grid'))
        )->gallery->id;
        $legacy = ($this->galleries->find($id) ?? throw new \LogicException())
            ->withSettings(new GallerySettings('removed_layout', values: ['x' => 1]));
        $this->galleries->update($legacy);

        $result = $this->service->update(new Actor(self::OWNER), $legacy->withTitle('Renamed'), new Revision(1));
        self::assertSame('Renamed', $result->gallery->title);

        $this->expectException(UnknownLayoutException::class);
        $this->service->update(
            new Actor(self::OWNER),
            $legacy->withSettings(new GallerySettings('removed_layout', values: ['x' => 2])),
            new Revision(2)
        );
    }

    public function testUpdateOfMissingGallery(): void
    {
        $gallery = $this->galleries->create(new NewGallery('Temp', 1));
        $this->galleries->delete($gallery->id);

        $this->expectException(GalleryNotFoundException::class);
        $this->service->update(new Actor(self::ADMIN), $gallery, new Revision(0));
    }

    public function testDuplicateCopiesIntoAFreshDraftWithRevisionOne(): void
    {
        $id = $this->create();
        $this->items->append($id, 10);
        $this->items->append($id, 11);
        $this->items->compareAndIncrement($id, new Revision(1));

        $copy = $this->service->duplicate(new Actor(self::ADMIN), $id, 'Copy');

        self::assertSame('Copy', $copy->gallery->title);
        self::assertSame(self::ADMIN, $copy->gallery->ownerId);
        self::assertSame(GalleryStatus::Draft, $copy->gallery->status);
        self::assertSame(1, $copy->revision->value);
        self::assertSame(2, $this->items->count($copy->gallery->id));
        self::assertSame(2, $this->items->current($id)->value, 'the source keeps its revision');
        self::assertSame(['gallery_created', 'gallery_duplicated'], $this->seen);
    }

    public function testDuplicateNeedsViewRightAndAnExistingSource(): void
    {
        $id = $this->create();

        try {
            $this->service->duplicate(new Actor(self::STRANGER), $id, 'Steal');
            self::fail('A stranger must not copy a private gallery.');
        } catch (AccessDeniedException) {
            self::assertSame(1, $this->galleries->search(new \SWGallery\Core\Domain\Gallery\GalleryQuery())->total);
        }

        try {
            $this->service->duplicate(Actor::anonymous(), $id, 'Anon');
            self::fail('Anonymous users cannot create.');
        } catch (AccessDeniedException) {
            self::assertSame(['gallery_created'], $this->seen);
        }

        $this->expectException(GalleryNotFoundException::class);
        $this->service->duplicate(new Actor(self::ADMIN), 999, 'Nope');
    }

    public function testTrashKeepsRelationsAndAttachmentsThenRestoreGivesADraft(): void
    {
        $id = $this->create();
        $this->items->append($id, 10);

        $revision = $this->service->trash(new Actor(self::ADMIN), $id, new Revision(1));
        self::assertSame(2, $revision->value);
        self::assertSame(GalleryStatus::Trash, $this->galleries->find($id)?->status);
        self::assertSame(1, $this->items->count($id));
        self::assertSame([10], $this->items->existingImageIds([10]));

        $restored = $this->service->restore(new Actor(self::ADMIN), $id, new Revision(2));
        self::assertSame(3, $restored->revision->value);
        self::assertSame(GalleryStatus::Draft, $restored->gallery->status);
        self::assertSame(1, $this->items->count($id));
        self::assertSame(['gallery_created', 'gallery_trashed', 'gallery_restored'], $this->seen);
    }

    public function testTrashAndRestoreRejectWrongStateStaleRevisionAndStrangers(): void
    {
        $id = $this->create();
        $admin = new Actor(self::ADMIN);

        try {
            $this->service->restore($admin, $id, new Revision(1));
            self::fail('Only trashed galleries can be restored.');
        } catch (InvalidStateException) {
            self::assertSame(1, $this->items->current($id)->value);
        }

        try {
            $this->service->trash($admin, $id, new Revision(0));
            self::fail('Stale revision.');
        } catch (RevisionConflictException) {
            self::assertSame(GalleryStatus::Draft, $this->galleries->find($id)?->status);
        }

        try {
            $this->service->trash(new Actor(self::OWNER), $id, new Revision(1));
            self::fail('Owners may not trash without the delete right.');
        } catch (AccessDeniedException) {
            self::assertSame(GalleryStatus::Draft, $this->galleries->find($id)?->status);
        }

        $this->service->trash($admin, $id, new Revision(1));
        try {
            $this->service->trash($admin, $id, new Revision(2));
            self::fail('Already trashed.');
        } catch (InvalidStateException) {
            self::assertSame(2, $this->items->current($id)->value);
        }
    }

    public function testOwnersWithDeleteRightMayTrash(): void
    {
        $service = $this->serviceWith(new ConfigurableAccessPolicy([self::ADMIN], true));
        $id = $this->create();

        $service->trash(new Actor(self::OWNER), $id, new Revision(1));

        self::assertSame(GalleryStatus::Trash, $this->galleries->find($id)?->status);
    }

    public function testBeforeListenersCanVetoTrashAndDelete(): void
    {
        $id = $this->create();
        $this->events->listen(BeforeGalleryTrashing::class, static function (EventInterface $e): void {
            self::assertInstanceOf(BeforeGalleryTrashing::class, $e);
            $e->veto('retention_policy');
        });
        $this->events->listen(BeforeGalleryDeleting::class, static function (EventInterface $e): void {
            self::assertInstanceOf(BeforeGalleryDeleting::class, $e);
            $e->veto('BAD CODE');
        });
        $admin = new Actor(self::ADMIN);

        try {
            $this->service->trash($admin, $id, new Revision(1));
            self::fail('Veto expected.');
        } catch (OperationVetoedException $e) {
            self::assertSame('swg_operation_vetoed', $e->errorCode());
            self::assertSame('retention_policy', $e->reason);
        }
        try {
            $this->service->delete($admin, $id, new Revision(1));
            self::fail('Veto expected.');
        } catch (OperationVetoedException $e) {
            self::assertSame('swg_vetoed', $e->reason);
        }

        self::assertSame(GalleryStatus::Draft, $this->galleries->find($id)?->status);
        self::assertSame(1, $this->items->current($id)->value);
        self::assertSame(['gallery_created'], $this->seen);
    }

    public function testDeleteRemovesGalleryAndRelationsButNeverAttachments(): void
    {
        $id = $this->create();
        $this->items->append($id, 10);
        $this->items->append($id, 11);

        $this->service->delete(new Actor(self::ADMIN), $id, new Revision(1));

        self::assertNull($this->galleries->find($id));
        self::assertSame(0, $this->items->count($id));
        self::assertSame([10, 11], $this->items->existingImageIds([10, 11]));
        self::assertSame(['gallery_created', 'gallery_deleted'], $this->seen);
    }

    public function testDeleteChecksRevisionAccessExistenceAndRollsBackOnFailure(): void
    {
        $id = $this->create();
        $this->items->append($id, 10);
        $admin = new Actor(self::ADMIN);

        $attempts = [
            RevisionConflictException::class => fn () => $this->service->delete($admin, $id, new Revision(0)),
            AccessDeniedException::class => fn () => $this->service->delete(
                new Actor(self::OWNER),
                $id,
                new Revision(1)
            ),
            GalleryNotFoundException::class => fn () => $this->service->delete($admin, 999, new Revision(0)),
        ];
        foreach ($attempts as $expected => $operation) {
            try {
                $operation();
                self::fail('Expected ' . $expected);
            } catch (\Throwable $e) {
                self::assertInstanceOf($expected, $e);
            }
        }
        self::assertNotNull($this->galleries->find($id));

        $this->galleries->failNextWrite();
        try {
            $this->service->delete($admin, $id, new Revision(1));
            self::fail('The simulated failure must surface.');
        } catch (\RuntimeException) {
            self::assertNotNull($this->galleries->find($id));
            self::assertSame(1, $this->items->current($id)->value);
            self::assertSame(1, $this->items->count($id));
            self::assertSame(['gallery_created'], $this->seen);
        }
    }
}
