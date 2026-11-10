<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

use PHPUnit\Framework\TestCase;
use SWGallery\Core\Contract\MediaReferenceRepositoryInterface;

/**
 * Behaviour every MediaReferenceRepositoryInterface implementation must show. Implement the factory methods in the unit
 * suite (fake) and in the WordPress integration suite (real attachments).
 */
abstract class MediaReferenceRepositoryContract extends TestCase
{
    abstract protected function repository(): MediaReferenceRepositoryInterface;

    /** Creates a usable image and returns its id. */
    abstract protected function seedImage(
        string $title,
        string $caption,
        string $alt,
        string $mime,
        int $width,
        int $height
    ): int;

    /** Creates an attachment that is not a usable gallery image (PDF) and returns its id. */
    abstract protected function seedNonImage(): int;

    public function testReferencesAreKeyedByIdAndCarryTheGlobalMetadata(): void
    {
        $a = $this->seedImage('Title A', 'Caption A', 'Alt A', 'image/jpeg', 640, 480);
        $b = $this->seedImage('Title B', '', '', 'image/png', 0, 0);

        $found = $this->repository()->findReferences([$b, $a]);

        self::assertEqualsCanonicalizing([$a, $b], array_keys($found));
        self::assertSame($a, $found[$a]->attachmentId);
        self::assertSame('Title A', $found[$a]->title);
        self::assertSame('Caption A', $found[$a]->caption);
        self::assertSame('Alt A', $found[$a]->alt);
        self::assertSame('image/jpeg', $found[$a]->mimeType);
        self::assertSame([640, 480], [$found[$a]->width, $found[$a]->height]);
        self::assertSame(['image/png', 0, 0], [$found[$b]->mimeType, $found[$b]->width, $found[$b]->height]);
        self::assertFalse($found[$b]->hasDimensions());
    }

    public function testMissingNonImageAndInvalidIdsAreOmittedAndDuplicatesCollapse(): void
    {
        $image = $this->seedImage('T', '', '', 'image/gif', 10, 10);
        $pdf = $this->seedNonImage();

        $found = $this->repository()->findReferences([$image, $image, $pdf, 999999, 0, -4]);

        self::assertSame([$image], array_keys($found));
        self::assertSame([], $this->repository()->findReferences([]));
    }
}
