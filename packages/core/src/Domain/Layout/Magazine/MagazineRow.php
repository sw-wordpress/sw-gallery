<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout\Magazine;

/** One planned row: a template and the consecutive items (`start`, `count`) it shows. */
final class MagazineRow
{
    public function __construct(
        public readonly RowTemplate $template,
        public readonly int $start,
        public readonly int $count,
    ) {
    }
}
