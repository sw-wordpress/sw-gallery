<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\WordPress\Infrastructure\Services;

/**
 * Helpers of the item and order REST tests: seeding items through the core service and reading the stored state.
 */
trait RestItemFixtures
{
    use RestFixtures;

    /**
     * Adds $count fresh image attachments to the gallery through the service (as its owner) and returns their ids.
     *
     * @return list<int> Attachment ids in assignment order.
     */
    protected function seedItems(int $gallery, int $owner, int $count): array
    {
        $attachments = [];
        for ($index = 0; $index < $count; ++$index) {
            $attachments[] = $this->makeAttachment($owner, 'image/jpeg', ['post_excerpt' => 'Caption ' . $index]);
        }
        $actor = new Actor($owner);
        Services::item_service()->assign($actor, $gallery, $attachments, new Revision($this->revisionOf($gallery)));

        return $attachments;
    }

    /** @return list<int> Item ids in stored order. */
    protected function itemIds(int $gallery): array
    {
        return Services::items()->orderedIds($gallery);
    }

    protected function revisionOf(int $gallery): int
    {
        return Services::revisions()->current($gallery)->value;
    }
}
