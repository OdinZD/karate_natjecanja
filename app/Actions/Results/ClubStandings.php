<?php

namespace App\Actions\Results;

use App\Models\Competition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ClubStandings
{
    /**
     * The medal table of a competition: gold, then silver, then bronze, then club name. Clubs with the same
     * medals share a rank (1, 2, 2, 4) and clubs without medals are left out.
     *
     * @return Collection<int, ClubStandingRow>
     */
    public function handle(Competition $competition): Collection
    {
        $rows = DB::table('placements')
            ->join('categories', 'categories.id', '=', 'placements.category_id')
            ->join('entries', 'entries.id', '=', 'placements.entry_id')
            ->join('competitors', 'competitors.id', '=', 'entries.competitor_id')
            ->join('clubs', 'clubs.id', '=', 'competitors.club_id')
            ->where('categories.competition_id', $competition->id)
            ->groupBy('clubs.id', 'clubs.name', 'clubs.city')
            ->selectRaw('clubs.id as club_id, clubs.name as club_name, clubs.city as club_city,
                SUM(CASE WHEN placements.place = 1 THEN 1 ELSE 0 END) as gold,
                SUM(CASE WHEN placements.place = 2 THEN 1 ELSE 0 END) as silver,
                SUM(CASE WHEN placements.place = 3 THEN 1 ELSE 0 END) as bronze')
            ->orderByDesc('gold')
            ->orderByDesc('silver')
            ->orderByDesc('bronze')
            ->orderBy('club_name')
            ->get();

        $rank = 0;
        $previous = null;

        return $rows->values()->map(function (object $row, int $index) use (&$rank, &$previous): ClubStandingRow {
            $medals = [(int) $row->gold, (int) $row->silver, (int) $row->bronze];

            if ($medals !== $previous) {
                $rank = $index + 1;
                $previous = $medals;
            }

            return new ClubStandingRow(
                rank: $rank,
                clubId: (int) $row->club_id,
                club: (string) $row->club_name,
                city: $row->club_city !== null ? (string) $row->club_city : null,
                gold: $medals[0],
                silver: $medals[1],
                bronze: $medals[2],
                total: array_sum($medals),
            );
        });
    }
}
