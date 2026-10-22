<?php

namespace App\Actions\Draw;

use App\Enums\BoutBracket;
use App\Enums\BoutDecision;
use App\Enums\BoutSlot;
use App\Enums\BoutStatus;
use App\Models\Bout;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

class UndoBoutResult
{
    public function __construct(private readonly RepechageBuilder $repechage) {}

    /**
     * Revert a decided bout to ready, as long as nothing that depends on it has been played.
     *
     * @throws DrawException
     */
    public function handle(Bout $bout): void
    {
        DB::transaction(function () use ($bout): void {
            $bout = Bout::with('category')->lockForUpdate()->findOrFail($bout->id);
            $category = $bout->category;

            if ($bout->status !== BoutStatus::Finished || $bout->decision === BoutDecision::Bye) {
                throw new DrawException(__('Only a played bout can be undone.'));
            }

            $next = $bout->next_bout_id !== null ? Bout::lockForUpdate()->findOrFail($bout->next_bout_id) : null;

            if ($next?->status === BoutStatus::Finished) {
                throw new DrawException(__('The next bout has already been played. Undo it first.'));
            }

            $this->revertDependents($category, $bout);

            if ($next !== null) {
                $this->removeFromNextBout($bout, $next);
            }

            $bout->fill([
                'winner_entry_id' => null,
                'aka_score' => null,
                'ao_score' => null,
                'decision' => null,
                'status' => BoutStatus::Ready,
                'finished_at' => null,
            ])->save();
        });
    }

    /**
     * Remove what the bout produced besides moving its winner on: placements and the repechage of its half.
     *
     * @throws DrawException
     */
    private function revertDependents(Category $category, Bout $bout): void
    {
        if ($bout->bracket === BoutBracket::Repechage) {
            if ($bout->next_bout_id === null) {
                $category->placements()->where('place', 3)->where('entry_id', $bout->winner_entry_id)->delete();
            }

            return;
        }

        if ($bout->next_bout_id === null) {
            $category->placements()->whereIn('place', [1, 2])->delete();

            return;
        }

        if ($category->finalRound() < 2 || $bout->round !== $category->finalRound() - 1) {
            return;
        }

        $ladder = $category->bouts()->where('bracket', BoutBracket::Repechage)->where('position', $bout->position)->get();

        if ($ladder->contains(fn (Bout $step) => $step->status === BoutStatus::Finished)) {
            throw new DrawException(__('The repechage of this half has already started. Undo it first.'));
        }

        $defeated = $this->repechage->defeatedBy($category, (int) $bout->winner_entry_id);

        $category->placements()->where('place', 3)->whereIn('entry_id', $defeated)->delete();
        $ladder->each->delete();
    }

    private function removeFromNextBout(Bout $bout, Bout $next): void
    {
        if ($bout->next_slot === BoutSlot::Aka) {
            $next->aka_entry_id = null;
        } else {
            $next->ao_entry_id = null;
        }

        $next->status = BoutStatus::Pending;
        $next->save();
    }
}
