<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWGallery\Core\Application\Service\GalleryItemEditService;
use SWGallery\Core\Application\Service\LayoutRegistry;
use SWGallery\Core\Contract\ItemEditStoreInterface;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\ItemOverrides;
use SWGallery\Core\Domain\Gallery\NewGallery;
use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\Core\Domain\Settings\SettingsSchemaValidator;
use SWGallery\Core\Event\EventDispatcher;
use SWGallery\Core\Event\EventInterface;
use SWGallery\Core\Exception\AccessDeniedException;
use SWGallery\Core\Exception\GalleryNotFoundException;
use SWGallery\Core\Exception\ItemNotFoundException;
use SWGallery\Core\Exception\RevisionConflictException;
use SWGallery\Core\Exception\ValidationFailedException;
use SWGallery\Core\Logging\NullLogger;
use SWGallery\Tests\Support\ConfigurableAccessPolicy;
use SWGallery\Tests\Support\InMemoryGalleries;
use SWGallery\Tests\Support\InMemoryItems;
use SWGallery\Tests\Support\InMemoryTransactionRunner;
use SWGallery\Tests\Support\StubLayout;

final class GalleryItemEditServiceTest extends TestCase
{
    private InMemoryItems $store;
    private InMemoryGalleries $galleries;
    private GalleryItemEditService $service;
    private Actor $admin;
    private int $galleryId;
    private int $itemId;
    /** @var list<array<string, scalar|list<int>>> */
    private array $payloads = [];

    protected function setUp(): void
    {
        $this->admin = new Actor(1);
        $this->store = new InMemoryItems([10]);
        $this->galleries = new InMemoryGalleries($this->store);
        $layouts = new LayoutRegistry();
        $layouts->register(new StubLayout('grid', [
            'type' => 'object',
            'x-item-settings' => [
                'type' => 'object',
                'properties' => [
                    'focus' => ['type' => 'string', 'enum' => ['center', 'top'], 'default' => 'center'],
                    'zoom' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3],
                ],
            ],
        ]));
        $layouts->register(new StubLayout('free'));
        $events = new EventDispatcher(new NullLogger());
        $events->listen(EventInterface::class, function (EventInterface $e): void {
            $this->payloads[] = $e->payload();
        });
        $this->service = new GalleryItemEditService(
            $this->store,
            $this->galleries,
            $layouts,
            $this->store,
            $this->store,
            new SettingsSchemaValidator(),
            new InMemoryTransactionRunner([$this->store]),
            $events,
            new ConfigurableAccessPolicy([1])
        );

