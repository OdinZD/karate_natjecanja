<?php

namespace App\Actions\Draw;

use App\Enums\BoutBracket;
use App\Enums\BoutDecision;
use App\Enums\BoutSlot;
use App\Enums\BoutStatus;
use App\Models\Bout;
use App\Models\Category;
use App\Models\Entry;
use Illuminate\Support\Facades\DB;
use Random\Randomizer;

class GenerateDraw
{
    public function __construct(
        private readonly DrawArranger $arranger,
        private readonly ?Randomizer $randomizer = null,
    ) {}

    /**
     * Build the single-elimination bracket of a category, replacing any draw that has not been played yet.
     *
     * @throws DrawException
     */
    public function handle(Category $category): void
    {
        DB::transaction(function () use ($category): void {
            if ($category->hasPlayedBouts()) {
                throw new DrawException(__('The draw cannot be changed because bouts have already been played.'));
            }

            $entries = $category->entries()->with('competitor')->get();

            if ($entries->count() < 2) {
                throw new DrawException(__('A draw needs at least 2 entries.'));
            }

            $slots = $this->arranger->arrange(
                $entries->map(fn (Entry $entry) => [
                    'id' => $entry->id,
                    'club_id' => $entry->competitor->club_id,
                    'seed' => $entry->seed,
                ])->all(),
                $this->randomizer ?? new Randomizer,
            );

            $category->bouts()->delete();
            $category->placements()->delete();

            $this->createBracket($category, $slots);
        });
    }

    /**
     * @param  array<int, int|null>  $slots
     */
    private function createBracket(Category $category, array $slots): void
    {
        $size = count($slots);
        $rounds = (int) log($size, 2);

        /** @var array<int, array<int, Bout>> $bouts */
        $bouts = [];

        for ($round = $rounds; $round >= 1; $round--) {
            for ($position = 1; $position <= $size / (2 ** $round); $position++) {
                $next = $round < $rounds ? $bouts[$round + 1][intdiv($position + 1, 2)] : null;

                $bouts[$round][$position] = $category->bouts()->create([
                    'bracket' => BoutBracket::Main,
                    'round' => $round,
                    'position' => $position,
                    'next_bout_id' => $next?->id,
                    'next_slot' => $next ? ($position % 2 === 1 ? BoutSlot::Aka : BoutSlot::Ao) : null,
                    'status' => BoutStatus::Pending,
                ]);
            }
        }

        foreach ($bouts[1] as $position => $bout) {
            $aka = $slots[($position - 1) * 2];
            $ao = $slots[($position - 1) * 2 + 1];

            $bout->aka_entry_id = $aka;
            $bout->ao_entry_id = $ao;

            if ($aka !== null && $ao !== null) {
                $bout->status = BoutStatus::Ready;
            } else {
                $bout->fill([
                    'winner_entry_id' => $aka ?? $ao,
                    'decision' => BoutDecision::Bye,
                    'status' => BoutStatus::Finished,
                    'finished_at' => now(),
                ]);

                $this->advance($bouts, $bout);
            }

            $bout->save();
        }

        foreach ($bouts[2] ?? [] as $bout) {
            if ($bout->aka_entry_id !== null && $bout->ao_entry_id !== null) {
                $bout->status = BoutStatus::Ready;
            }

            $bout->save();
        }
    }

    /**
     * Put the winner of a first-round bye into its slot of the next bout.
     *
     * @param  array<int, array<int, Bout>>  $bouts
     */
    private function advance(array $bouts, Bout $bout): void
    {
        $next = $bouts[2][intdiv($bout->position + 1, 2)] ?? null;

        if ($next === null) {
            return;
        }

        if ($bout->next_slot === BoutSlot::Aka) {
            $next->aka_entry_id = $bout->winner_entry_id;
        } else {
            $next->ao_entry_id = $bout->winner_entry_id;
        }
    }
}
