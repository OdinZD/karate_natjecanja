<?php

namespace App\Actions\Draw;

use App\Enums\BoutBracket;
use App\Enums\BoutDecision;
use App\Enums\BoutSlot;
use App\Enums\BoutStatus;
use App\Enums\Discipline;
use App\Models\Bout;
use App\Models\Category;
use App\Models\Entry;
use Illuminate\Support\Facades\DB;

class RecordBoutResult
{
    public function __construct(private readonly RepechageBuilder $repechage) {}

    /**
     * Record the result of a bout and move the winner on, building the repechage and placements as needed.
     *
     * @throws DrawException
     */
    public function handle(Bout $bout, BoutSlot $winner, ?int $akaScore, ?int $aoScore, BoutDecision $decision): void
    {
        DB::transaction(function () use ($bout, $winner, $akaScore, $aoScore, $decision): void {
            $bout = Bout::with('category')->lockForUpdate()->findOrFail($bout->id);

            if ($bout->status !== BoutStatus::Ready) {
                throw new DrawException(__('This bout is not ready to be played.'));
            }

            $this->validateResult($winner, $akaScore, $aoScore, $decision);

            $winnerId = $winner === BoutSlot::Aka ? $bout->aka_entry_id : $bout->ao_entry_id;
            $loserId = $winner === BoutSlot::Aka ? $bout->ao_entry_id : $bout->aka_entry_id;

            $bout->fill([
                'winner_entry_id' => $winnerId,
                'aka_score' => $akaScore,
                'ao_score' => $aoScore,
                'decision' => $decision,
                'status' => BoutStatus::Finished,
                'finished_at' => now(),
            ])->save();

            $this->progress($bout->category, $bout, (int) $winnerId, (int) $loserId);
        });
    }

    /**
     * @throws DrawException
     */
    private function validateResult(BoutSlot $winner, ?int $akaScore, ?int $aoScore, BoutDecision $decision): void
    {
        if ($decision === BoutDecision::Bye) {
            throw new DrawException(__('A bye cannot be recorded manually.'));
        }

        foreach ([$akaScore, $aoScore] as $score) {
            if ($score !== null && ($score < 0 || $score > 255)) {
                throw new DrawException(__('Scores must be between 0 and 255.'));
            }
        }

        if (in_array($decision, [BoutDecision::Points, BoutDecision::Flags], true)) {
            if ($akaScore === null || $aoScore === null) {
                throw new DrawException(__('Both scores are required for this decision.'));
            }

            $winnerScore = $winner === BoutSlot::Aka ? $akaScore : $aoScore;
            $loserScore = $winner === BoutSlot::Aka ? $aoScore : $akaScore;

            if ($winnerScore <= $loserScore) {
                throw new DrawException(__('The winner must have the higher score.'));
            }
        }
    }

    private function progress(Category $category, Bout $bout, int $winnerId, int $loserId): void
    {
        if ($bout->next_bout_id !== null) {
            $this->placeInNextBout($bout, $winnerId);
        } elseif ($bout->bracket === BoutBracket::Main) {
            $category->placements()->create(['entry_id' => $winnerId, 'place' => 1]);
            $category->placements()->create(['entry_id' => $loserId, 'place' => 2]);
        } else {
            $category->placements()->create(['entry_id' => $winnerId, 'place' => 3]);
        }

        if ($bout->bracket === BoutBracket::Main && $category->finalRound() >= 2 && $bout->round === $category->finalRound() - 1) {
            $this->finishSemifinal($category, $bout, $loserId);
        }
    }

    private function placeInNextBout(Bout $bout, int $winnerId): void
    {
        $next = Bout::lockForUpdate()->findOrFail($bout->next_bout_id);

        if ($bout->next_slot === BoutSlot::Aka) {
            $next->aka_entry_id = $winnerId;
        } else {
            $next->ao_entry_id = $winnerId;
        }

        if ($next->aka_entry_id !== null && $next->ao_entry_id !== null) {
            $next->status = BoutStatus::Ready;
        }

        $next->save();
    }

    private function finishSemifinal(Category $category, Bout $semifinal, int $loserId): void
    {
        $bronze = $category->discipline === Discipline::Kumite
            ? $this->repechage->build($category, $semifinal)
            : Entry::findOrFail($loserId);

        if ($bronze !== null) {
            $category->placements()->create(['entry_id' => $bronze->id, 'place' => 3]);
        }
    }
}
