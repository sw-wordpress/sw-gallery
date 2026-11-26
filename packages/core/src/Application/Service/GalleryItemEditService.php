<?php

declare(strict_types=1);

namespace SWGallery\Core\Application\Service;

use SWGallery\Core\Contract\GalleryAccessPolicyInterface;
use SWGallery\Core\Contract\GalleryItemRepositoryInterface;
use SWGallery\Core\Contract\GalleryRepositoryInterface;
use SWGallery\Core\Contract\ItemEditStoreInterface;
use SWGallery\Core\Contract\LayoutRegistryInterface;
use SWGallery\Core\Contract\RevisionStoreInterface;
use SWGallery\Core\Contract\TransactionRunnerInterface;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\Gallery;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Gallery\ItemOverrides;
use SWGallery\Core\Domain\Gallery\ItemSettingsResult;
use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\Core\Domain\Settings\SettingsSchemaValidator;
use SWGallery\Core\Event\EventDispatcherInterface;
use SWGallery\Core\Event\ItemUpdated;
use SWGallery\Core\Exception\AccessDeniedException;
use SWGallery\Core\Exception\GalleryNotFoundException;
use SWGallery\Core\Exception\InvalidStateException;
use SWGallery\Core\Exception\ItemNotFoundException;
use SWGallery\Core\Exception\ValidationFailedException;

/**
 * Use cases that change one existing item: gallery-local overrides and per-item layout settings. Same rules as
 * GalleryItemService: one transaction, revision claimed first, events only after success.
 */
final class GalleryItemEditService
{
    /**
     * Key of the layout settings schema (GalleryLayoutInterface::getSettingsSchema()) that describes per-item
     * settings. A layout without it accepts any item settings, which are stored untouched.
     */
    public const ITEM_SCHEMA_KEY = 'x-item-settings';

    public function __construct(
        private readonly GalleryItemRepositoryInterface $items,
        private readonly GalleryRepositoryInterface $galleries,
        private readonly LayoutRegistryInterface $layouts,
        private readonly RevisionStoreInterface $revisions,
        private readonly ItemEditStoreInterface $edits,
        private readonly SettingsSchemaValidator $validator,
        private readonly TransactionRunnerInterface $transactions,
        private readonly EventDispatcherInterface $events,
        private readonly GalleryAccessPolicyInterface $access,
    ) {
    }

    /**
     * Replaces the overrides of an item. `null` means "inherit from the attachment", an empty string is an
     * intentional blank; a field that is not passed on is therefore reset to inherit.
     *
     * @throws GalleryNotFoundException
     * @throws AccessDeniedException
     * @throws ItemNotFoundException
     */
    public function updateOverrides(
        Actor $actor,
        int $galleryId,
        int $itemId,
        ItemOverrides $overrides,
        Revision $expected
    ): Revision {
        return $this->updateItem($actor, $galleryId, $itemId, $overrides, null, $expected)->revision;
    }

    /**
     * Validates the settings against the item schema of the gallery's layout and stores the normalized values
     * (defaults applied, unknown keys preserved).
     *
     * @param array<string, mixed> $settings
     * @throws ItemNotFoundException
     * @throws GalleryNotFoundException
     * @throws AccessDeniedException
     * @throws ValidationFailedException
     */
    public function setSettings(
        Actor $actor,
        int $galleryId,
        int $itemId,
        array $settings,
        Revision $expected
    ): ItemSettingsResult {
        return $this->updateItem($actor, $galleryId, $itemId, null, $settings, $expected);
    }

    /**
     * Saves the supplied fields together under one revision and publishes one event after the commit.
     *
     * @param array<string, mixed>|null $settings
     */
    public function updateItem(
        Actor $actor,
        int $galleryId,
        int $itemId,
        ?ItemOverrides $overrides,
        ?array $settings,
        Revision $expected
    ): ItemSettingsResult {
        if ($overrides === null && $settings === null) {
            throw new \InvalidArgumentException('An item edit must include overrides or settings.');
        }

        $gallery = $this->authorize($actor, $galleryId);
        $values = $settings === null ? null : $this->validateSettings($gallery, $settings);
        $revision = $this->transactions->run(
            function () use ($galleryId, $itemId, $overrides, $values, $expected): Revision {
                $this->requireItem($galleryId, $itemId);
                $revision = $this->revisions->compareAndIncrement($galleryId, $expected);
                if (!$this->edits->saveItemEdit($galleryId, $itemId, $overrides, $values)) {
                    throw new ItemNotFoundException();
                }

                return $revision;
            }
        );

        $this->events->dispatch(new ItemUpdated($galleryId, $itemId, $revision->value));

        return new ItemSettingsResult($values ?? [], $revision);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function validateSettings(Gallery $gallery, array $settings): array
    {
        $schema = $this->layouts->resolve($gallery->settings->layout)->layout->getSettingsSchema();
        $itemSchema = $schema[self::ITEM_SCHEMA_KEY] ?? null;
        if (!is_array($itemSchema)) {
            return $settings;
        }

        $validation = $this->validator->validate($itemSchema, $settings);
        if (!$validation->isValid()) {
            throw new ValidationFailedException($validation->errors);
        }

        return $validation->values;
    }

    private function authorize(Actor $actor, int $galleryId): Gallery
    {
        $gallery = $this->galleries->find($galleryId) ?? throw new GalleryNotFoundException();
        if (!$this->access->canEdit($actor, $gallery)) {
            throw new AccessDeniedException();
        }
        // A trashed gallery is read-only until it is restored (same rule as updating the gallery itself).
        if ($gallery->status === GalleryStatus::Trash) {
            throw new InvalidStateException();
        }

        return $gallery;
    }

    private function requireItem(int $galleryId, int $itemId): void
    {
        if (!in_array($itemId, $this->items->orderedIds($galleryId), true)) {
            throw new ItemNotFoundException();
        }
    }
}
