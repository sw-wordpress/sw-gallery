<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout\Magazine;

/**
 * One row pattern: a CSS grid (column and row weights in `fr`, named areas) whose areas are the slots of the row.
 *
 * Slots are named `a`, `b`, ... in reading order (first cell of each area, row by row), so the n-th consumed item
 * always lands in the n-th slot and the stored order is the visual reading order. Every area is a rectangle; a row
 * has no holes, except the `centred` template whose side cells (`.`) are deliberate margins around one slot.
 * The stylesheet repeats exactly the declarations of {@see css()} (a unit test compares both).
 */
final class RowTemplate
{
    /** Width of the nominal row in pixels (the design reference of the scoring). */
    public const REFERENCE_WIDTH = 1200.0;

    /** Height in pixels of a row with `heightFactor` 1 (the default `rowHeight` setting). */
    public const BASE_HEIGHT = 320.0;

    public const MAX_SLOTS = 5;

    public const GROUP_CALM = 'calm';
    public const GROUP_BOLD = 'bold';

    private const LETTERS = ['a', 'b', 'c', 'd', 'e'];

    /** @var list<string> */
    public readonly array $slots;

    /** @var array<string, array{0: int, 1: int, 2: int, 3: int}> column start, column end, row start, row end (0-based, end exclusive) */
    private array $rects = [];

    /**
     * @param list<int> $columns Column weights (fr).
     * @param list<int> $rows Row weights (fr).
     * @param list<list<string>> $areas One list of area names per grid row, `.` for a margin cell.
     * @param float $heightFactor Row height relative to the base height.
     * @param string $group GROUP_CALM or GROUP_BOLD (rhythm bias).
     */
    public function __construct(
        public readonly string $id,
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $areas,
        public readonly float $heightFactor,
        public readonly string $group,
        public readonly bool $centred = false,
    ) {
        if (preg_match('/^[a-z]+(-[a-z]+)*$/', $id) !== 1 || $heightFactor <= 0.0) {
            throw new \InvalidArgumentException('Invalid row template.');
        }
        if (!in_array($group, [self::GROUP_CALM, self::GROUP_BOLD], true) || count($areas) !== count($rows)) {
            throw new \InvalidArgumentException('Invalid row template.');
        }
        foreach ([...$columns, ...$rows] as $weight) {
            if ($weight < 1) {
                throw new \InvalidArgumentException('Invalid row template.');
            }
        }
        $this->slots = $this->scan();
    }

    /** @return list<string> */
    private function scan(): array
    {
        $cells = [];
        $order = [];
        foreach ($this->areas as $r => $line) {
            if (count($line) !== count($this->columns)) {
                throw new \InvalidArgumentException('Invalid row template.');
            }
            foreach ($line as $c => $name) {
                if ($name === '.') {
                    if (!$this->centred) {
                        throw new \InvalidArgumentException('Holes are only allowed in a centred template.');
                    }
                    continue;
                }
                if (!in_array($name, self::LETTERS, true)) {
                    throw new \InvalidArgumentException('Invalid area name.');
                }
                $cells[$name][] = [$c, $r];
                if (!in_array($name, $order, true)) {
                    $order[] = $name;
                }
            }
        }
        if ($order === [] || $order !== array_slice(self::LETTERS, 0, count($order))) {
            throw new \InvalidArgumentException('Areas must be a, b, c ... in reading order.');
        }
        foreach ($cells as $name => $list) {
            $columns = array_column($list, 0);
            $rowsOf = array_column($list, 1);
            $rect = [min($columns), max($columns) + 1, min($rowsOf), max($rowsOf) + 1];
            if (count($list) !== ($rect[1] - $rect[0]) * ($rect[3] - $rect[2])) {
                throw new \InvalidArgumentException('Every area must be a rectangle.');
            }
            $this->rects[$name] = $rect;
        }

        return $order;
    }

    public function slotCount(): int
    {
        return count($this->slots);
    }

    /** Row height in pixels at the reference width and the default row height. */
    public function referenceHeight(): float
    {
        return self::BASE_HEIGHT * $this->heightFactor;
    }

    /** Aspect ratio (width / height) of the row at the reference size, gaps ignored. */
    public function rowAspect(): float
    {
        return self::REFERENCE_WIDTH / $this->referenceHeight();
    }

    /** Width / height of one slot (0-based) at the reference size, gaps ignored. */
    public function slotAspect(int $slot): float
    {
        [$c0, $c1, $r0, $r1] = $this->rects[$this->slots[$slot]];
        $width = array_sum(array_slice($this->columns, $c0, $c1 - $c0)) / array_sum($this->columns) * self::REFERENCE_WIDTH;
        $height = array_sum(array_slice($this->rows, $r0, $r1 - $r0)) / array_sum($this->rows) * $this->referenceHeight();

        return $width / $height;
    }

    /** Share (0..1) of the row width the widest slot spans. */
    public function widestSlotShare(): float
    {
        $widest = 0.0;
        foreach ($this->rects as [$c0, $c1]) {
            $widest = max($widest, array_sum(array_slice($this->columns, $c0, $c1 - $c0)) / array_sum($this->columns));
        }

        return $widest;
    }

    /** The declarations the stylesheet has to contain for this template (compared by a unit test). */
    public function css(): string
    {
        $areas = implode(' ', array_map(static fn (array $line): string => '"' . implode(' ', $line) . '"', $this->areas));
        $track = static fn (int $weight): string => 'minmax(0, ' . $weight . 'fr)';

        return sprintf(
            'grid-template-columns: %s; grid-template-rows: %s; grid-template-areas: %s; --swg-hf: %s;',
            implode(' ', array_map($track, $this->columns)),
            implode(' ', array_map($track, $this->rows)),
            $areas,
            rtrim(rtrim(sprintf('%.3F', $this->heightFactor), '0'), '.')
        );
    }
}
