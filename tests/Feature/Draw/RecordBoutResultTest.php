<?php

namespace Tests\Feature\Draw;

use App\Actions\Draw\DrawException;
use App\Actions\Draw\RecordBoutResult;
use App\Enums\BoutDecision;
use App\Enums\BoutSlot;
use App\Enums\BoutStatus;
use App\Enums\Discipline;
use App\Models\Bout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordBoutResultTest extends TestCase
{
    use InteractsWithDraws;
    use RefreshDatabase;

    private function record(Bout $bout, BoutSlot $winner, ?int $aka, ?int $ao, BoutDecision $decision): void
    {
        app(RecordBoutResult::class)->handle($bout, $winner, $aka, $ao, $decision);
    }

    public function test_the_winner_advances_and_the_next_bout_becomes_ready_when_both_entrants_are_known(): void
    {
        $category = $this->drawnCategory(4);
        $final = $this->bout($category, 2, 1);

        $this->assertSame(BoutStatus::Pending, $final->status);

        $first = $this->bout($category, 1, 1);
        $this->record($first, BoutSlot::Aka, 3, 1, BoutDecision::Points);

        $first->refresh();
        $final->refresh();

        $this->assertSame(BoutStatus::Finished, $first->status);
        $this->assertSame($first->aka_entry_id, $first->winner_entry_id);
        $this->assertSame(3, $first->aka_score);
        $this->assertSame(BoutDecision::Points, $first->decision);
        $this->assertNotNull($first->finished_at);
        $this->assertSame($first->winner_entry_id, $final->aka_entry_id);
        $this->assertNull($final->ao_entry_id);
        $this->assertSame(BoutStatus::Pending, $final->status);

        $second = $this->bout($category, 1, 2);
        $this->record($second, BoutSlot::Ao, 0, 2, BoutDecision::Points);

        $final->refresh();

        $this->assertSame($second->ao_entry_id, $final->ao_entry_id);
        $this->assertSame(BoutStatus::Ready, $final->status);
    }

    public function test_the_final_gives_first_and_second_place(): void
    {
        $category = $this->drawnCategory(2);
        $final = $this->bout($category, 1, 1);

        $this->record($final, BoutSlot::Ao, 1, 4, BoutDecision::Points);

        $this->assertSame([1 => [$final->ao->seed], 2 => [$final->aka->seed]], $this->podium($category));
    }

    public function test_a_kumite_semifinal_with_a_single_defeated_opponent_gives_bronze_immediately(): void
    {
        $category = $this->drawnCategory(4);
        $semifinal = $this->bout($category, 1, 1);

        $this->record($semifinal, BoutSlot::Aka, 2, 0, BoutDecision::Points);

        $this->assertSame([3 => [$semifinal->ao->seed]], $this->podium($category));
        $this->assertSame(0, $category->bouts()->where('bracket', 'repechage')->count());
    }

    public function test_kata_semifinal_losers_get_bronze_immediately_and_there_is_no_repechage(): void
    {
        $category = $this->drawnCategory(8, Discipline::Kata);

        foreach ([1, 2, 3, 4] as $position) {
            $this->record($this->bout($category, 1, $position), BoutSlot::Aka, null, null, BoutDecision::Hantei);
        }

        $this->record($this->bout($category, 2, 1), BoutSlot::Aka, null, null, BoutDecision::Hantei);
        $this->assertSame([3 => [4]], $this->podium($category));

        $this->record($this->bout($category, 2, 2), BoutSlot::Aka, null, null, BoutDecision::Hantei);
        $this->assertSame([3 => [3, 4]], $this->podium($category), 'both bronzes exist before the final');

        $this->playAll($category);

        $this->assertSame(0, $category->bouts()->where('bracket', 'repechage')->count());
        $this->assertSame([1 => [1], 2 => [2], 3 => [3, 4]], $this->podium($category));
    }

    public function test_points_and_flags_need_both_scores_and_a_higher_winner_score(): void
    {
        $category = $this->drawnCategory(2);
        $final = $this->bout($category, 1, 1);

        foreach ([BoutDecision::Points, BoutDecision::Flags] as $decision) {
            foreach ([[null, null], [3, null], [2, 2], [1, 3]] as [$aka, $ao]) {
                try {
                    $this->record($final, BoutSlot::Aka, $aka, $ao, $decision);
                    $this->fail("{$decision->value} {$aka}:{$ao} should have been refused");
                } catch (DrawException) {
                    $this->assertSame(BoutStatus::Ready, $final->fresh()->status);
                }
            }
        }

        $this->record($final, BoutSlot::Aka, 3, 2, BoutDecision::Flags);

        $this->assertSame(BoutStatus::Finished, $final->fresh()->status);
    }

    public function test_hantei_kiken_and_shikkaku_do_not_need_scores(): void
    {
        foreach ([BoutDecision::Hantei, BoutDecision::Kiken, BoutDecision::Shikkaku] as $decision) {
            $category = $this->drawnCategory(2);
            $final = $this->bout($category, 1, 1);

            $this->record($final, BoutSlot::Aka, null, null, $decision);

            $this->assertSame($decision, $final->fresh()->decision);
        }
    }

    public function test_a_bye_cannot_be_recorded_and_scores_are_bounded(): void
    {
        $final = $this->bout($this->drawnCategory(2), 1, 1);

        foreach ([[BoutDecision::Bye, null, null], [BoutDecision::Points, 300, 1], [BoutDecision::Hantei, -1, 0]] as [$decision, $aka, $ao]) {
            try {
                $this->record($final, BoutSlot::Aka, $aka, $ao, $decision);
                $this->fail('should have been refused');
            } catch (DrawException) {
                $this->assertSame(BoutStatus::Ready, $final->fresh()->status);
            }
        }
    }

    public function test_only_ready_bouts_can_be_recorded(): void
    {
        $category = $this->drawnCategory(4);

        try {
            $this->record($this->bout($category, 2, 1), BoutSlot::Aka, 1, 0, BoutDecision::Points);
            $this->fail('a pending bout must be refused');
        } catch (DrawException) {
            $this->assertSame(0, $category->placements()->count());
        }

        $first = $this->bout($category, 1, 1);
        $this->record($first, BoutSlot::Aka, 1, 0, BoutDecision::Points);

        $this->expectException(DrawException::class);

        $this->record($first, BoutSlot::Ao, 0, 1, BoutDecision::Points);
    }
}
