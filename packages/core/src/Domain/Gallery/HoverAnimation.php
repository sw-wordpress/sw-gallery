<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Gallery;

/**
 * How the hover texts of an image fade in: a bar sliding in from the bottom or from the top, a full-surface overlay,
 * or a plain fade of the bottom bar.
 */
enum HoverAnimation: string
{
    case SlideUp = 'slide-up';
    case SlideDown = 'slide-down';
    case Cover = 'cover';
    case Fade = 'fade';
}
