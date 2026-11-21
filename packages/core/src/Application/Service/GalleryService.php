<?php

declare(strict_types=1);

namespace SWGallery\Core\Application\Service;

use SWGallery\Core\Contract\GalleryAccessPolicyInterface;
use SWGallery\Core\Contract\GalleryRepositoryInterface;
use SWGallery\Core\Contract\LayoutRegistryInterface;
use SWGallery\Core\Contract\RevisionStoreInterface;
use SWGallery\Core\Contract\TransactionRunnerInterface;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\Gallery;
use SWGallery\Core\Domain\Gallery\GalleryResult;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\HoverEffect;
use SWGallery\Core\Domain\Gallery\NewGallery;
use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\Core\Domain\Settings\SettingsSchemaValidator;
use SWGallery\Core\Event\AbstractVetoableEvent;
use SWGallery\Core\Event\BeforeGalleryDeleting;
use SWGallery\Core\Event\BeforeGalleryTrashing;
use SWGallery\Core\Event\EventDispatcherInterface;
use SWGallery\Core\Event\GalleryCreated;
use SWGallery\Core\Event\GalleryDeleted;
use SWGallery\Core\Event\GalleryDuplicated;
use SWGallery\Core\Event\GalleryRestored;
use SWGallery\Core\Event\GalleryTrashed;
use SWGallery\Core\Event\GalleryUpdated;
use SWGallery\Core\Exception\AccessDeniedException;
use SWGallery\Core\Exception\GalleryNotFoundException;
use SWGallery\Core\Exception\InvalidOwnerException;
use SWGallery\Core\Exception\InvalidStateException;
use SWGallery\Core\Exception\OperationVetoedException;
use SWGallery\Core\Exception\ValidationFailedException;

/**
 * Gallery lifecycle use cases. The service enforces the access policy itself and works in a fixed order:
 * authorize, validate, ask before_* listeners, then one transaction that writes and claims the revision
 * (compare-and-increment against the client's expected revision), then events. Trashing and deleting only
 * touch gallery definitions and relations, never attachments.
 *
 * The revision of a new gallery is 1; a copy starts at revision 1 as well.
 */
final class GalleryService
{
    public function __construct(
        private readonly GalleryRepositoryInterface $galleries,
        private readonly RevisionStoreInterface $revisions,
        private readonly TransactionRunnerInterface $transactions,
        private readonly GalleryAccessPolicyInterface $access,
        private readonly EventDispatcherInterface $events,
        private readonly LayoutRegistryInterface $layouts,
        private readonly SettingsSchemaValidator $validator,
    ) {
    }

    /**
     * Creates a gallery owned by the actor (the owner of $new is ignored, so ownership cannot be spoofed).
     *
     * @throws AccessDeniedException
     * @throws ValidationFailedException
     */
    public function create(Actor $actor, NewGallery $new): GalleryResult
    {
        if (!$this->access->canCreate($actor)) {
            throw new AccessDeniedException();
        }
        $this->validateSettings($new->settings);
        $owned = new NewGallery($new->title, $actor->userId, $new->description, $new->status, $new->settings);

        $result = $this->transactions->run(function () use ($owned): GalleryResult {
            $gallery = $this->galleries->create($owned);

            return new GalleryResult($gallery, $this->revisions->compareAndIncrement($gallery->id, new Revision(0)));
        });

        $this->events->dispatch(
            new GalleryCreated($result->gallery->id, $result->gallery->ownerId, $result->revision->value)
        );

        return $result;
    }

    /**
     * Saves title, description, status, cover and settings of an existing gallery. Trash and restore have their
     * own use cases. Changing the owner additionally needs canChangeOwner() (403) and a new owner who may own
     * galleries (422).
     *
     * @throws GalleryNotFoundException
     * @throws AccessDeniedException
     * @throws InvalidOwnerException       When the new owner does not exist or may not own galleries.
     * @throws InvalidStateException       When the stored gallery is trashed or the new status is trash.
     * @throws ValidationFailedException
     * @throws \SWGallery\Core\Exception\RevisionConflictException
     */
    public function update(Actor $actor, Gallery $changed, Revision $expected): GalleryResult
    {
        $current = $this->require($changed->id);
        if (!$this->access->canEdit($actor, $current)) {
            throw new AccessDeniedException();
        }
        if ($changed->ownerId !== $current->ownerId) {
            if (!$this->access->canChangeOwner($actor, $current)) {
                throw new AccessDeniedException();
            }
            if (!$this->access->canOwnGalleries($changed->ownerId)) {
                throw new InvalidOwnerException();
            }
        }
        if ($current->status === GalleryStatus::Trash || $changed->status === GalleryStatus::Trash) {
            throw new InvalidStateException();
        }
        if ($this->settingsDiffer($current->settings, $changed->settings)) {
            $this->validateSettings($changed->settings);
        }

        $revision = $this->transactions->run(function () use ($changed, $expected): Revision {
            $revision = $this->revisions->compareAndIncrement($changed->id, $expected);
            $this->galleries->update($changed);

            return $revision;
        });

        $this->events->dispatch(new GalleryUpdated($changed->id, $revision->value));

        return new GalleryResult($this->require($changed->id), $revision);
    }

