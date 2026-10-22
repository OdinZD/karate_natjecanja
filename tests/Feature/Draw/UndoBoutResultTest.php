<?php

namespace Tests\Feature\Draw;

use App\Actions\Draw\DrawException;
use App\Actions\Draw\RecordBoutResult;
use App\Actions\Draw\UndoBoutResult;
use App\Enums\BoutBracket;
use App\Enums\BoutDecision;
use App\Enums\BoutSlot;
use App\Enums\BoutStatus;
use App\Enums\Discipline;
use App\Models\Bout;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UndoBoutResultTest extends TestCase
{
    use InteractsWithDraws;
    use RefreshDatabase;

    private function record(Bout $bout, BoutSlot $winner = BoutSlot::Aka): void
    {
        app(RecordBoutResult::class)->handle($bout->fresh(), $winner, null, null, BoutDecision::Hantei);
    }

    private function undo(Bout $bout): void
    {
        app(UndoBoutResult::class)->handle($bout->fresh());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function snapshot(Category $category): array
    {
        return $category->bouts()->orderBy('id')->get()->map->only([
            'id', 'bracket', 'round', 'position', 'aka_entry_id', 'ao_entry_id', 'winner_entry_id',
            'decision', 'status', 'next_bout_id',
        ])->map(fn (array $row) => array_map(fn ($value) => $value instanceof \BackedEnum ? $value->value : $value, $row))->all();
    }

    public function test_undoing_a_bout_restores_it_and_its_next_bout(): void
    {
        $category = $this->drawnCategory(8);
        $before = $this->snapshot($category);
        $bout = $this->bout($category, 1, 2);

        $this->record($bout, BoutSlot::Ao);
        $this->undo($bout);

        $this->assertSame($before, $this->snapshot($category));
    }

    public function test_recording_again_after_undo_gives_the_same_outcome_as_recording_once(): void
    {
        $category = $this->drawnCategory(8);
        $bout = $this->bout($category, 1, 1);

        $this->record($bout, BoutSlot::Ao);
        $this->undo($bout);
        $this->record($bout, BoutSlot::Aka);

        $this->playAll($category);

        $this->assertSame([1 => [1], 2 => [2], 3 => [3, 4]], $this->podium($category));
    }

    public function test_undo_is_refused_when_the_next_bout_has_been_played(): void
    {
        $category = $this->drawnCategory(8);

        $this->record($this->bout($category, 1, 1));
        $this->record($this->bout($category, 1, 2));
        $this->record($this->bout($category, 2, 1));

        try {
            $this->undo($this->bout($category, 1, 1));
            $this->fail('undo should have been refused');
        } catch (DrawException) {
            $this->assertSame(BoutStatus::Finished, $this->bout($category, 1, 1)->fresh()->status);
        }

        $this->undo($this->bout($category, 2, 1));
        $this->undo($this->bout($category, 1, 1));

        $this->assertSame(BoutStatus::Ready, $this->bout($category, 1, 1)->fresh()->status);
    }

    public function test_byes_and_unplayed_bouts_cannot_be_undone(): void
    {
        $category = $this->drawnCategory(5);

        $bye = $category->bouts()->where('decision', BoutDecision::Bye)->firstOrFail();
        $ready = $category->bouts()->where('status', BoutStatus::Ready)->firstOrFail();

        foreach ([$bye, $ready] as $bout) {
            try {
                $this->undo($bout);
                $this->fail('undo should have been refused');
            } catch (DrawException) {
                $this->assertSame($bout->status, $bout->fresh()->status);
            }
        }
    }

    public function test_undoing_the_final_removes_first_and_second_place(): void
    {
        $category = $this->drawnCategory(4);

        $this->playAll($category);
        $this->assertCount(2, $category->placements()->whereIn('place', [1, 2])->get());

        $this->undo($this->bout($category, 2, 1));

        $this->assertSame([3 => [3, 4]], $this->podium($category));
        $this->assertSame(BoutStatus::Ready, $this->bout($category, 2, 1)->fresh()->status);
    }

    public function test_undoing_a_semifinal_removes_its_repechage_and_bronze(): void
    {
        $category = $this->drawnCategory(8);

        $this->playAll($category);

        // Take everything back down to the first semifinal.
        $this->undo($this->bout($category, 3, 1));
        foreach ($category->bouts()->where('bracket', BoutBracket::Repechage)->where('position', 1)->orderByDesc('round')->get() as $step) {
            $this->undo($step);
        }

        $this->assertSame([3 => [3]], $this->podium($category));

        $this->undo($this->bout($category, 2, 1));

        $this->assertSame(0, $category->bouts()->where('bracket', BoutBracket::Repechage)->where('position', 1)->count());
        $this->assertSame(1, $category->bouts()->where('bracket', BoutBracket::Repechage)->where('position', 2)->count());
        $this->assertSame([3 => [3]], $this->podium($category));
        $this->assertSame(BoutStatus::Ready, $this->bout($category, 2, 1)->fresh()->status);
    }

    public function test_a_semifinal_cannot_be_undone_while_its_repechage_has_been_played(): void
    {
        $category = $this->drawnCategory(8);

        $this->playAll($category);
        $this->undo($this->bout($category, 3, 1));

        $this->expectException(DrawException::class);

        $this->undo($this->bout($category, 2, 1));
    }

    public function test_undoing_the_last_repechage_step_removes_the_bronze(): void
    {
        $category = $this->drawnCategory(8);

        $this->playAll($category);
        $this->undo($this->bout($category, 3, 1));
        $lastStep = $category->bouts()->where('bracket', BoutBracket::Repechage)->where('position', 1)->sole();

        $this->undo($lastStep);

        $this->assertSame([3 => [3]], $this->podium($category));
        $this->assertSame(BoutStatus::Ready, $lastStep->fresh()->status);
    }

    public function test_a_kumite_semifinal_with_one_defeated_opponent_loses_its_immediate_bronze(): void
    {
        $category = $this->drawnCategory(4);
        $semifinal = $this->bout($category, 1, 1);

        $this->record($semifinal);
        $this->assertSame([3 => [$semifinal->ao->seed]], $this->podium($category));

        $this->undo($semifinal);

        $this->assertSame([], $this->podium($category));
    }

    public function test_kata_semifinal_bronze_is_removed_on_undo(): void
    {
        $category = $this->drawnCategory(4, Discipline::Kata);
        $semifinal = $this->bout($category, 1, 2);

        $this->record($semifinal);
        $this->assertCount(1, $category->placements()->get());

        $this->undo($semifinal);

        $this->assertCount(0, $category->placements()->get());
    }
}
