<?php

namespace Tests\Feature\Draw;

use App\Actions\Draw\RecordBoutResult;
use App\Enums\BoutBracket;
use App\Enums\BoutDecision;
use App\Enums\BoutSlot;
use App\Enums\BoutStatus;
use App\Enums\Discipline;
use App\Models\Bout;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RepechageTest extends TestCase
{
    use InteractsWithDraws;
    use RefreshDatabase;

    /**
     * @return array<string, array{int, string, array<int, array<int, int>>}>
     */
    public static function scenarios(): array
    {
        return [
            'n=2, better seeds win' => [2, 'low', [1 => [1], 2 => [2]]],
            'n=3, better seeds win' => [3, 'low', [1 => [1], 2 => [2], 3 => [3]]],
            'n=4, better seeds win' => [4, 'low', [1 => [1], 2 => [2], 3 => [3, 4]]],
            'n=5, better seeds win' => [5, 'low', [1 => [1], 2 => [2], 3 => [3, 4]]],
            'n=6, upsets everywhere' => [6, 'high', [1 => [6], 2 => [5], 3 => [3, 4]]],
            'n=8, better seeds win' => [8, 'low', [1 => [1], 2 => [2], 3 => [3, 4]]],
            'n=16, better seeds win' => [16, 'low', [1 => [1], 2 => [2], 3 => [3, 4]]],
        ];
    }

    /**
     * @param  array<int, array<int, int>>  $podium
     */
    #[DataProvider('scenarios')]
    public function test_known_scenarios_end_with_the_expected_podium(int $entries, string $policy, array $podium): void
    {
        $category = $this->drawnCategory($entries);

        $this->playAll($category, $policy);

        $this->assertSame($podium, $this->podium($category));
        $this->assertSame(0, $category->bouts()->whereIn('status', [BoutStatus::Pending, BoutStatus::Ready])->count());
    }

    public function test_the_repechage_is_a_ladder_in_order_of_elimination(): void
    {
        $category = $this->drawnCategory(16);

        $this->playAll($category);

        $steps = $category->bouts()->where('bracket', BoutBracket::Repechage)->with(['aka', 'ao', 'winner'])->get();

        $this->assertCount(4, $steps);

        $firstHalf = $steps->where('position', 1)->sortBy('round')->values();
        $secondHalf = $steps->where('position', 2)->sortBy('round')->values();

        // Seed 1 beat 16 (round 1), 8 (quarterfinal) and 4 (semifinal).
        $this->assertSame([16, 8, 8, 4], [$firstHalf[0]->aka->seed, $firstHalf[0]->ao->seed, $firstHalf[1]->aka->seed, $firstHalf[1]->ao->seed]);
        $this->assertSame([8, 4], [$firstHalf[0]->winner->seed, $firstHalf[1]->winner->seed]);
        $this->assertSame($firstHalf[1]->id, $firstHalf[0]->next_bout_id);
        $this->assertNull($firstHalf[1]->next_bout_id);

        // Seed 2 beat 15, 7 and 3.
        $this->assertSame([15, 7, 7, 3], [$secondHalf[0]->aka->seed, $secondHalf[0]->ao->seed, $secondHalf[1]->aka->seed, $secondHalf[1]->ao->seed]);
    }

    public function test_the_ladder_is_built_when_the_semifinal_is_decided_and_waits_for_earlier_steps(): void
    {
        $category = $this->drawnCategory(16);

        // Play until the first half has produced its finalist, but leave the second half unplayed.
        $recorder = app(RecordBoutResult::class);
        $first = $this->bout($category, 3, 1);
        $quarterfinals = $category->bouts()->where('round', 2)->get();

        foreach ($category->bouts()->where('round', 1)->orderBy('position')->get() as $bout) {
            $recorder->handle($bout, $this->slotOfBetterSeed($bout), null, null, BoutDecision::Hantei);
        }
        foreach ($quarterfinals->take(2) as $bout) {
            $recorder->handle($bout->fresh(), $this->slotOfBetterSeed($bout->fresh()), null, null, BoutDecision::Hantei);
        }

        $this->assertSame(0, $category->bouts()->where('bracket', BoutBracket::Repechage)->count());

        $recorder->handle($first->fresh(), $this->slotOfBetterSeed($first->fresh()), null, null, BoutDecision::Hantei);

        $ladder = $category->bouts()->where('bracket', BoutBracket::Repechage)->orderBy('round')->get();

        $this->assertCount(2, $ladder);
        $this->assertSame(BoutStatus::Ready, $ladder[0]->status);
        $this->assertSame(BoutStatus::Pending, $ladder[1]->status);
        $this->assertNull($ladder[1]->aka_entry_id);
        $this->assertNotNull($ladder[1]->ao_entry_id);
    }

    private function slotOfBetterSeed(Bout $bout): BoutSlot
    {
        return $bout->aka->seed < $bout->ao->seed ? BoutSlot::Aka : BoutSlot::Ao;
    }

    /**
     * @return array<string, array{int, Discipline}>
     */
    public static function simulations(): array
    {
        $cases = [];

        foreach ([Discipline::Kumite, Discipline::Kata] as $discipline) {
            foreach (range(2, 20) as $entries) {
                $cases["{$discipline->value} n=$entries"] = [$entries, $discipline];
            }
        }

        return $cases;
    }

    #[DataProvider('simulations')]
    public function test_random_results_always_end_with_a_consistent_podium(int $entries, Discipline $discipline): void
    {
        mt_srand($entries * 31 + 7);

        $category = $this->drawnCategory($entries, $discipline, seeded: false);

        $this->playAll($category, 'random');

        $this->assertSame(0, $category->bouts()->whereIn('status', [BoutStatus::Pending, BoutStatus::Ready])->count());

        $placements = $category->placements()->get();
        $expectedBronze = match (true) {
            $entries === 2 => 0,
            $entries === 3 => 1,
            default => 2,
        };

        $this->assertCount(1, $placements->where('place', 1));
        $this->assertCount(1, $placements->where('place', 2));
        $this->assertCount($expectedBronze, $placements->where('place', 3));
        $this->assertSame($placements->count(), $placements->pluck('entry_id')->unique()->count(), 'an entry has two placements');

        $final = $category->bouts()->where('bracket', BoutBracket::Main)->where('round', $category->finalRound())->sole();
        $this->assertSame($final->winner_entry_id, $placements->firstWhere('place', 1)->entry_id);

        $ladders = $category->bouts()->where('bracket', BoutBracket::Repechage)->get();

        if ($discipline === Discipline::Kata) {
            $this->assertCount(0, $ladders);

            return;
        }

        if ($category->finalRound() < 2) {
            return;
        }

        foreach ([1, 2] as $half) {
            $semifinal = $this->bout($category, $category->finalRound() - 1, $half);
            $defeated = $category->bouts()
                ->where('bracket', BoutBracket::Main)
                ->where('winner_entry_id', $semifinal->winner_entry_id)
                ->where('round', '<=', $semifinal->round)
                ->where('decision', '!=', 'bye')
                ->count();

            $this->assertSame(max($defeated - 1, 0), $ladders->where('position', $half)->count(), "ladder of half $half");
        }
    }

    /**
     * @return array<int, array{int, int, int}> [half, aka seed, ao seed] of every ladder step, in play order
     */
    private function ladderPairs(Category $category): array
    {
        return $category->bouts()->where('bracket', BoutBracket::Repechage)->with(['aka', 'ao'])
            ->orderBy('position')->orderBy('round')->get()
            ->map(fn (Bout $bout) => [$bout->position, $bout->aka->seed, $bout->ao->seed])
            ->all();
    }

    public function test_ladder_pairs_for_eight_entries(): void
    {
        $category = $this->drawnCategory(8);
        $this->playAll($category);

        $this->assertSame([[1, 8, 4], [2, 7, 3]], $this->ladderPairs($category));
        $this->assertSame([4, 3], $category->bouts()->where('bracket', BoutBracket::Repechage)->orderBy('position')->get()->map(fn (Bout $bout) => $bout->winner->seed)->all());
        $this->assertSame(0, $category->bouts()->where('bracket', BoutBracket::Repechage)->whereNotNull('next_bout_id')->count());
    }

    public function test_ladder_pairs_for_six_entries_with_upsets(): void
    {
        $category = $this->drawnCategory(6);
        $this->playAll($category, 'high');

        $this->assertSame([[1, 4, 1], [2, 3, 2]], $this->ladderPairs($category));
        $this->assertSame([4, 3], $category->bouts()->where('bracket', BoutBracket::Repechage)->orderBy('position')->get()->map(fn (Bout $bout) => $bout->winner->seed)->all());
    }

    public function test_no_ladder_is_needed_for_three_four_or_five_entries(): void
    {
        foreach ([3, 4, 5] as $entries) {
            $category = $this->drawnCategory($entries);
            $this->playAll($category);

            $this->assertSame(0, $category->bouts()->where('bracket', BoutBracket::Repechage)->count(), "n=$entries");
        }
    }
}
