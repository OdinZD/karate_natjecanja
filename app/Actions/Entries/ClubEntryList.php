<?php

namespace App\Actions\Entries;

use App\Models\Club;
use App\Models\Competition;
use App\Models\Competitor;
use App\Models\Entry;
use Illuminate\Database\Eloquent\Collection;

/**
 * What a club has entered in a competition: its competitors with their entries in that competition,
 * for the confirmation the club's trainer checks.
 */
class ClubEntryList
{
    /**
     * @return Collection<int, Competitor> Competitors by name, each with only this competition's entries (and their categories) in category order.
     */
    public function handle(Competition $competition, Club $club): Collection
    {
        $inCompetition = fn ($query) => $query->where('competition_id', $competition->id);

        return Competitor::query()
            ->where('club_id', $club->id)
            ->whereHas('entries.category', $inCompetition)
            ->with(['entries' => fn ($query) => $query->whereHas('category', $inCompetition)->with('category')])
            ->orderByName('last_name')
            ->orderByName('first_name')
            ->orderBy('id')
            ->get()
            ->each(fn (Competitor $competitor) => $competitor->setRelation(
                'entries',
                $competitor->entries
                    ->sortBy(fn (Entry $entry) => sprintf('%05d|%s', $entry->category->sort_order, $entry->category->name))
                    ->values(),
            ));
    }
}
