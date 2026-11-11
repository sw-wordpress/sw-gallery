<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

use SWGallery\Core\Contract\ThumbnailResolverInterface;
use SWGallery\Core\Domain\Layout\ImageRequest;
use SWGallery\Core\Domain\Layout\ResolvedImage;

/** Thumbnail resolver double: a predictable URL per attachment and role; null for the listed attachments. */
final class FakeThumbnailResolver implements ThumbnailResolverInterface
{
    /** @var list<ImageRequest> */
    public array $requests = [];

    /**
     * @param list<int> $unusable
     */
    public function __construct(private readonly array $unusable = [])
    {
    }

    public function resolve(ImageRequest $request): ?ResolvedImage
    {
        $this->requests[] = $request;
        if (in_array($request->attachmentId, $this->unusable, true)) {
            return null;
        }

        return new ResolvedImage(
            sprintf('https://example.test/%d-%s.jpg', $request->attachmentId, $request->role->value),
            $request->displayWidth,
            $request->displayWidth / 2 >= 1 ? intdiv($request->displayWidth, 2) : 1
        );
    }
}
