<?php

namespace Tests\Feature\Public;

use App\Actions\Draw\RecordBoutResult;
use App\Enums\BoutDecision;
use App\Enums\BoutSlot;
use App\Enums\CompetitionStatus;
use App\Enums\Discipline;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Tatami;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Draw\InteractsWithDraws;
use Tests\TestCase;

class DrawBracketTimetableTest extends TestCase
{
    use InteractsWithDraws;
    use RefreshDatabase;

    private Competition $competition;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        $this->competition = Competition::factory()->create([
            'status' => CompetitionStatus::InProgress,
            'date' => '2026-12-05',
            'start_time' => '10:00:00',
        ]);
    }

    private function play(Category $category, int $round, int $position, BoutSlot $winner = BoutSlot::Aka): void
    {
        app(RecordBoutResult::class)->handle($this->bout($category, $round, $position), $winner, null, null, BoutDecision::Hantei);
    }

    private function category(string $name, int $entries, bool $drawn = true): Category
    {
        $category = $drawn
            ? $this->drawnCategory($entries, competition: $this->competition)
            : Category::factory()->for($this->competition)->create();

        $category->update(['name' => $name]);

        return $category;
    }

    public function test_the_draw_page_shows_the_state_of_every_category_and_links_only_drawn_ones(): void
    {
        $undrawn = $this->category('Undrawn Category', 0, drawn: false);
        $pending = $this->category('Pending Category', 4);
        $running = $this->category('Running Category', 4);
        $this->play($running, 1, 1);
        $finished = $this->category('Finished Category', 2);
        $this->playAll($finished);

        $response = $this->get(route('public.competitions.draw', $this->competition))->assertOk();

        $response
            ->assertSee('No draw')
            ->assertSee('Draw done')
            ->assertSee('In progress')
            ->assertSee(route('public.competitions.bracket', [$this->competition, $pending]), false)
            ->assertSee(route('public.competitions.bracket', [$this->competition, $running]), false)
            ->assertSee(route('public.competitions.bracket', [$this->competition, $finished]), false)
            ->assertDontSee(route('public.competitions.bracket', [$this->competition, $undrawn]), false);

        $this->assertSame(4, substr_count($response->getContent(), 'data-test="category-row"'));
    }

    public function test_only_running_categories_are_in_the_live_block(): void
    {
        $this->category('Waiting Category', 4);
        $running = $this->category('Busy Category', 4);
        $this->play($running, 1, 1);

        $html = $this->get(route('public.competitions.draw', $this->competition))->getContent();

        preg_match('/data-test="live-categories".*?<\/div>\s*<\/div>/s', $html, $block);

        $this->assertStringContainsString('Busy Category', $block[0]);
        $this->assertStringNotContainsString('Waiting Category', $block[0]);
        $this->assertStringContainsString('Being played now', $html);
    }

    public function test_the_bracket_page_shows_names_scores_repechage_and_the_podium(): void
    {
        $category = $this->category('Cadets', 8);
        $this->playAll($category);
        $winner = $category->placements()->where('place', 1)->firstOrFail()->entry->competitor;

        $this->get(route('public.competitions.bracket', [$this->competition, $category]))
            ->assertOk()
            ->assertSee('Cadets')
            ->assertSee('Quarterfinals')
            ->assertSee('Repechage')
            ->assertSee('1st place')
            ->assertSee($winner->last_name);
    }

    public function test_exactly_one_bout_is_marked_as_current_and_the_mark_moves_on(): void
    {
        $category = $this->category('Cadets', 4);
        $url = route('public.competitions.bracket', [$this->competition, $category]);

        $html = $this->get($url)->getContent();
        $this->assertSame(1, substr_count($html, 'data-test="current-bout"'));

        $first = $this->bout($category, 1, 1)->load(['aka.competitor', 'ao.competitor']);
        $this->play($category, 1, 1);
        $page = Livewire::test('pages::public.competitions.bracket', ['publicCompetition' => $this->competition, 'category' => $category]);
        $this->assertSame($this->bout($category, 1, 2)->id, $page->instance()->currentBoutId);

        $this->playAll($category);

        $this->assertSame(0, substr_count($this->get($url)->getContent(), 'data-test="current-bout"'));
        $this->assertNotNull($first);
    }

    public function test_a_category_without_a_draw_says_so(): void
    {
        $category = $this->category('Undrawn', 0, drawn: false);

        $this->get(route('public.competitions.bracket', [$this->competition, $category]))
            ->assertOk()
            ->assertSee('The draw has not been published yet.')
            ->assertDontSee('data-test="current-bout"', false);
    }

    public function test_the_admin_bracket_has_no_current_marker(): void
    {
        $category = $this->drawnCategory(4, competition: $this->competition);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.competitions.bracket', [$this->competition, $category]))
            ->assertOk()
            ->assertDontSee('data-test="current-bout"', false);
    }

    public function test_the_time_table_shows_estimated_times_and_what_is_on_now(): void
    {
        $tatami = Tatami::factory()->for($this->competition)->create(['number' => 1, 'name' => 'Main mat']);
        $category = $this->category('Cadets', 8);
        $category->update(['tatami_id' => $tatami->id, 'sort_order' => 1]);

        $first = $this->bout($category, 1, 1)->load(['aka.competitor', 'ao.competitor']);
        $second = $this->bout($category, 1, 2)->load(['aka.competitor', 'ao.competitor']);
        $third = $this->bout($category, 1, 3)->load(['aka.competitor', 'ao.competitor']);

        $html = $this->get(route('public.competitions.timetable', $this->competition))->assertOk()->getContent();

        $this->assertStringContainsString('Tatami 1', $html);
        $this->assertStringContainsString('Main mat', $html);
        $this->assertStringContainsString('10:00–10:27', $html);
        $this->assertStringContainsString('Estimated end 10:27', $html);
        $this->assertStringContainsString('Cadets', $html);

        preg_match('/data-test="now-playing".*?data-test="next-bouts".*?<\/div>\s*<\/div>/s', $html, $block);

        foreach ([$first->aka, $first->ao, $second->aka, $second->ao, $third->aka, $third->ao] as $entry) {
            $this->assertStringContainsString(e($entry->competitor->last_name), $block[0]);
        }

        $this->assertStringContainsString('AKA', $block[0]);
        $this->assertStringContainsString('AO', $block[0]);
    }

    public function test_a_tatami_with_nothing_active_says_so_and_unpublished_tables_say_so(): void
    {
        $this->get(route('public.competitions.timetable', $this->competition))
            ->assertOk()
            ->assertSee('The time table has not been published yet.');

        $tatami = Tatami::factory()->for($this->competition)->create(['number' => 1]);
        $finished = $this->category('Done', 2);
        $this->playAll($finished);
        $finished->update(['tatami_id' => $tatami->id, 'sort_order' => 1, 'discipline' => Discipline::Kata]);

        $this->get(route('public.competitions.timetable', $this->competition))
            ->assertOk()
            ->assertSee('Nothing is being played on this tatami right now.')
            ->assertSee('Finished');
    }

    public function test_only_scheduled_categories_appear_in_the_time_table(): void
    {
        $tatami = Tatami::factory()->for($this->competition)->create();
        $this->category('Scheduled One', 2)->update(['tatami_id' => $tatami->id, 'sort_order' => 1]);
        $this->category('Unscheduled One', 2);

        $this->get(route('public.competitions.timetable', $this->competition))
            ->assertSee('Scheduled One')
            ->assertDontSee('Unscheduled One');
    }
}