    /**
     * Copies definition, settings, cover, membership and overrides into a new draft owned by the actor.
     * The source is not modified, so it keeps its revision; media files are not copied.
     *
     * @throws GalleryNotFoundException
     * @throws AccessDeniedException
     */
    public function duplicate(Actor $actor, int $sourceId, string $title): GalleryResult
    {
        $source = $this->require($sourceId);
        if (!$this->access->canCreate($actor) || !$this->access->canView($actor, $source)) {
            throw new AccessDeniedException();
        }

        $result = $this->transactions->run(function () use ($sourceId, $title, $actor): GalleryResult {
            $copy = $this->galleries->duplicate($sourceId, $title, $actor->userId);

            return new GalleryResult($copy, $this->revisions->compareAndIncrement($copy->id, new Revision(0)));
        });

        $this->events->dispatch(
            new GalleryDuplicated($result->gallery->id, $sourceId, $actor->userId, $result->revision->value)
        );

        return $result;
    }

    /**
     * @throws GalleryNotFoundException
     * @throws AccessDeniedException
     * @throws InvalidStateException      When the gallery is already trashed.
     * @throws OperationVetoedException
     * @throws \SWGallery\Core\Exception\RevisionConflictException
     */
    public function trash(Actor $actor, int $galleryId, Revision $expected): Revision
    {
        $gallery = $this->authorizeDelete($actor, $galleryId);
        if ($gallery->status === GalleryStatus::Trash) {
            throw new InvalidStateException();
        }
        $this->guard(new BeforeGalleryTrashing($galleryId, $actor->userId));

        $revision = $this->transactions->run(function () use ($galleryId, $expected): Revision {
            $revision = $this->revisions->compareAndIncrement($galleryId, $expected);
            $this->galleries->trash($galleryId);

            return $revision;
        });

        $this->events->dispatch(new GalleryTrashed($galleryId, $revision->value));

        return $revision;
    }

    /**
     * Restores a trashed gallery as a draft.
     *
     * @throws GalleryNotFoundException
     * @throws AccessDeniedException
     * @throws InvalidStateException      When the gallery is not trashed.
     * @throws \SWGallery\Core\Exception\RevisionConflictException
     */
    public function restore(Actor $actor, int $galleryId, Revision $expected): GalleryResult
    {
        $gallery = $this->authorizeDelete($actor, $galleryId);
        if ($gallery->status !== GalleryStatus::Trash) {
            throw new InvalidStateException();
        }

        $result = $this->transactions->run(function () use ($galleryId, $expected): GalleryResult {
            $revision = $this->revisions->compareAndIncrement($galleryId, $expected);

            return new GalleryResult($this->galleries->restore($galleryId), $revision);
        });

        $this->events->dispatch(new GalleryRestored($galleryId, $result->revision->value));

        return $result;
    }

    /**
     * Permanently deletes the gallery and its item relations. Attachments stay untouched.
     *
     * @throws GalleryNotFoundException
     * @throws AccessDeniedException
     * @throws OperationVetoedException
     * @throws \SWGallery\Core\Exception\RevisionConflictException
     */
    public function delete(Actor $actor, int $galleryId, Revision $expected): void
    {
        $this->authorizeDelete($actor, $galleryId);
        $this->guard(new BeforeGalleryDeleting($galleryId, $actor->userId));

        $revision = $this->transactions->run(function () use ($galleryId, $expected): Revision {
            $revision = $this->revisions->compareAndIncrement($galleryId, $expected);
            $this->galleries->delete($galleryId);

            return $revision;
        });

        $this->events->dispatch(new GalleryDeleted($galleryId, $revision->value));
    }

    private function require(int $galleryId): Gallery
    {
        return $this->galleries->find($galleryId) ?? throw new GalleryNotFoundException();
    }

    private function authorizeDelete(Actor $actor, int $galleryId): Gallery
    {
        $gallery = $this->require($galleryId);
        if (!$this->access->canDelete($actor, $gallery)) {
            throw new AccessDeniedException();
        }

        return $gallery;
    }

    private function guard(AbstractVetoableEvent $event): void
    {
        if (!$this->events->dispatchBefore($event)) {
            throw new OperationVetoedException($event->vetoCode() ?? AbstractVetoableEvent::DEFAULT_VETO_CODE);
        }
    }

    private static function sameHover(?HoverEffect $a, ?HoverEffect $b): bool
    {
        return $a === null ? $b === null : $a->equals($b);
    }

    private function settingsDiffer(GallerySettings $a, GallerySettings $b): bool
    {
        return $a->layout !== $b->layout
            || $a->sortMode !== $b->sortMode
            || $a->perPage !== $b->perPage
            || !self::sameHover($a->hover, $b->hover)
            || $a->values !== $b->values;
    }

    /**
     * @throws ValidationFailedException
     */
    private function validateSettings(GallerySettings $settings): void
    {
        $layout = $this->layouts->get($settings->layout);
        $schema = $layout->getSettingsSchema();
        unset($schema[GalleryItemEditService::ITEM_SCHEMA_KEY]);
        $result = $this->validator->validate($schema, $settings->values);
        if (!$result->isValid()) {
            throw new ValidationFailedException($result->errors);
        }
    }
}
