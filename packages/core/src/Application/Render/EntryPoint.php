<?php

declare(strict_types=1);

namespace SWGallery\Core\Application\Render;

/** The surface a render request comes from. Every entry point shares one pipeline and one access decision. */
enum EntryPoint: string
{
    case Block = 'block';
    case Shortcode = 'shortcode';
    case PhpApi = 'php_api';
    case Preview = 'preview';
    /** Feeds, excerpts and REST `content.rendered`-like contexts: simplified markup without CSS/JS dependency. */
    case Feed = 'feed';

    /** Whether this entry point needs the interactive markup contract (data attributes, lightbox). */
    public function isInteractive(): bool
    {
        // phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- false positive: `$this` inside an enum method.
        return $this !== self::Feed;
    }
}