        $this->galleryId = $this->galleries->create(new NewGallery('G', 1))->id;
        $this->store->append($this->galleryId, 10);
        $this->store->compareAndIncrement($this->galleryId, new Revision(0));
        $this->itemId = $this->store->orderedIds($this->galleryId)[0];
    }

    public function testNullInheritsAndEmptyStringIsAnIntentionalBlank(): void
    {
        $revision = $this->service->updateOverrides(
            $this->admin,
            $this->galleryId,
            $this->itemId,
            new ItemOverrides(null, '', 'Alt text'),
            new Revision(1)
        );

        self::assertSame(2, $revision->value);
        $overrides = $this->store->findPage($this->galleryId, 0, 10)[0]->overrides;
        self::assertSame('inherited title', $overrides->resolveTitle('inherited title'));
        self::assertSame('', $overrides->resolveCaption('inherited caption'));
        self::assertSame('Alt text', $overrides->resolveAlt('inherited alt'));
        self::assertSame(
            [['gallery_id' => $this->galleryId, 'item_id' => $this->itemId, 'revision' => 2]],
            $this->payloads
        );

        $this->service->updateOverrides($this->admin, $this->galleryId, $this->itemId, new ItemOverrides(), new Revision(2));
        $reset = $this->store->findPage($this->galleryId, 0, 10)[0]->overrides;
        self::assertNull($reset->alt);
    }

    public function testOverridesRejectUnknownItemAndStaleRevisionWithoutWriting(): void
    {
        try {
            $this->service->updateOverrides($this->admin, $this->galleryId, 999, new ItemOverrides('x'), new Revision(1));
            self::fail('Unknown item.');
        } catch (ItemNotFoundException) {
            self::assertSame(1, $this->store->current($this->galleryId)->value);
        }

        try {
            $this->service->updateOverrides($this->admin, $this->galleryId, $this->itemId, new ItemOverrides('x'), new Revision(0));
            self::fail('Stale revision.');
        } catch (RevisionConflictException) {
            self::assertNull($this->store->findPage($this->galleryId, 0, 10)[0]->overrides->title);
            self::assertSame([], $this->payloads);
        }
    }

    public function testFailingWriteRollsTheOverrideClaimBack(): void
    {
        $this->store->failAfterWrites(0);

        try {
            $this->service->updateOverrides($this->admin, $this->galleryId, $this->itemId, new ItemOverrides('x'), new Revision(1));
            self::fail('The simulated failure must surface.');
        } catch (\RuntimeException) {
            self::assertSame(1, $this->store->current($this->galleryId)->value);
        }
    }

    public function testSettingsAreValidatedAndStoredWithDefaultsAndUnknownKeys(): void
    {
        $result = $this->service->setSettings(
            $this->admin,
            $this->galleryId,
            $this->itemId,
            ['zoom' => 2, 'from_future' => true],
            new Revision(1)
        );

        self::assertSame(['zoom' => 2, 'from_future' => true, 'focus' => 'center'], $result->settings);
        self::assertSame(2, $result->revision->value);
        self::assertSame($result->settings, $this->store->itemSettings($this->galleryId, $this->itemId));
    }

    public function testInvalidSettingsFailWithStableErrorsAndWriteNothing(): void
    {
        try {
            $this->service->setSettings(
                $this->admin,
                $this->galleryId,
                $this->itemId,
                ['zoom' => 9, 'focus' => 'x'],
                new Revision(1)
            );
            self::fail('Validation must fail.');
        } catch (ValidationFailedException $e) {
            self::assertSame('swg_validation_failed', $e->errorCode());
            self::assertSame(
                ['swg_not_in_enum@/focus', 'swg_above_maximum@/zoom'],
                array_map(static fn ($err): string => $err->code . '@' . $err->path, $e->errors)
            );
            self::assertSame(1, $this->store->current($this->galleryId)->value);
            self::assertSame([], $this->store->itemSettings($this->galleryId, $this->itemId));
        }
    }

    public function testLayoutWithoutItemSchemaStoresSettingsUntouched(): void
    {
        $this->galleries->update($this->galleries->find($this->galleryId)?->withSettings(
            (new \SWGallery\Core\Domain\Gallery\GallerySettings('free'))
        ) ?? throw new \LogicException());

        $result = $this->service->setSettings($this->admin, $this->galleryId, $this->itemId, ['a' => ['b' => 1]], new Revision(1));

        self::assertSame(['a' => ['b' => 1]], $result->settings);
    }

    public function testUnregisteredLayoutFallsBackToGridSchema(): void
    {
        $this->galleries->update($this->galleries->find($this->galleryId)?->withSettings(
            new \SWGallery\Core\Domain\Gallery\GallerySettings('removed_plugin_layout')
        ) ?? throw new \LogicException());

        $this->expectException(ValidationFailedException::class);
        $this->service->setSettings($this->admin, $this->galleryId, $this->itemId, ['zoom' => 99], new Revision(1));
    }

    public function testSettingsRejectUnknownGalleryAndItem(): void
    {
        try {
            $this->service->setSettings($this->admin, 999, $this->itemId, [], new Revision(0));
            self::fail('Unknown gallery.');
        } catch (GalleryNotFoundException) {
            self::assertSame([], $this->payloads);
        }

        $this->expectException(ItemNotFoundException::class);
        $this->service->setSettings($this->admin, $this->galleryId, 999, ['zoom' => 1], new Revision(1));
    }

    public function testCombinedEditUsesOneWriteAndEmitsOneEvent(): void
    {
        $this->store->failAfterWrites(1);
        $result = $this->service->updateItem(
            $this->admin,
            $this->galleryId,
            $this->itemId,
            new ItemOverrides('Combined'),
            ['focus' => 'top'],
            new Revision(1)
        );

        self::assertSame(2, $result->revision->value);
        self::assertSame(['focus' => 'top'], $this->store->itemSettings($this->galleryId, $this->itemId));
        self::assertCount(1, $this->payloads);
    }

    public function testItemVanishingBetweenCheckAndWriteRollsBack(): void
    {
        $rejecting = new class implements ItemEditStoreInterface {
            public function saveItemEdit(
                int $galleryId,
                int $itemId,
                ?ItemOverrides $overrides,
                ?array $settings
            ): bool {
                return false;
            }

            public function saveOverrides(int $galleryId, int $itemId, ItemOverrides $overrides): bool
            {
                return false;
            }

            public function itemSettings(int $galleryId, int $itemId): array
            {
                return [];
            }

            public function saveItemSettings(int $galleryId, int $itemId, array $settings): bool
            {
                return false;
            }
        };
        $layouts = new LayoutRegistry();
        $layouts->register(new StubLayout('grid'));
        $service = new GalleryItemEditService(
            $this->store,
            $this->galleries,
            $layouts,
            $this->store,
            $rejecting,
            new SettingsSchemaValidator(),
            new InMemoryTransactionRunner([$this->store]),
            new EventDispatcher(new NullLogger()),
            new ConfigurableAccessPolicy([1])
        );

        foreach (
            [
                fn () => $service->updateOverrides(
                    $this->admin,
                    $this->galleryId,
                    $this->itemId,
                    new ItemOverrides('x'),
                    new Revision(1)
                ),
                fn () => $service->setSettings($this->admin, $this->galleryId, $this->itemId, ['a' => 1], new Revision(1)),
            ] as $operation
        ) {
            try {
                $operation();
                self::fail('A vanished item must raise not found.');
            } catch (ItemNotFoundException) {
                self::assertSame(1, $this->store->current($this->galleryId)->value);
            }
        }
    }

    public function testDeniedActorChangesNothingAndLearnsNothingAboutValidation(): void
    {
        $stranger = new Actor(3);

        foreach (
            [
                fn () => $this->service->updateOverrides(
                    $stranger,
                    $this->galleryId,
                    $this->itemId,
                    new ItemOverrides('x'),
                    new Revision(1)
                ),
                fn () => $this->service->setSettings(
                    $stranger,
                    $this->galleryId,
                    $this->itemId,
                    ['zoom' => 99],
                    new Revision(1)
                ),
            ] as $operation
        ) {
            try {
                $operation();
                self::fail('Expected AccessDeniedException.');
            } catch (AccessDeniedException $e) {
                self::assertSame('swg_forbidden', $e->errorCode());
            }
        }
        self::assertSame(1, $this->store->current($this->galleryId)->value);
        self::assertSame([], $this->store->itemSettings($this->galleryId, $this->itemId));
        self::assertSame([], $this->payloads);

        $this->expectException(GalleryNotFoundException::class);
        $this->service->updateOverrides($this->admin, 999, $this->itemId, new ItemOverrides('x'), new Revision(1));
    }
}
