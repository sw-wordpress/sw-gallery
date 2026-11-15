<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout\Magazine;

/** The catalogue of row templates, in a fixed order (the order is part of the deterministic tie-breaking). */
final class RowTemplates
{
    /** @var array<string, RowTemplate>|null */
    private static ?array $all = null;

    /** @return array<string, RowTemplate> by id */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }
        $calm = RowTemplate::GROUP_CALM;
        $bold = RowTemplate::GROUP_BOLD;
        $list = [
            // One picture: closing plates and panoramas.
            new RowTemplate('full', [1], [1], [['a']], 1.875, $bold),
            new RowTemplate('panorama', [1], [1], [['a']], 1.25, $bold),
            new RowTemplate('solo', [1, 2, 1], [1], [['.', 'a', '.']], 2.25, $calm, true),
            // Two pictures.
            new RowTemplate('duo', [1, 1], [1], [['a', 'b']], 1.25, $calm),
            new RowTemplate('duo-split', [2, 1], [1], [['a', 'b']], 1.5, $calm),
            new RowTemplate('duo-split-r', [1, 2], [1], [['a', 'b']], 1.5, $calm),
            new RowTemplate('portrait-pair', [1, 1], [1], [['a', 'b']], 2.2, $calm),
            // Three pictures.
            new RowTemplate('triptych', [1, 1, 1], [1], [['a', 'b', 'c']], 1.0, $calm),
            new RowTemplate('portrait-trio', [1, 1, 1], [1], [['a', 'b', 'c']], 1.67, $calm),
            new RowTemplate('hero-left', [2, 1], [1, 1], [['a', 'b'], ['a', 'c']], 1.67, $bold),
            new RowTemplate('hero-right', [1, 2], [1, 1], [['a', 'b'], ['c', 'b']], 1.67, $bold),
            new RowTemplate('tall-left', [4, 5], [1, 1], [['a', 'b'], ['a', 'c']], 2.2, $bold),
            new RowTemplate('tall-right', [5, 4], [1, 1], [['a', 'b'], ['c', 'b']], 2.2, $bold),
            // Four and five pictures.
            new RowTemplate('quartet', [1, 1, 1, 1], [1], [['a', 'b', 'c', 'd']], 1.25, $calm),
            new RowTemplate('banner-three', [1, 1, 1], [3, 2], [['a', 'a', 'a'], ['b', 'c', 'd']], 2.5, $bold),
            new RowTemplate('hero-four', [2, 1, 1], [1, 1], [['a', 'b', 'b'], ['a', 'c', 'd']], 1.875, $bold),
            new RowTemplate('hero-five', [2, 1, 1], [1, 1], [['a', 'b', 'c'], ['a', 'd', 'e']], 1.875, $bold),
            new RowTemplate('hero-five-right', [1, 1, 2], [1, 1], [['a', 'b', 'c'], ['d', 'e', 'c']], 1.875, $bold),
        ];
        $all = [];
        foreach ($list as $template) {
            $all[$template->id] = $template;
        }

        return self::$all = $all;
    }

    public static function get(string $id): RowTemplate
    {
        return self::all()[$id] ?? throw new \InvalidArgumentException('Unknown row template.');
    }

    public static function exists(string $id): bool
    {
        return isset(self::all()[$id]);
    }
}
