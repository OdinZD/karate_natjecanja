<?php

namespace App\Actions\Draw;

use App\Enums\BoutBracket;
use App\Enums\BoutDecision;
use App\Enums\BoutSlot;
use App\Enums\BoutStatus;
use App\Models\Bout;
use App\Models\Category;
use App\Models\Entry;

class RepechageBuilder
{
    /**
     * Entries the finalist beat in real bouts of the main bracket, earliest round first.
     *
     * @return array<int, int>
     */
    public function defeatedBy(Category $category, int $finalistEntryId): array
    {
        return $category->bouts()
            ->where('bracket', BoutBracket::Main)
            ->where('status', BoutStatus::Finished)
            ->where('winner_entry_id', $finalistEntryId)
            ->where('decision', '!=', BoutDecision::Bye)
            ->orderBy('round')
            ->get()
            ->map(fn (Bout $bout) => $bout->aka_entry_id === $finalistEntryId ? $bout->ao_entry_id : $bout->aka_entry_id)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Build the repechage ladder for the half whose semifinal has just been decided. The entries defeated
     * by the finalist fight in order of elimination, the winner of the last step takes bronze.
     *
     * @return Entry|null The bronze medallist when there is no bout left to play, otherwise null
     */
    public function build(Category $category, Bout $semifinal): ?Entry
    {
        $defeated = $this->defeatedBy($category, (int) $semifinal->winner_entry_id);

        if ($defeated === []) {
            return null;
        }

        if (count($defeated) === 1) {
            return Entry::findOrFail($defeated[0]);
        }

        $steps = count($defeated) - 1;
        $next = null;

        for ($step = $steps; $step >= 1; $step--) {
            $next = $category->bouts()->create([
                'bracket' => BoutBracket::Repechage,
                'round' => $step,
                'position' => $semifinal->position,
                'aka_entry_id' => $step === 1 ? $defeated[0] : null,
                'ao_entry_id' => $defeated[$step],
                'next_bout_id' => $next?->id,
                'next_slot' => $next ? BoutSlot::Aka : null,
                'status' => $step === 1 ? BoutStatus::Ready : BoutStatus::Pending,
            ]);
        }

        return null;
    }
}
