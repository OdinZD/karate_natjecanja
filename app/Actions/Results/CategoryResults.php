<?php

namespace App\Actions\Results;

use App\Actions\Timetable\TimetableBuilder;
use App\Enums\Discipline;
use App\Enums\DrawProgress;
use App\Models\Competition;
use App\Models\Entry;
use App\Models\Placement;
use Illuminate\Support\Collection;

class CategoryResults
{
    public function __construct(private readonly TimetableBuilder $timetable) {}

    /**
     * Podiums of every category of the competition that already has a placement.
     *
     * @return Collection<int, CategoryResultRow>
     */
    public function handle(Competition $competition, ?Discipline $discipline = null): Collection
    {
        $categories = $this->timetable->withProgressCounts($competition->categories())
            ->whereHas('placements')
            ->when($discipline !== null, fn ($query) => $query->where('discipline', $discipline))
            ->with('placements.entry.competitor.club')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $categories->map(function ($category): CategoryResultRow {
            $entriesAt = fn (int $place): array => $category->placements
                ->where('place', $place)
                ->map(fn (Placement $placement) => $placement->entry)
                ->filter()
                ->sortBy(fn (Entry $entry) => $entry->competitor->last_name.' '.$entry->competitor->first_name)
                ->values()
                ->all();

            return new CategoryResultRow(
                $category,
                $entriesAt(1),
                $entriesAt(2),
                $entriesAt(3),
                $this->timetable->progress($category) === DrawProgress::Finished,
            );
        })->values();
    }
}
