<?php

namespace App\Actions\Draw;

use Random\Randomizer;

class DrawArranger
{
    private const ATTEMPTS = 500;

    /**
     * Standard bracket order: the rank (1 = best) that plays in each slot of a bracket of the given size.
     * Neighbouring slots meet in the first round, ranks 1 and 2 end up in opposite halves.
     *
     * @return array<int, int>
     */
    public function slotOrder(int $size): array
    {
        $ranks = [1];

        while (count($ranks) < $size) {
            $total = count($ranks) * 2;
            $next = [];

            foreach ($ranks as $rank) {
                $next[] = $rank;
                $next[] = $total + 1 - $rank;
            }

            $ranks = $next;
        }

        return $ranks;
    }

    /**
     * Arrange entries into bracket slots. Seeded entries take the best ranks in seed order, the rest are
     * shuffled, keeping the arrangement with the fewest same-club first-round pairs, then the fewest
     * same-club pairs within one half. Slots without an entry (byes) are null.
     *
     * @param  array<int, array{id: int, club_id: int, seed: int|null}>  $entries
     * @return array<int, int|null>
     */
    public function arrange(array $entries, Randomizer $randomizer): array
    {
        $count = count($entries);
        $size = 2;

        while ($size < $count) {
            $size *= 2;
        }

        $seeded = array_values(array_filter($entries, fn (array $entry) => $entry['seed'] !== null));
        usort($seeded, fn (array $a, array $b) => [$a['seed'], $a['id']] <=> [$b['seed'], $b['id']]);
        $unseeded = array_values(array_filter($entries, fn (array $entry) => $entry['seed'] === null));

        $order = $this->slotOrder($size);

        $best = null;
        $bestScore = PHP_INT_MAX;

        for ($attempt = 0; $attempt < self::ATTEMPTS && $bestScore > 0; $attempt++) {
            $ranked = [...$seeded, ...$randomizer->shuffleArray($unseeded)];
            $slots = array_map(fn (int $rank) => $ranked[$rank - 1] ?? null, $order);
            $score = $this->score($slots, $size);

            if ($score < $bestScore) {
                $best = $slots;
                $bestScore = $score;
            }

            if ($unseeded === [] || count($unseeded) === 1) {
                break;
            }
        }

        return array_map(fn (?array $entry) => $entry['id'] ?? null, $best ?? []);
    }

    /**
     * @param  array<int, array{id: int, club_id: int, seed: int|null}|null>  $slots
     */
    private function score(array $slots, int $size): int
    {
        $firstRound = 0;

        for ($i = 0; $i < $size; $i += 2) {
            if ($slots[$i] !== null && $slots[$i + 1] !== null && $slots[$i]['club_id'] === $slots[$i + 1]['club_id']) {
                $firstRound++;
            }
        }

        $sameHalf = 0;

        foreach ([array_slice($slots, 0, intdiv($size, 2)), array_slice($slots, intdiv($size, 2))] as $half) {
            $perClub = [];

            foreach ($half as $entry) {
                if ($entry !== null) {
                    $perClub[$entry['club_id']] = ($perClub[$entry['club_id']] ?? 0) + 1;
                }
            }

            foreach ($perClub as $clubCount) {
                $sameHalf += intdiv($clubCount * ($clubCount - 1), 2);
            }
        }

        return $firstRound * 100000 + $sameHalf;
    }
}
