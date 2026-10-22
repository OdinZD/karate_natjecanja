<?php

namespace Tests\Unit\Actions\Draw;

use App\Actions\Draw\DrawArranger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

class DrawArrangerTest extends TestCase
{
    /**
     * @param  array<int, int>|null  $clubs  club id per entry (1-based entry ids)
     * @return array<int, array{id: int, club_id: int, seed: int|null}>
     */
    private function entries(int $count, bool $seeded = false, ?array $clubs = null): array
    {
        $entries = [];

        for ($id = 1; $id <= $count; $id++) {
            $entries[] = [
                'id' => $id,
                'club_id' => $clubs[$id - 1] ?? $id,
                'seed' => $seeded ? $id : null,
            ];
        }

        return $entries;
    }

    private function randomizer(int $seed = 1): Randomizer
    {
        return new Randomizer(new Mt19937($seed));
    }

    private function sizeFor(int $count): int
    {
        $size = 2;

        while ($size < $count) {
            $size *= 2;
        }

        return $size;
    }

    /**
     * @return array<string, array{int}>
     */
    public static function entryCounts(): array
    {
        $counts = [...range(2, 17), 32, 33];

        return array_combine(array_map(fn (int $n) => "n=$n", $counts), array_map(fn (int $n) => [$n], $counts));
    }

    public function test_slot_order_is_the_standard_bracket_order(): void
    {
        $arranger = new DrawArranger;

        $this->assertSame([1, 2], $arranger->slotOrder(2));
        $this->assertSame([1, 4, 2, 3], $arranger->slotOrder(4));
        $this->assertSame([1, 8, 4, 5, 2, 7, 3, 6], $arranger->slotOrder(8));
    }

    #[DataProvider('entryCounts')]
    public function test_every_entry_is_placed_once_in_a_power_of_two_bracket(int $count): void
    {
        $slots = (new DrawArranger)->arrange($this->entries($count), $this->randomizer());

        $size = $this->sizeFor($count);
        $placed = array_values(array_filter($slots, fn ($id) => $id !== null));
        sort($placed);

        $this->assertCount($size, $slots);
        $this->assertSame(range(1, $count), $placed);
        $this->assertSame($size - $count, count($slots) - count($placed));

        for ($i = 0; $i < $size; $i += 2) {
            $this->assertFalse($slots[$i] === null && $slots[$i + 1] === null, 'bye must not face a bye');
        }
    }

    #[DataProvider('entryCounts')]
    public function test_byes_go_to_the_best_seeds_and_the_top_two_seeds_are_in_opposite_halves(int $count): void
    {
        $slots = (new DrawArranger)->arrange($this->entries($count, seeded: true), $this->randomizer());
        $size = $this->sizeFor($count);
        $byes = $size - $count;

        for ($i = 0; $i < $size; $i += 2) {
            if ($slots[$i] === null || $slots[$i + 1] === null) {
                $this->assertLessThanOrEqual($byes, $slots[$i] ?? $slots[$i + 1], 'only the best seeds get a bye');
            }
        }

        $this->assertLessThan($size / 2, array_search(1, $slots, true));
        $this->assertGreaterThanOrEqual($size / 2, array_search(2, $slots, true));
    }

    public function test_seeds_are_placed_in_standard_positions(): void
    {
        $entries = $this->entries(8);
        foreach ([1 => 5, 2 => 3, 3 => 7, 4 => 1] as $seed => $id) {
            $entries[$id - 1]['seed'] = $seed;
        }

        $slots = (new DrawArranger)->arrange($entries, $this->randomizer());

        $this->assertSame(5, $slots[0]);
        $this->assertSame(3, $slots[4]);
        $this->assertSame(7, $slots[6]);
        $this->assertSame(1, $slots[2]);
    }

    public function test_clubs_are_kept_apart_in_the_first_round(): void
    {
        $arranger = new DrawArranger;

        foreach ([[4, 4], [4, 4, 4, 4], [3, 3, 3, 3, 4]] as $pattern) {
            $clubs = [];
            foreach ($pattern as $club => $members) {
                array_push($clubs, ...array_fill(0, $members, $club + 1));
            }

            $entries = $this->entries(count($clubs), clubs: $clubs);
            $clubOf = array_column($entries, 'club_id', 'id');

            foreach (range(1, 10) as $seed) {
                $slots = $arranger->arrange($entries, $this->randomizer($seed));

                for ($i = 0; $i < count($slots); $i += 2) {
                    if ($slots[$i] !== null && $slots[$i + 1] !== null) {
                        $this->assertNotSame($clubOf[$slots[$i]], $clubOf[$slots[$i + 1]], 'same club in the first round');
                    }
                }
            }
        }
    }

    public function test_the_same_randomizer_seed_gives_the_same_draw(): void
    {
        $arranger = new DrawArranger;
        $entries = $this->entries(13);

        $this->assertSame(
            $arranger->arrange($entries, $this->randomizer(7)),
            $arranger->arrange($entries, $this->randomizer(7)),
        );
        $this->assertNotSame(
            $arranger->arrange($entries, $this->randomizer(7)),
            $arranger->arrange($entries, $this->randomizer(8)),
        );
    }
}
