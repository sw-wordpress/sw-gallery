<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout\Magazine;

/** The rows of a page in order; together they cover every item exactly once. */
final class MagazinePlan
{
    /** @param list<MagazineRow> $rows */
    public function __construct(public readonly array $rows)
    {
    }

    /** @return list<string> template ids in row order */
    public function templateIds(): array
    {
        return array_map(static fn (MagazineRow $row): string => $row->template->id, $this->rows);
    }
}
