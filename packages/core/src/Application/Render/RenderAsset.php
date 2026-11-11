<?php

declare(strict_types=1);

namespace SWGallery\Core\Application\Render;

/** A frontend asset group the rendered output needs; the adapter enqueues it (never the pipeline). */
enum RenderAsset: string
{
    case Lightbox = 'lightbox';
}
