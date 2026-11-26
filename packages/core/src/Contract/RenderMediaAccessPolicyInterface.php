<?php

declare(strict_types=1);

namespace SWGallery\Core\Contract;

use SWGallery\Core\Application\Render\RenderContext;
use SWGallery\Core\Domain\Gallery\Gallery;

interface RenderMediaAccessPolicyInterface
{
    /**
     * @param list<int> $attachmentIds Attachments on the current render page.
     * @return list<int> Attachments whose media and metadata may be rendered in this context.
     */
    public function readableRenderAttachments(RenderContext $context, Gallery $gallery, array $attachmentIds): array;
}
