<?php

namespace App\Actions\Timetable;

use App\Enums\BoutDecision;
use App\Enums\BoutStatus;
use App\Enums\Discipline;
use App\Enums\DrawProgress;
use App\Models\Category;
use App\Models\Competition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TimetableBuilder
{
    /** Seconds between two bouts (changeover, announcements). */
    public const BOUT_PAUSE_SECONDS = 60;

    /**
     * The time table of a competition: per tatami, its categories in order with estimated start and end.
     *
     * @return array<int, TimetableTatami>
     */
    public function build(Competition $competition): array
    {
        $start = CarbonImmutable::parse($competition->date->format('Y-m-d').' '.$competition->start_time);

        $categories = $this->withProgressCounts($competition->categories()->whereNotNull('tatami_id'))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('tatami_id');

        $tatamis = [];

        foreach ($competition->tatamis()->with('operators')->orderBy('number')->get() as $tatami) {
            $cursor = $start;
            $rows = [];

            foreach ($categories->get($tatami->id, []) as $category) {
                $bouts = $this->estimateBouts($category);
                $end = $cursor->addSeconds($bouts * ($category->bout_duration_seconds + self::BOUT_PAUSE_SECONDS));

                $rows[] = new TimetableRow($category, $this->entryCount($category), $bouts, $cursor, $end, $this->progress($category));
                $cursor = $end;
            }

            $tatamis[] = new TimetableTatami($tatami, $rows, $cursor);
        }

        return $tatamis;
    }

    /**
     * Categories of the competition that have not been given a tatami yet.
     *
     * @return Collection<int, Category>
     */
    public function unassigned(Competition $competition): Collection
    {
        return $this->withProgressCounts($competition->categories()->whereNull('tatami_id'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Estimated number of real bouts, independent of whether a draw exists yet: n − 1 main bouts plus,
     * for kumite, an upper bound for the repechage ladders (two per round beyond the quarterfinals).
     */
    public function estimateBouts(Category $category): int
    {
        $entries = $this->entryCount($category);

        if ($entries < 2) {
            return 0;
        }

        $bouts = $entries - 1;

        if ($category->discipline === Discipline::Kumite) {
            $rounds = 1;

            for ($size = 2; $size < $entries; $size *= 2) {
                $rounds++;
            }

            $bouts += 2 * max($rounds - 2, 0);
        }

        return $bouts;
    }

    public function progress(Category $category): DrawProgress
    {
        return match (true) {
            (int) $category->any_bouts_count === 0 => DrawProgress::NoDraw,
            (int) $category->open_bouts_count === 0 => DrawProgress::Finished,
            (int) $category->played_bouts_count > 0 => DrawProgress::Running,
            default => DrawProgress::Pending,
        };
    }

    /**
     * Add the entry and bout counts that {@see progress()} and {@see estimateBouts()} read.
     *
     * @param  HasMany<Category, *>  $query
     * @return HasMany<Category, *>
     */
    public function withProgressCounts(HasMany $query): HasMany
    {
        return $query->withCount([
            'entries',
            'bouts as any_bouts_count',
            'bouts as open_bouts_count' => fn (Builder $bouts) => $bouts->whereIn('status', [BoutStatus::Pending, BoutStatus::Ready]),
            'bouts as played_bouts_count' => fn (Builder $bouts) => $bouts
                ->where('status', BoutStatus::Finished)
                ->where('decision', '!=', BoutDecision::Bye),
        ]);
    }

    private function entryCount(Category $category): int
    {
        return (int) ($category->entries_count ?? $category->entries()->count());
    }
}
