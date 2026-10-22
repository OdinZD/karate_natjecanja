<?php

namespace Tests\Feature\Tatami;

use App\Actions\Draw\RecordBoutResult;
use App\Enums\BoutDecision;
use App\Enums\BoutSlot;
use App\Enums\BoutStatus;
use App\Enums\Discipline;
use App\Models\Category;
use App\Models\Placement;
use App\Models\Tatami;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Draw\InteractsWithDraws;
use Tests\TestCase;

class ConsoleTest extends TestCase
{
    use InteractsWithDraws;
    use RefreshDatabase;

    private User $operator;

    private Tatami $tatami;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        $this->operator = User::factory()->create();
        $this->actingAs($this->operator);
    }

    /**
     * A drawn category (seeded 1…n) scheduled on the test tatami, which is created on first use.
     */
    private function scheduled(int $entries, Discipline $discipline = Discipline::Kumite): Category
    {
        $category = $this->drawnCategory($entries, $discipline);

        $this->tatami ??= Tatami::factory()->for($category->competition)->create();
        $category->update(['tatami_id' => $this->tatami->id, 'competition_id' => $this->tatami->competition_id, 'sort_order' => Category::where('tatami_id', $this->tatami->id)->count() + 1]);
        $this->tatami->operators()->syncWithoutDetaching([$this->operator->id]);

        return $category->refresh();
    }

    private function console(): Testable
    {
        return Livewire::test('pages::tatami.console', ['tatami' => $this->tatami]);
    }

    private function play(Testable $console, string $winner = 'aka', string $decision = 'hantei', string $aka = '', string $ao = ''): Testable
    {
        return $console
            ->set('winner', $winner)
            ->set('decision', $decision)
            ->set('akaScore', $aka)
            ->set('aoScore', $ao)
            ->call('record', $console->instance()->queue?->current?->id ?? 0);
    }

    private function toast(string $text): \Closure
    {
        return fn (string $event, array $params) => str_contains(json_encode($params), $text);
    }

    private function playBetterSeed(Testable $console): Testable
    {
        $current = $console->instance()->queue->current;

        return $this->play($console, $current->aka->seed < $current->ao->seed ? 'aka' : 'ao');
    }

    public function test_the_first_ready_bout_is_current_and_the_rest_are_upcoming(): void
    {
        $category = $this->scheduled(4);

        $console = $this->console();
        $queue = $console->instance()->queue;

        $this->assertSame($category->id, $console->get('categoryId'));
        $this->assertTrue($queue->current->is($this->bout($category, 1, 1)));
        $this->assertSame(
            [$this->bout($category, 1, 2)->id, $this->bout($category, 2, 1)->id],
            $queue->upcoming->pluck('id')->all(),
        );
        $this->assertCount(0, $queue->recent);
    }

    public function test_recording_a_result_moves_the_winner_on_and_offers_the_next_bout(): void
    {
        $category = $this->scheduled(4);
        $console = $this->console();

        $this->play($console, 'ao', 'points', '1', '3')->assertHasNoErrors();

        $first = $this->bout($category, 1, 1)->fresh();
        $this->assertSame(BoutStatus::Finished, $first->status);
        $this->assertSame($first->ao_entry_id, $first->winner_entry_id);
        $this->assertSame(1, $first->aka_score);
        $this->assertSame(3, $first->ao_score);

        $this->assertSame($first->ao_entry_id, $this->bout($category, 2, 1)->aka_entry_id);

        $queue = $console->instance()->queue;
        $this->assertTrue($queue->current->is($this->bout($category, 1, 2)));
        $this->assertSame([$first->id], $queue->recent->pluck('id')->all());
        $console->assertSet('winner', '');
    }

    public function test_the_form_is_validated(): void
    {
        $category = $this->scheduled(2);
        $console = $this->console();

        $this->play($console, '', 'points')->assertHasErrors(['winner' => 'required']);
        $this->play($console, 'aka', 'bye')->assertHasErrors('decision');
        $this->play($console, 'aka', 'points', '300', '1')->assertHasErrors('akaScore');
        $this->play($console, 'aka', 'points', 'x', '1')->assertHasErrors('akaScore');

        $this->assertSame(0, $category->bouts()->where('status', BoutStatus::Finished)->count());
    }

    public function test_result_rules_of_the_engine_are_shown_as_messages(): void
    {
        $category = $this->scheduled(2);
        $console = $this->console();

        $this->play($console, 'aka', 'points')->assertDispatched('toast-show', $this->toast('Both scores are required'));
        $this->play($console, 'aka', 'points', '2', '2')->assertDispatched('toast-show', $this->toast('higher score'));
        $this->play($console, 'ao', 'flags', '4', '1')->assertDispatched('toast-show', $this->toast('higher score'));

        $this->assertSame(0, $category->bouts()->where('status', BoutStatus::Finished)->count());
        $this->assertSame(0, Placement::count());
    }

    public function test_the_default_decision_follows_the_discipline(): void
    {
        $this->scheduled(2, Discipline::Kata);
        $this->console()->assertSet('decision', 'flags');

        $this->tatami = Tatami::factory()->for($this->tatami->competition)->create();
        $this->scheduled(2, Discipline::Kumite);

        $this->console()->assertSet('decision', 'points');
    }

    public function test_the_newest_result_can_be_undone(): void
    {
        $category = $this->scheduled(4);
        $console = $this->console();

        $this->play($console);
        $console->call('undo', $this->bout($category, 1, 1)->id);

        $this->assertSame(BoutStatus::Ready, $this->bout($category, 1, 1)->fresh()->status);
        $this->assertNull($this->bout($category, 2, 1)->fresh()->aka_entry_id);

        $this->play($console);
        $this->play($console);
        $this->play($console);
        $this->assertSame(BoutStatus::Finished, $this->bout($category, 2, 1)->fresh()->status);

        // Within the same second the final counts as the newest result, so undo takes it back first.
        $console->call('undo', $this->bout($category, 2, 1)->id);

        $this->assertSame(BoutStatus::Ready, $this->bout($category, 2, 1)->fresh()->status);
        $this->assertSame(BoutStatus::Finished, $this->bout($category, 1, 1)->fresh()->status);
        $this->assertSame(0, $category->placements()->whereIn('place', [1, 2])->count());
    }

    public function test_an_undo_that_the_engine_refuses_shows_a_message_instead_of_failing(): void
    {
        $category = $this->scheduled(4);
        $console = $this->console();

        $this->play($console);
        $this->play($console);
        $this->play($console);

        // Make the first semifinal look like the newest result although the final was played after it.
        $this->bout($category, 1, 1)->update(['finished_at' => now()->addMinute()]);
        $console->call('$refresh');

        $console->call('undo', $this->bout($category, 1, 1)->id)
            ->assertHasNoErrors()
            ->assertDispatched('toast-show', fn (string $event, array $params) => str_contains(json_encode($params), 'next bout has already been played'));

        $this->assertSame(BoutStatus::Finished, $this->bout($category, 1, 1)->fresh()->status);
        $this->assertSame(BoutStatus::Finished, $this->bout($category, 2, 1)->fresh()->status);
    }

    public function test_the_console_moves_on_to_the_next_unfinished_category(): void
    {
        $first = $this->scheduled(2);
        $second = $this->scheduled(2);
        $console = $this->console();

        $console->assertSet('categoryId', $first->id);

        $this->play($console);

        $console->assertSet('categoryId', $second->id);
    }

    public function test_a_category_can_be_played_through_to_the_podium(): void
    {
        $category = $this->scheduled(8);
        $console = $this->console();

        for ($i = 0; $i < 30 && $console->instance()->queue->current !== null; $i++) {
            $this->playBetterSeed($console);
        }

        $this->assertNull($console->instance()->queue->current);
        $this->assertSame(0, $category->bouts()->whereIn('status', [BoutStatus::Pending, BoutStatus::Ready])->count());
        $this->assertSame([1 => [1], 2 => [2], 3 => [3, 4]], $this->podium($category));
        $console->assertSee('Category finished');
    }

    public function test_a_category_of_another_tatami_cannot_be_used(): void
    {
        $this->scheduled(2);
        $foreign = $this->drawnCategory(2);

        $foreignBout = $foreign->bouts()->firstOrFail();

        $this->console()->set('categoryId', $foreign->id)->call('record', $foreignBout->id)->assertStatus(404);
        $this->console()->set('categoryId', $foreign->id)->call('undo', $foreignBout->id)->assertStatus(404);

        $this->assertSame(0, $foreign->bouts()->where('status', BoutStatus::Finished)->count());
    }

    public function test_a_category_without_a_draw_and_an_empty_tatami_explain_themselves(): void
    {
        $empty = Tatami::factory()->create();
        $empty->operators()->attach($this->operator);

        Livewire::test('pages::tatami.console', ['tatami' => $empty])->assertSee('No categories are scheduled');

        $category = Category::factory()->for($empty->competition)->create(['tatami_id' => $empty->id]);

        Livewire::test('pages::tatami.console', ['tatami' => $empty])
            ->assertSet('categoryId', $category->id)
            ->assertSee('No draw yet');
    }

    public function test_the_current_and_next_bouts_are_shown_by_name(): void
    {
        $category = $this->scheduled(4);
        $first = $this->bout($category, 1, 1)->load(['aka.competitor', 'ao.competitor']);
        $second = $this->bout($category, 1, 2)->load(['aka.competitor', 'ao.competitor']);

        $this->console()
            ->assertSee($first->aka->competitor->last_name)
            ->assertSee($first->ao->competitor->last_name)
            ->assertSee($second->aka->competitor->last_name)
            ->assertSee($second->ao->competitor->last_name)
            ->assertSee('Now')
            ->assertSee('Next');
    }

    public function test_a_stale_form_does_not_record_onto_the_next_bout(): void
    {
        $category = $this->scheduled(4);
        $console = $this->console();
        $shown = $console->instance()->queue->current;

        // Another device records the bout that this screen is still showing.
        app(RecordBoutResult::class)->handle($shown, BoutSlot::Aka, null, null, BoutDecision::Hantei);

        $console
            ->set('winner', 'ao')
            ->set('decision', 'hantei')
            ->call('record', $shown->id)
            ->assertDispatched('toast-show', $this->toast('no longer the current bout'));

        $this->assertSame(BoutStatus::Ready, $this->bout($category, 1, 2)->fresh()->status);
        $this->assertSame($shown->aka_entry_id, $shown->fresh()->winner_entry_id);
    }

    public function test_a_bout_of_another_tatami_cannot_be_recorded_or_undone(): void
    {
        $this->scheduled(2);
        $foreign = $this->drawnCategory(2);
        $foreignBout = $foreign->bouts()->firstOrFail();

        $this->console()->set('winner', 'aka')->call('record', $foreignBout->id)->assertStatus(404);
        $this->console()->call('undo', $foreignBout->id)->assertStatus(404);

        $this->assertSame(BoutStatus::Ready, $foreignBout->fresh()->status);
    }

    public function test_only_the_newest_result_of_the_whole_tatami_can_be_undone(): void
    {
        $first = $this->scheduled(2);
        $second = $this->scheduled(2);
        $console = $this->console();

        $this->play($console);
        $this->play($console);
        $firstFinal = $this->bout($first, 1, 1);
        $secondFinal = $this->bout($second, 1, 1);

        // The second category was played later, so only its result can be taken back.
        $firstFinal->update(['finished_at' => now()->subMinute()]);
        $console->set('categoryId', $first->id)->call('$refresh');

        $console
            ->assertSeeHtml('wire:click="undo('.$secondFinal->id.')"')
            ->assertDontSeeHtml('wire:click="undo('.$firstFinal->id.')"')
            ->call('undo', $firstFinal->id)
            ->assertDispatched('toast-show', $this->toast('no longer the newest result'));

        $this->assertSame(BoutStatus::Finished, $firstFinal->fresh()->status);
        $this->assertSame(1, $first->placements()->where('place', 1)->count());

        $console->call('undo', $secondFinal->id);

        $this->assertSame(BoutStatus::Ready, $secondFinal->fresh()->status);
    }

    public function test_the_result_that_moved_the_console_on_can_still_be_undone_and_reopens_its_category(): void
    {
        $first = $this->scheduled(2);
        $second = $this->scheduled(2);
        $console = $this->console();
        $firstFinal = $this->bout($first, 1, 1);

        $this->play($console);

        $console
            ->assertSet('categoryId', $second->id)
            ->assertSee('Last result')
            ->assertSeeHtml('wire:click="undo('.$firstFinal->id.')"')
            ->call('undo', $firstFinal->id)
            ->assertSet('categoryId', $first->id);

        $this->assertSame(BoutStatus::Ready, $firstFinal->fresh()->status);
        $this->assertSame(0, $first->placements()->count());
    }

    public function test_the_console_skips_categories_without_a_draw_when_moving_on(): void
    {
        $first = $this->scheduled(2);
        $undrawn = Category::factory()->for($this->tatami->competition)->create(['tatami_id' => $this->tatami->id, 'sort_order' => 50]);
        $last = $this->scheduled(2);
        $last->update(['sort_order' => 99]);
        $console = $this->console();

        $this->play($console);

        $console->assertSet('categoryId', $last->id);
        $this->assertNotSame($undrawn->id, $console->get('categoryId'));
        $this->assertNotSame($first->id, $console->get('categoryId'));
    }
}
