<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWGallery\Core\Domain\Layout\Magazine\MagazinePlan;
use SWGallery\Core\Domain\Layout\Magazine\MagazinePlanner;
use SWGallery\Core\Domain\Layout\Magazine\Orientation;
use SWGallery\Core\Domain\Layout\Magazine\RowTemplate;
use SWGallery\Core\Domain\Layout\Magazine\RowTemplates;
use SWGallery\Core\Domain\Layout\Plan\PlanItem;

final class MagazinePlannerTest extends TestCase
{
    private const LANDSCAPES = [1.5, 1.778, 1.333, 1.6, 2.0, 2.4];
    private const PORTRAITS = [0.667, 0.75, 0.8, 0.5625];
    private const SQUARES = [1.0, 0.9, 1.1];

    /**
     * Seeded pseudo random photo gallery (a small LCG, independent of mt_rand): about 65 % landscape, 25 % portrait.
     *
     * @return list<PlanItem>
     */
    private function gallery(int $seed, int $size, int $landscapeShare = 65, int $portraitShare = 25): array
    {
        $state = $seed * 2654435761 % 4294967296;
        $next = static function (int $modulo) use (&$state): int {
            $state = ($state * 1103515245 + 12345) % 2147483648;

            return intdiv($state, 65536) % $modulo;
        };
        $items = [];
        for ($i = 0; $i < $size; ++$i) {
            $roll = $next(100);
            $pool = $roll < $landscapeShare ? self::LANDSCAPES : ($roll < $landscapeShare + $portraitShare ? self::PORTRAITS : self::SQUARES);
            $items[] = new PlanItem($pool[$next(count($pool))]);
        }

        return $items;
    }

    private function assertCovers(MagazinePlan $plan, int $count): void
    {
        $position = 0;
        foreach ($plan->rows as $row) {
            $this->assertSame($position, $row->start, 'Rows are consecutive, so the order is preserved.');
            $this->assertSame($row->template->slotCount(), $row->count, 'No empty slot, no overflow.');
            $position += $row->count;
        }
        $this->assertSame($count, $position, 'Every item is placed exactly once.');
    }

    /** @return array<string, array{int}> */
    public static function counts(): array
    {
        $cases = [];
        for ($count = 1; $count <= 60; ++$count) {
            $cases["$count items"] = [$count];
        }

        return $cases;
    }

    #[DataProvider('counts')]
    public function testEveryCountIsCoveredForManyRandomSequencesAndRhythms(int $count): void
    {
        $planner = new MagazinePlanner();
        foreach ([MagazinePlanner::RHYTHM_BALANCED, MagazinePlanner::RHYTHM_CALM, MagazinePlanner::RHYTHM_BOLD, 'nonsense'] as $rhythm) {
            for ($seed = 1; $seed <= 6; ++$seed) {
                $items = $this->gallery($seed * 100 + $count, $count, 20 + 10 * $seed, 30);
                $this->assertCovers($planner->plan($items, $seed * 7919, $rhythm), $count);
            }
        }
    }

    public function testEmptyInputGivesNoRows(): void
    {
        $plan = (new MagazinePlanner())->plan([], 1);
        $this->assertSame([], $plan->rows);
        $this->assertSame([], $plan->templateIds());
    }

    public function testExtremeSequencesNeverThrow(): void
    {
        $planner = new MagazinePlanner();
        foreach ([0.01, 0.25, 1.0, 4.0, 99.0, null] as $ratio) {
            foreach ([1, 2, 7, 33, 60] as $count) {
                $items = array_fill(0, $count, new PlanItem($ratio));
                $this->assertCovers($planner->plan($items, 5), $count);
            }
        }
    }

    public function testThePlanIsDeterministicAndTheSeedVariesIt(): void
    {
        $planner = new MagazinePlanner();
        $items = $this->gallery(3, 40);
        $this->assertSame($planner->plan($items, 11)->templateIds(), $planner->plan($items, 11)->templateIds());
        $variants = [];
        foreach (range(1, 40) as $seed) {
            $variants[implode(',', $planner->plan($items, $seed * 104729)->templateIds())] = true;
        }
        $this->assertGreaterThan(1, count($variants), 'The seeded tie-breaker gives variety between galleries.');
    }

    public function testTheCataloguePassesItsOwnValidationAndOffersEnoughTemplates(): void
    {
        $all = RowTemplates::all();
        $this->assertGreaterThanOrEqual(8, count($all));
        $slotCounts = [];
        foreach ($all as $id => $template) {
            $this->assertSame($id, $template->id);
            $this->assertSame(array_slice(['a', 'b', 'c', 'd', 'e'], 0, $template->slotCount()), $template->slots);
            $this->assertLessThanOrEqual(RowTemplate::MAX_SLOTS, $template->slotCount());
            $slotCounts[$template->slotCount()] = true;
            for ($slot = 0; $slot < $template->slotCount(); ++$slot) {
                $this->assertGreaterThan(0.3, $template->slotAspect($slot), $id);
                $this->assertLessThan(4.1, $template->slotAspect($slot), $id);
            }
            $this->assertEqualsWithDelta(RowTemplate::REFERENCE_WIDTH / $template->referenceHeight(), $template->rowAspect(), 1e-9);
            $this->assertGreaterThan(0.0, $template->widestSlotShare());
            $this->assertLessThanOrEqual(1.0, $template->widestSlotShare());
        }
        $this->assertSame([1, 2, 3, 4, 5], array_keys($slotCounts) !== [] ? (static function (array $keys): array {
            sort($keys);

            return $keys;
        })(array_keys($slotCounts)) : [], 'One to five slots are offered.');
        $this->assertTrue(RowTemplates::exists('hero-left'));
        $this->assertFalse(RowTemplates::exists('nope'));
        $this->assertSame('triptych', RowTemplates::get('triptych')->id);
    }

