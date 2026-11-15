<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout\Jigsaw;

/**
 * The outline of one jigsaw piece as an SVG path in `objectBoundingBox` units (0..1 of the piece box).
 *
 * The piece box is the grid slot plus the tab depth on every side. All lengths are measured in slot widths: the slot is
 * 1 wide and `1 / ratio` high, a tab sticks out by `depth` (same on all four sides, so box width = 1 + 2 * depth and
 * box height = 1 / ratio + 2 * depth). Tabs and blanks are the classic round knob drawn with cubic Béziers; a blank is
 * the same curve pushed inwards, so the tab of one piece fits the blank of its neighbour exactly.
 */
final class JigsawOutline
{
    /**
     * Knob profile along an edge: points are (u along the edge in 0..1, v outwards in fractions of the depth). Each
     * entry is three points (two control points and the end point) of a cubic curve; the straight lead in/out is
     * added by {@see path()}.
     */
    private const KNOB = [
        [[0.41, 0.0], [0.43, 0.12], [0.42, 0.30]],
        [[0.41, 0.45], [0.33, 0.50], [0.33, 0.72]],
        [[0.33, 0.95], [0.42, 1.0], [0.5, 1.0]],
        [[0.58, 1.0], [0.67, 0.95], [0.67, 0.72]],
        [[0.67, 0.50], [0.59, 0.45], [0.58, 0.30]],
        [[0.57, 0.12], [0.59, 0.0], [0.63, 0.0]],
    ];

    /** Start of the knob along the edge. */
    private const LEAD = 0.37;

    /**
     * @param int   $code  Edge code of {@see JigsawEdges}.
     * @param float $depth Tab depth in slot widths (e.g. 0.18).
     * @param float $ratio Slot width / height (e.g. 1.0, 4/3, 1.5).
     * @return string Path data (M, L, C, Z), absolute coordinates in 0..1.
     */
    public static function path(int $code, float $depth, float $ratio): string
    {
        $slotH = 1.0 / $ratio;
        $boxW = 1.0 + 2.0 * $depth;
        $boxH = $slotH + 2.0 * $depth;
        // start, direction, outward normal, length for top, right, bottom, left (clockwise on screen)
        $edges = [
            [[$depth, $depth], [1, 0], [0, -1], 1.0],
            [[$depth + 1.0, $depth], [0, 1], [1, 0], $slotH],
            [[$depth + 1.0, $depth + $slotH], [-1, 0], [0, 1], 1.0],
            [[$depth, $depth + $slotH], [0, -1], [-1, 0], $slotH],
        ];
        $sides = JigsawEdges::sides($code);
        $point = static function (array $edge, float $u, float $v) use ($boxW, $boxH, $depth): string {
            [$start, $dir, $normal, $length] = $edge;
            $x = $start[0] + $dir[0] * $u * $length + $normal[0] * $v * $depth;
            $y = $start[1] + $dir[1] * $u * $length + $normal[1] * $v * $depth;
            return self::number($x / $boxW) . ' ' . self::number($y / $boxH);
        };
        $path = 'M' . $point($edges[0], 0.0, 0.0);
        foreach ($edges as $index => $edge) {
            $side = $sides[$index];
            if (JigsawEdges::FLAT === $side) {
                $path .= 'L' . $point($edge, 1.0, 0.0);
                continue;
            }
            $sign = JigsawEdges::OUT === $side ? 1.0 : -1.0;
            $path .= 'L' . $point($edge, self::LEAD, 0.0);
            foreach (self::KNOB as $curve) {
                $path .= 'C';
                $parts = [];
                foreach ($curve as [$u, $v]) {
                    $parts[] = $point($edge, $u, $v * $sign);
                }
                $path .= implode(' ', $parts);
            }
            $path .= 'L' . $point($edge, 1.0, 0.0);
        }
        return $path . 'Z';
    }

    private static function number(float $value): string
    {
        $text = rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
        return '' === $text || '-0' === $text ? '0' : $text;
    }
}
