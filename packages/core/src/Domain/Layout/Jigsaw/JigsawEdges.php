<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout\Jigsaw;

/**
 * Edge codes of a jigsaw grid (L-04): which side of every piece carries a tab, a blank or is flat.
 *
 * A piece's code is `top + 3 * right + 9 * bottom + 27 * left` with every side one of {@see self::FLAT}, {@see self::OUT}
 * (a tab pointing out of the piece) or {@see self::IN} (a blank taking the neighbour's tab); sides are named for a left
 * to right grid, a right to left page mirrors the finished piece. The edge between two neighbours is decided by one
 * seeded bit: one piece gets the tab, the other the blank, so neighbours always interlock. The outer edges of the grid
 * (and the edges towards missing items in the last row) are flat. Pure and deterministic: same seed, same codes.
 */
final class JigsawEdges
{
    public const FLAT = 0;
    public const OUT = 1;
    public const IN = 2;

    /** Number of distinct codes (3^4). */
    public const CODES = 81;

    /**
     * @param int $count   Number of pieces (row-major order).
     * @param int $columns Pieces per row (at least 1).
     * @param int $seed    Seed; the layout derives it from gallery, revision and its `variation` setting.
     * @return list<int> Code per piece, 0..80.
     */
    public static function codes(int $count, int $columns, int $seed): array
    {
        $columns = max(1, $columns);
        $sides = array_fill(0, max(0, $count), [self::FLAT, self::FLAT, self::FLAT, self::FLAT]);
        for ($index = 0; $index < $count; ++$index) {
            // [top, right, bottom, left]
            if ($index % $columns < $columns - 1 && $index + 1 < $count) {
                $out = self::bit($seed, 'r', $index);
                $sides[$index][1] = $out ? self::OUT : self::IN;
                $sides[$index + 1][3] = $out ? self::IN : self::OUT;
            }
            if ($index + $columns < $count) {
                $out = self::bit($seed, 'b', $index);
                $sides[$index][2] = $out ? self::OUT : self::IN;
                $sides[$index + $columns][0] = $out ? self::IN : self::OUT;
            }
        }
        return array_map(static fn (array $s): int => $s[0] + 3 * $s[1] + 9 * $s[2] + 27 * $s[3], $sides);
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int} top, right, bottom, left.
     */
    public static function sides(int $code): array
    {
        $code = max(0, min(self::CODES - 1, $code));
        return [$code % 3, intdiv($code, 3) % 3, intdiv($code, 9) % 3, intdiv($code, 27) % 3];
    }

    private static function bit(int $seed, string $kind, int $index): bool
    {
        return 1 === (hexdec(substr(hash('sha256', sprintf('swg-jigsaw:%d:%s:%d', $seed, $kind, $index)), 0, 2)) & 1);
    }
}