    public function testSlotGeometryOfKnownTemplates(): void
    {
        $hero = RowTemplates::get('hero-left');
        $this->assertEqualsWithDelta(1.5, $hero->slotAspect(0), 0.01, 'The hero is a 3:2 landscape.');
        $this->assertEqualsWithDelta(1.5, $hero->slotAspect(1), 0.01);
        $this->assertEqualsWithDelta(1.25, RowTemplates::get('triptych')->slotAspect(2), 0.001);
        $this->assertEqualsWithDelta(0.75, RowTemplates::get('portrait-trio')->slotAspect(1), 0.01);
        $this->assertEqualsWithDelta(0.5, RowTemplates::get('solo')->widestSlotShare(), 0.001, 'The centred plate spans half of the row.');
    }

    public function testCssDeclarationsAreStable(): void
    {
        $this->assertSame(
            'grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); grid-template-rows: minmax(0, 1fr) minmax(0, 1fr); grid-template-areas: "a b" "a c"; --swg-hf: 1.67;',
            RowTemplates::get('hero-left')->css()
        );
        $this->assertStringContainsString('"a b b" "a c d"', RowTemplates::get('hero-four')->css());
    }

    public function testTheStylesheetContainsEveryTemplateExactlyAsCoreDescribesIt(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/frontend/src/magazine.css');
        $css = str_replace("'", '"', (string) preg_replace('/\s+/', ' ', $css));
        foreach (RowTemplates::all() as $id => $template) {
            $this->assertStringContainsString(
                '.swg-gallery.swg-layout-magazine .swg-tpl-' . $id . ' > .swg-row-items { ' . $template->css() . ' }',
                $css,
                "magazine.css and RowTemplate::css() differ for $id"
            );
        }
    }

    /** @return array<string, array{callable(): RowTemplate}> */
    public static function invalidTemplates(): array
    {
        return [
            'bad id' => [static fn () => new RowTemplate('Bad_Id', [1], [1], [['a']], 1.0, 'calm')],
            'zero factor' => [static fn () => new RowTemplate('x', [1], [1], [['a']], 0.0, 'calm')],
            'bad group' => [static fn () => new RowTemplate('x', [1], [1], [['a']], 1.0, 'loud')],
            'row count' => [static fn () => new RowTemplate('x', [1], [1, 1], [['a']], 1.0, 'calm')],
            'zero weight' => [static fn () => new RowTemplate('x', [0], [1], [['a']], 1.0, 'calm')],
            'column count' => [static fn () => new RowTemplate('x', [1, 1], [1], [['a']], 1.0, 'calm')],
            'hole without centred' => [static fn () => new RowTemplate('x', [1, 1], [1], [['a', '.']], 1.0, 'calm')],
            'bad letter' => [static fn () => new RowTemplate('x', [1], [1], [['z']], 1.0, 'calm')],
            'wrong order' => [static fn () => new RowTemplate('x', [1, 1], [1], [['b', 'a']], 1.0, 'calm')],
            'no slot' => [static fn () => new RowTemplate('x', [1], [1], [['.']], 1.0, 'calm', true)],
            'L shape' => [static fn () => new RowTemplate('x', [1, 1], [1, 1], [['a', 'a'], ['a', 'b']], 1.0, 'calm')],
            'unknown template' => [static fn () => RowTemplates::get('missing')],
        ];
    }

