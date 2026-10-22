<?php

namespace App\Actions\Pdf;

use App\Models\Bout;
use Illuminate\Support\Collection;

class BracketGrid
{
    /**
     * Lay the main bracket of a category out as a table, because dompdf has no flexbox or grid.
     *
     * Columns are rounds and there is one row per first-round bout. A bout of round r starts at row
     * (position − 1) · 2^(r−1) and spans 2^(r−1) rows. In a row, the cell of a round is
     * ['bout' => ?Bout, 'rowspan' => int] where a bout starts, and null where an earlier cell already
     * covers the row. A bout that is missing from an incomplete set gives a cell with a null bout.
     *
     * @param  Collection<int, Bout>  $mainBouts
     * @return array{rounds: int, rows: int, cells: array<int, array<int, array{bout: Bout|null, rowspan: int}|null>>}
     */
    public function build(Collection $mainBouts): array
    {
        $rounds = (int) $mainBouts->max('round');

        if ($rounds === 0) {
            return ['rounds' => 0, 'rows' => 0, 'cells' => []];
        }

        $rows = 2 ** ($rounds - 1);
        $byRoundAndPosition = $mainBouts->groupBy('round')->map(fn (Collection $bouts) => $bouts->keyBy('position'));

        $cells = [];

        for ($row = 0; $row < $rows; $row++) {
            for ($round = 1; $round <= $rounds; $round++) {
                $span = 2 ** ($round - 1);

                $cells[$row][$round] = $row % $span === 0
                    ? ['bout' => $byRoundAndPosition->get($round)?->get(intdiv($row, $span) + 1), 'rowspan' => $span]
                    : null;
            }
        }

        return ['rounds' => $rounds, 'rows' => $rows, 'cells' => $cells];
    }
}
