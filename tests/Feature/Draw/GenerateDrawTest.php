<?php

namespace Tests\Feature\Draw;

use App\Actions\Draw\DeleteDraw;
use App\Actions\Draw\DrawArranger;
use App\Actions\Draw\DrawException;
use App\Actions\Draw\GenerateDraw;
use App\Enums\BoutBracket;
use App\Enums\BoutDecision;
use App\Enums\BoutSlot;
use App\Enums\BoutStatus;
use App\Models\Bout;
use App\Models\Category;
use App\Models\Entry;
use App\Models\Placement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\TestCase;

class GenerateDrawTest extends TestCase
{
    use RefreshDatabase;

    private function category(int $entries): Category
    {
        $category = Category::factory()->create();
        Entry::factory()->count($entries)->for($category)->create();

        return $category;
    }

    private function generate(Category $category, int $seed = 1): void
    {
        (new GenerateDraw(new DrawArranger, new Randomizer(new Mt19937($seed))))->handle($category);
    }

    public function test_a_bracket_of_n_minus_one_bouts_is_created_and_linked(): void
    {
        $category = $this->category(5);

        $this->generate($category);

        $bouts = $category->bouts()->get();

        $this->assertCount(7, $bouts);
        $this->assertSame([4, 2, 1], array_map(
            fn (int $round) => $bouts->where('round', $round)->count(),
            [1, 2, 3],
        ));
        $this->assertTrue($bouts->every(fn (Bout $bout) => $bout->bracket === BoutBracket::Main));

        foreach ($bouts->where('round', '<', 3) as $bout) {
            $next = $bouts->firstWhere('id', $bout->next_bout_id);

            $this->assertNotNull($next);
            $this->assertSame($bout->round + 1, $next->round);
            $this->assertSame(intdiv($bout->position + 1, 2), $next->position);
            $this->assertSame($bout->position % 2 === 1 ? BoutSlot::Aka : BoutSlot::Ao, $bout->next_slot);
        }

        $final = $bouts->firstWhere('round', 3);
        $this->assertNull($final->next_bout_id);
        $this->assertNull($final->next_slot);
    }

    public function test_byes_are_finished_and_their_winners_advance(): void
    {
        $category = $this->category(5);

        $this->generate($category);

        $byes = $category->bouts()->where('decision', BoutDecision::Bye)->get();

        $this->assertCount(3, $byes);

        foreach ($byes as $bye) {
            $this->assertSame(BoutStatus::Finished, $bye->status);
            $this->assertNotNull($bye->winner_entry_id);
            $this->assertTrue(($bye->aka_entry_id === null) !== ($bye->ao_entry_id === null));

            $next = Bout::findOrFail($bye->next_bout_id);
            $this->assertSame(
                $bye->winner_entry_id,
                $bye->next_slot === BoutSlot::Aka ? $next->aka_entry_id : $next->ao_entry_id,
            );
        }
    }

    public function test_only_bouts_with_two_entrants_are_ready(): void
    {
        $category = $this->category(6);

        $this->generate($category);

        foreach ($category->bouts()->get() as $bout) {
            $hasBoth = $bout->aka_entry_id !== null && $bout->ao_entry_id !== null;

            if ($bout->status === BoutStatus::Finished) {
                $this->assertSame(BoutDecision::Bye, $bout->decision);
            } else {
                $this->assertSame($hasBoth ? BoutStatus::Ready : BoutStatus::Pending, $bout->status);
            }
        }
    }

    public function test_every_entry_is_in_exactly_one_first_round_bout(): void
    {
        $category = $this->category(11);

        $this->generate($category);

        $placed = $category->bouts()->where('round', 1)->get()
            ->flatMap(fn (Bout $bout) => [$bout->aka_entry_id, $bout->ao_entry_id])
            ->filter()
            ->sort()
            ->values()
            ->all();

        $this->assertSame($category->entries()->orderBy('id')->pluck('id')->all(), $placed);
    }

    public function test_two_entries_make_a_single_final(): void
    {
        $category = $this->category(2);

        $this->generate($category);

        $bout = $category->bouts()->sole();

        $this->assertSame(1, $bout->round);
        $this->assertSame(BoutStatus::Ready, $bout->status);
        $this->assertNull($bout->next_bout_id);
    }

    public function test_regenerating_replaces_the_draw(): void
    {
        $category = $this->category(8);

        $this->generate($category, 1);
        $firstIds = $category->bouts()->pluck('id')->all();

        $this->generate($category, 2);

        $this->assertCount(7, $category->bouts()->get());
        $this->assertEmpty(array_intersect($firstIds, $category->bouts()->pluck('id')->all()));
    }

    public function test_regenerating_is_refused_once_a_bout_has_been_played(): void
    {
        $category = $this->category(4);
        $this->generate($category);

        $bout = $category->bouts()->where('status', BoutStatus::Ready)->firstOrFail();
        $bout->update([
            'winner_entry_id' => $bout->aka_entry_id,
            'decision' => BoutDecision::Hantei,
            'status' => BoutStatus::Finished,
        ]);
        $ids = $category->bouts()->pluck('id')->all();

        $this->expectException(DrawException::class);

        try {
            $this->generate($category);
        } finally {
            $this->assertSame($ids, $category->bouts()->pluck('id')->all());
        }
    }

    public function test_fewer_than_two_entries_cannot_be_drawn(): void
    {
        foreach ([0, 1] as $entries) {
            $category = $this->category($entries);

            try {
                $this->generate($category);
                $this->fail('Expected a DrawException');
            } catch (DrawException) {
                $this->assertFalse($category->hasDraw());
            }
        }
    }

    public function test_regenerating_clears_old_placements_and_the_draw_can_be_deleted(): void
    {
        $category = $this->category(4);
        $this->generate($category);
        Placement::factory()->create(['category_id' => $category->id, 'entry_id' => $category->entries()->first()->id]);

        $this->generate($category);
        $this->assertSame(0, $category->placements()->count());

        app(DeleteDraw::class)->handle($category);

        $this->assertFalse($category->hasDraw());
    }

    public function test_a_played_draw_cannot_be_deleted(): void
    {
        $category = $this->category(4);
        $this->generate($category);

        $bout = $category->bouts()->where('status', BoutStatus::Ready)->firstOrFail();
        $bout->update([
            'winner_entry_id' => $bout->aka_entry_id,
            'decision' => BoutDecision::Points,
            'status' => BoutStatus::Finished,
        ]);

        $this->expectException(DrawException::class);

        app(DeleteDraw::class)->handle($category);
    }
}
