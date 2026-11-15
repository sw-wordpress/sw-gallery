<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout\Magazine;

use SWGallery\Core\Domain\Layout\Plan\PlanItem;

/**
 * Turns the ordered items of a page into rows without ever reordering them.
 *
 * Algorithm: every template consumes the next 1..5 items (its slots). The cost of a row is the sum over its slots of
 * |ln(slotAspect / itemAspect)| (how far the picture has to be cropped), plus penalties: repeating the previous
 * template, a "solo" plate, two very tall rows in a row, and the rhythm bias (the group the owner did not ask for).
 * A tiny seeded jitter (< 0.05) breaks ties differently per gallery and revision. The cheapest segmentation of the whole
 * page is found by dynamic programming over (position, previous template). Because the end of the page takes part in
 * the optimisation, the last row is never an accident: leftover pictures are absorbed by earlier choices, a lone
 * landscape closes the page as a full-width plate, a lone portrait or square as the centred `solo` plate.
 * Pure and deterministic: same items, seed and rhythm give the same rows.
 */
final class MagazinePlanner
{
    public const RHYTHM_BALANCED = 'balanced';
    public const RHYTHM_CALM = 'calm';
    public const RHYTHM_BOLD = 'bold';

    /** Added when a row uses the same template as the previous one (about two badly cropped pictures). */
    private const REPEAT_PENALTY = 0.6;

    /** Added to every one-picture row (a page of plates is dull; singles stay for panoramas and the odd leftover). */
    private const SINGLE_PENALTY = 0.3;

    /** Added to the centred single plate on top of the single penalty, so that it is chosen only when nothing else fits. */
    private const SOLO_PENALTY = 0.35;

    /** Added when two rows with a height factor of at least TALL_FROM follow each other. */
    private const TALL_PENALTY = 0.15;
    private const TALL_FROM = 2.0;

    /** Added to every row whose group is not the preferred one when the rhythm is not balanced. */
    private const RHYTHM_BIAS = 0.25;

    /** The closing row counts its crop twice: earlier rows give way until the last one fits (the look-behind rule). */
    private const LAST_ROW_WEIGHT = 1.5;

    /** Added when the closing row shows one picture only: a page should end on a row, not on a stray plate. */
    private const LAST_SINGLE_PENALTY = 0.4;

    private const JITTER = 0.05;

    /**
     * @param list<PlanItem> $items Items in stored order.
     * @param int $seed Plan seed (see PlanSeed).
     * @param string $rhythm One of the RHYTHM_* constants; anything else counts as balanced.
     */
    public function plan(array $items, int $seed, string $rhythm = self::RHYTHM_BALANCED): MagazinePlan
    {
        $count = count($items);
        if ($count === 0) {
            return new MagazinePlan([]);
        }
        $ratios = array_map(static fn (PlanItem $item): float => $item->aspectRatio, $items);
        $templates = array_values(RowTemplates::all());
        $total = count($templates);

        // best[i][p]: cheapest cost of rows for items i.. when the previous row used template p (index $total = none).
        $best = array_fill(0, $count + 1, array_fill(0, $total + 1, INF));
        $choice = array_fill(0, $count, array_fill(0, $total + 1, -1));
        $best[$count] = array_fill(0, $total + 1, 0.0);
        $base = [];
        for ($i = $count - 1; $i >= 0; --$i) {
            foreach ($templates as $t => $template) {
                $slots = $template->slotCount();
                if ($i + $slots > $count) {
                    continue;
                }
                $base[$t] = $this->baseCost($template, $ratios, $i, $seed, $rhythm);
            }
            for ($p = 0; $p <= $total; ++$p) {
                foreach ($templates as $t => $template) {
                    $slots = $template->slotCount();
                    if ($i + $slots > $count) {
                        continue;
                    }
                    $cost = $base[$t] + $best[$i + $slots][$t];
                    if ($p < $total) {
                        $cost += $this->transitionCost($templates[$p], $template);
                    }
                    if ($cost < $best[$i][$p]) {
                        $best[$i][$p] = $cost;
                        $choice[$i][$p] = $t;
                    }
                }
            }
        }

        $rows = [];
        $position = 0;
        $previous = $total;
        while ($position < $count) {
            $t = $choice[$position][$previous];
            $rows[] = new MagazineRow($templates[$t], $position, $templates[$t]->slotCount());
            $position += $templates[$t]->slotCount();
            $previous = $t;
        }

        return new MagazinePlan($rows);
    }

    /**
     * Mean |ln(slot / item)| aspect mismatch of a row (the crop measure used by tests and the quality report).
     *
     * @param list<float> $ratios Item aspect ratios.
     */
    public static function rowMismatch(RowTemplate $template, array $ratios, int $start): float
    {
        $sum = 0.0;
        $slots = $template->slotCount();
        for ($s = 0; $s < $slots; ++$s) {
            $sum += abs(log($template->slotAspect($s) / $ratios[$start + $s]));
        }

        return $sum / $slots;
    }

    /** @param list<float> $ratios */
    private function baseCost(RowTemplate $template, array $ratios, int $start, int $seed, string $rhythm): float
    {
        $slots = $template->slotCount();
        $weight = $start + $slots === count($ratios) ? self::LAST_ROW_WEIGHT : 1.0;
        $cost = self::rowMismatch($template, $ratios, $start) * $slots * $weight;
        if ($slots === 1) {
            $cost += self::SINGLE_PENALTY + ($weight > 1.0 ? self::LAST_SINGLE_PENALTY : 0.0);
        }
        if ($template->id === 'solo') {
            $cost += self::SOLO_PENALTY;
        }
        $preferred = match ($rhythm) {
            self::RHYTHM_CALM => RowTemplate::GROUP_CALM,
            self::RHYTHM_BOLD => RowTemplate::GROUP_BOLD,
            default => null,
        };
        if ($preferred !== null && $template->group !== $preferred) {
            $cost += self::RHYTHM_BIAS;
        }

        return $cost + self::JITTER * (crc32($seed . ':' . $start . ':' . $template->id) % 1000) / 1000;
    }

    private function transitionCost(RowTemplate $previous, RowTemplate $next): float
    {
        $cost = 0.0;
        if ($previous->id === $next->id) {
            $cost += self::REPEAT_PENALTY;
        }
        if ($previous->heightFactor >= self::TALL_FROM && $next->heightFactor >= self::TALL_FROM) {
            $cost += self::TALL_PENALTY;
        }

        return $cost;
    }
}
