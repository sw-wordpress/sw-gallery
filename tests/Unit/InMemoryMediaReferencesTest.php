<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use SWGallery\Core\Contract\MediaReferenceRepositoryInterface;
use SWGallery\Core\Domain\Media\MediaReference;
use SWGallery\Tests\Support\InMemoryMediaReferences;
use SWGallery\Tests\Support\MediaReferenceRepositoryContract;

final class InMemoryMediaReferencesTest extends MediaReferenceRepositoryContract
{
    private InMemoryMediaReferences $fake;
    private int $next = 1;

    protected function setUp(): void
    {
        $this->fake = new InMemoryMediaReferences();
    }

    protected function repository(): MediaReferenceRepositoryInterface
    {
        return $this->fake;
    }

    protected function seedImage(
        string $title,
        string $caption,
        string $alt,
        string $mime,
        int $width,
        int $height
    ): int {
        $id = $this->next++;
        $this->fake->add(new MediaReference($id, $title, $caption, $alt, $mime, $width, $height));

        return $id;
    }

    protected function seedNonImage(): int
    {
        return $this->seedImage('Doc', '', '', 'application/pdf', 0, 0);
    }

    public function testCallsAreCounted(): void
    {
        $this->fake->findReferences([1]);
        $this->fake->findReferences([]);

        self::assertSame(2, $this->fake->calls());
    }
}
