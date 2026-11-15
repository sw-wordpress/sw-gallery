<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWGallery\Core\Domain\Layout\Jigsaw\JigsawEdges;
use SWGallery\Core\Domain\Layout\Jigsaw\JigsawOutline;

final class JigsawEdgesTest extends TestCase
{
    public function testNeighboursInterlockAndTheBoundaryIsFlat(): void
    {
        foreach ([1, 7, 123456] as $seed) {
            for ($columns = 1; $columns <= 10; ++$columns) {
                for ($count = 1; $count <= 60; ++$count) {
                    $codes = JigsawEdges::codes($count, $columns, $seed);
                    self::assertCount($count, $codes);
                    foreach ($codes as $index => $code) {
                        self::assertGreaterThanOrEqual(0, $code);
                        self::assertLessThan(JigsawEdges::CODES, $code);
                        [$top, $right, $bottom, $left] = JigsawEdges::sides($code);
                        $column = $index % $columns;
                        if ($column === $columns - 1 || $index + 1 >= $count) {
                            self::assertSame(JigsawEdges::FLAT, $right, "right of $index ($count/$columns)");
                        } else {
                            [, , , $nextLeft] = JigsawEdges::sides($codes[$index + 1]);
                            self::assertSame(3, $right + $nextLeft, 'tab meets blank');
                            self::assertNotSame(JigsawEdges::FLAT, $right);
                        }
                        if ($column === 0) {
                            self::assertSame(JigsawEdges::FLAT, $left);
                        }
                        if ($index < $columns) {
                            self::assertSame(JigsawEdges::FLAT, $top);
                        }
                        if ($index + $columns >= $count) {
                            self::assertSame(JigsawEdges::FLAT, $bottom);
                        } else {
                            [$belowTop] = JigsawEdges::sides($codes[$index + $columns]);
                            self::assertSame(3, $bottom + $belowTop);
                        }
                    }
                }
            }
        }
    }

    public function testSingleRowOrColumnAndEmpty(): void
    {
        self::assertSame([], JigsawEdges::codes(0, 4, 1));
        self::assertSame([0], JigsawEdges::codes(1, 4, 1));
        self::assertSame(JigsawEdges::codes(5, 0, 3), JigsawEdges::codes(5, 1, 3), 'columns below 1 count as 1');
    }

    public function testDeterministicAndTheSeedChangesTheEdges(): void
    {
        self::assertSame(JigsawEdges::codes(20, 4, 9), JigsawEdges::codes(20, 4, 9));
        $different = 0;
        for ($seed = 10; $seed < 20; ++$seed) {
            $different += JigsawEdges::codes(20, 4, $seed) !== JigsawEdges::codes(20, 4, 9) ? 1 : 0;
        }
        self::assertGreaterThanOrEqual(9, $different);
    }

    public function testTabsAndBlanksAreBothUsed(): void
    {
        $outs = 0;
        $ins = 0;
        foreach (JigsawEdges::codes(60, 5, 42) as $code) {
            foreach (JigsawEdges::sides($code) as $side) {
                $outs += JigsawEdges::OUT === $side ? 1 : 0;
                $ins += JigsawEdges::IN === $side ? 1 : 0;
            }
        }
        self::assertSame($outs, $ins);
        self::assertGreaterThan(40, $outs);
        self::assertSame([0, 0, 0, 0], JigsawEdges::sides(-5));
        self::assertSame([2, 2, 2, 2], JigsawEdges::sides(500));
    }

    public function testOutlineOfAFlatPieceIsTheSlot(): void
    {
        $path = JigsawOutline::path(0, 0.2, 1.0);
        self::assertSame('M0.1429 0.1429L0.8571 0.1429L0.8571 0.8571L0.1429 0.8571L0.1429 0.1429Z', $path);
    }

    public function testOutlineHasOneKnobPerTabOrBlankInsideTheBox(): void
    {
        $all = JigsawEdges::codes(60, 4, 5);
        foreach (array_unique($all) as $code) {
            $path = JigsawOutline::path($code, 0.3, 4 / 3);
            $knobs = count(array_filter(JigsawEdges::sides($code), static fn (int $s): bool => JigsawEdges::FLAT !== $s));
            self::assertSame($knobs * 6, substr_count($path, 'C'));
            self::assertStringStartsWith('M', $path);
            self::assertStringEndsWith('Z', $path);
            preg_match_all('/-?\d+(?:\.\d+)?/', $path, $numbers);
            foreach ($numbers[0] as $number) {
                self::assertGreaterThanOrEqual(0.0, (float) $number);
                self::assertLessThanOrEqual(1.0, (float) $number);
            }
        }
    }

    public function testATabReachesTheBoxEdgeAndABlankStaysInside(): void
    {
        $tab = JigsawOutline::path(1 * 3, 0.25, 1.0); // right side out
        self::assertStringContainsString(' 1', $tab, 'the tab tip touches the right edge of the box');
        preg_match_all('/(\d(?:\.\d+)?) /', $tab, $xs);
        self::assertContains('1', $xs[1]);
        $blank = JigsawOutline::path(2 * 3, 0.25, 1.0); // right side in
        preg_match_all('/(\d(?:\.\d+)?) /', $blank, $blankXs);
        self::assertNotContains('1', $blankXs[1], 'a blank never reaches the box edge');
        $depth = 0.25;
        $slotRight = (1.0 + $depth) / (1.0 + 2 * $depth);
        $max = max(array_map('floatval', $blankXs[1]));
        self::assertEqualsWithDelta($slotRight, $max, 0.0001, 'a blank starts at the slot edge');
    }
}
