<?php

namespace App\Actions\Timetable;

use App\Enums\BoutBracket;
use App\Models\Bout;
use Illuminate\Support\Collection;

class BoutOrder
{
    /**
     * Sort bouts of one category in the order they are played: the main bracket round by round, the
     * repechage once the semifinals are decided, the final last.
     *
     * @param  Collection<int, Bout>  $bouts
     * @return Collection<int, Bout>
     */
    public function sort(Collection $bouts, int $finalRound): Collection
    {
        return $bouts->sortBy(fn (Bout $bout) => $this->key($bout, $finalRound))->values();
    }

    private function key(Bout $bout, int $finalRound): int
    {
        if ($bout->bracket === BoutBracket::Repechage) {
            return ($finalRound - 1) * 100 + 50 + $bout->round * 3 + $bout->position;
        }

        return $bout->round * 100 + $bout->position;
    }
}
