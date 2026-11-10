<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Access;

/** What an embed (block, shortcode, PHP API, paging) may output for a gallery. */
enum RenderAccess
{
    /** Render the gallery. */
    case Render;
    /** Show the password form instead of the gallery. */
    case PasswordForm;
    /** Render nothing for visitors, but show a short diagnostic to users who can edit the gallery. */
    case Diagnostic;
    /** Render nothing. */
    case Nothing;
}