    /** @param callable(): RowTemplate $factory */
    #[DataProvider('invalidTemplates')]
    public function testInvalidTemplatesAreRejected(callable $factory): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $factory();
    }

    public function testOrientationThresholds(): void
    {
        $this->assertSame(Orientation::Landscape, Orientation::fromRatio(1.15));
        $this->assertSame(Orientation::Landscape, Orientation::fromRatio(1.5));
        $this->assertSame(Orientation::Square, Orientation::fromRatio(1.0));
        $this->assertSame(Orientation::Square, Orientation::fromRatio(1.14));
        $this->assertSame(Orientation::Square, Orientation::fromRatio(0.88));
        $this->assertSame(Orientation::Portrait, Orientation::fromRatio(0.87));
        $this->assertSame(Orientation::Portrait, Orientation::fromRatio(0.5));
    }

    public function testOneOrTwoPicturesFormAPleasingRow(): void
    {
        $planner = new MagazinePlanner();
        $this->assertSame(['solo'], $planner->plan([new PlanItem(0.75)], 1)->templateIds(), 'A lone portrait is a centred plate, not a stretched banner.');
        $this->assertSame(['solo'], $planner->plan([new PlanItem(1.0)], 1)->templateIds());
        $this->assertContains($planner->plan([new PlanItem(1.5)], 1)->templateIds()[0], ['full', 'panorama']);
        $this->assertSame(['panorama'], $planner->plan([new PlanItem(3.0)], 1)->templateIds());
        $this->assertSame(['duo'], $planner->plan([new PlanItem(1.5), new PlanItem(1.5)], 1)->templateIds());
        $this->assertSame(['portrait-pair'], $planner->plan([new PlanItem(0.75), new PlanItem(0.75)], 1)->templateIds());
        $this->assertSame(['duo-split'], $planner->plan([new PlanItem(1.6), new PlanItem(0.7)], 1)->templateIds());
        $this->assertSame(['duo-split-r'], $planner->plan([new PlanItem(0.7), new PlanItem(1.6)], 1)->templateIds());
    }

    public function testThreeLandscapesGetAHeroRow(): void
    {
        $plan = (new MagazinePlanner())->plan([new PlanItem(1.5), new PlanItem(1.5), new PlanItem(1.5)], 1);
        $this->assertSame(['hero-left'], $plan->templateIds());
    }

    public function testTheLastRowIsNeverAnAwkwardLeftover(): void
    {
        $planner = new MagazinePlanner();
        $singles = 0;
        $plates = 0;
        $total = 0;
        for ($count = 2; $count <= 60; ++$count) {
            for ($seed = 1; $seed <= 12; ++$seed) {
                $items = $this->gallery($seed * 31 + $count, $count);
                $ratios = array_map(static fn (PlanItem $item): float => $item->aspectRatio, $items);
                $last = $planner->plan($items, $seed)->rows;
                $last = $last[count($last) - 1];
                ++$total;
                if ($last->template->id === 'solo') {
                    ++$plates;
                    $this->assertLessThan(1.15, $ratios[$last->start], 'Only a portrait or square may close the page as a centred plate.');
                    continue;
                }
                $this->assertLessThan(0.55, MagazinePlanner::rowMismatch($last->template, $ratios, $last->start), 'The closing row must fit its pictures.');
                $singles += $last->count === 1 ? 1 : 0;
            }
        }
        $this->assertLessThan(0.1 * $total, $singles, 'A single closing picture is the exception.');
        $this->assertLessThan(0.05 * $total, $plates, 'So is the centred closing plate.');
    }

    public function testQualityHistogramAndRepetitionOnRealisticGalleries(): void
    {
        $planner = new MagazinePlanner();
        $mismatch = 0.0;
        $pictures = 0;
        $rows = 0;
        $repeats = 0;
        $histogram = [];
        for ($seed = 1; $seed <= 80; ++$seed) {
            $count = 5 + $seed % 56;
            $items = $this->gallery($seed, $count);
            $ratios = array_map(static fn (PlanItem $item): float => $item->aspectRatio, $items);
            $previous = null;
            foreach ($planner->plan($items, $seed)->rows as $row) {
                $mismatch += MagazinePlanner::rowMismatch($row->template, $ratios, $row->start) * $row->count;
                $pictures += $row->count;
                ++$rows;
                $repeats += $previous === $row->template->id ? 1 : 0;
                $previous = $row->template->id;
                $histogram[$row->template->id] = ($histogram[$row->template->id] ?? 0) + 1;
            }
        }
        $this->assertLessThan(0.22, $mismatch / $pictures, 'Mean |ln(slot / picture)| stays small (about 20 % crop).');
        $this->assertLessThan(0.03 * $rows, $repeats, 'The same template twice in a row is rare.');
        $this->assertGreaterThanOrEqual(10, count($histogram), 'Many different templates are used.');
        $this->assertLessThan(0.4 * $rows, max($histogram), 'No template dominates.');
    }

    public function testTheRhythmShiftsTheMixOfTemplates(): void
    {
        $planner = new MagazinePlanner();
        $share = static function (string $rhythm, string $group) use ($planner): float {
            $matching = 0;
            $rows = 0;
            for ($seed = 1; $seed <= 30; ++$seed) {
                $items = [];
                $state = $seed;
                for ($i = 0; $i < 40; ++$i) {
                    $state = ($state * 1103515245 + 12345) % 2147483648;
                    $items[] = new PlanItem(0.8 + (intdiv($state, 65536) % 100) / 80);
                }
                foreach ($planner->plan($items, $seed, $rhythm)->rows as $row) {
                    ++$rows;
                    $matching += $row->template->group === $group ? 1 : 0;
                }
            }

            return $matching / $rows;
        };
        $this->assertGreaterThan($share(MagazinePlanner::RHYTHM_BALANCED, RowTemplate::GROUP_CALM), $share(MagazinePlanner::RHYTHM_CALM, RowTemplate::GROUP_CALM));
        $this->assertGreaterThan($share(MagazinePlanner::RHYTHM_BALANCED, RowTemplate::GROUP_BOLD), $share(MagazinePlanner::RHYTHM_BOLD, RowTemplate::GROUP_BOLD));
    }
}
