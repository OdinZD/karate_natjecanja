<?php

namespace Tests\Feature\Draw;

use App\Actions\Draw\DrawArranger;
use App\Actions\Draw\GenerateDraw;
use App\Actions\Draw\RecordBoutResult;
use App\Enums\BoutDecision;
use App\Enums\BoutSlot;
use App\Enums\BoutStatus;
use App\Enums\Discipline;
use App\Models\Bout;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Entry;
use Random\Engine\Mt19937;
use Random\Randomizer;

trait InteractsWithDraws
{
    /**
     * A category with a generated draw. Seeded entries get seed = 1…n, so the better seed (lower number) is
     * placed in the standard bracket positions.
     */
    protected function drawnCategory(int $entries, Discipline $discipline = Discipline::Kumite, bool $seeded = true, ?Competition $competition = null): Category
    {
        $category = $competition !== null
            ? Category::factory()->for($competition)->create(['discipline' => $discipline])
            : Category::factory()->create(['discipline' => $discipline]);

        for ($seed = 1; $seed <= $entries; $seed++) {
            Entry::factory()->for($category)->create(['seed' => $seeded ? $seed : null]);
        }

        (new GenerateDraw(new DrawArranger, new Randomizer(new Mt19937(1))))->handle($category);

        return $category;
    }

    /**
     * Play every bout until none is ready. Policy 'low' lets the better (lower) seed win, 'high' the worse one,
     * anything else decides with the seeded mt_rand().
     */
    protected function playAll(Category $category, string $policy = 'low'): int
    {
        $recorder = app(RecordBoutResult::class);
        $played = 0;

        while ($bout = $category->bouts()->where('status', BoutStatus::Ready)->orderBy('id')->first()) {
            $recorder->handle($bout, $this->winnerSlot($bout, $policy), null, null, BoutDecision::Hantei);

            $this->assertLessThan(200, ++$played, 'the bracket does not terminate');
        }

        return $played;
    }

    private function winnerSlot(Bout $bout, string $policy): BoutSlot
    {
        $akaSeed = Entry::findOrFail($bout->aka_entry_id)->seed ?? $bout->aka_entry_id;
        $aoSeed = Entry::findOrFail($bout->ao_entry_id)->seed ?? $bout->ao_entry_id;

        return match ($policy) {
            'low' => $akaSeed < $aoSeed ? BoutSlot::Aka : BoutSlot::Ao,
            'high' => $akaSeed > $aoSeed ? BoutSlot::Aka : BoutSlot::Ao,
            default => mt_rand(0, 1) === 0 ? BoutSlot::Aka : BoutSlot::Ao,
        };
    }

    /**
     * Seeds of the placed entries per place, e.g. [1 => [1], 2 => [2], 3 => [3, 4]].
     *
     * @return array<int, array<int, int>>
     */
    protected function podium(Category $category): array
    {
        return $category->placements()->with('entry')->get()
            ->groupBy('place')
            ->map(fn ($placements) => $placements->map(fn ($placement) => $placement->entry->seed)->sort()->values()->all())
            ->sortKeys()
            ->all();
    }

    protected function bout(Category $category, int $round, int $position): Bout
    {
        return $category->bouts()->where('bracket', 'main')->where('round', $round)->where('position', $position)->firstOrFail();
    }
}
