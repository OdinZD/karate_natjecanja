<?php

namespace Tests\Feature\Public;

use App\Actions\Draw\RecordBoutResult;
use App\Enums\BoutDecision;
use App\Enums\BoutSlot;
use App\Enums\CompetitionStatus;
use App\Models\Competition;
use App\Models\Tatami;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Draw\InteractsWithDraws;
use Tests\TestCase;

class LivePollingTest extends TestCase
{
    use InteractsWithDraws;
    use RefreshDatabase;

    private Competition $competition;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        $this->competition = Competition::factory()->create(['status' => CompetitionStatus::InProgress]);
    }

    public function test_the_live_pages_poll_every_ten_seconds_while_visible(): void
    {
        $category = $this->drawnCategory(4, competition: $this->competition);

        $live = [
            route('public.competitions.draw', $this->competition),
            route('public.competitions.bracket', [$this->competition, $category]),
            route('public.competitions.timetable', $this->competition),
            route('public.competitions.results', $this->competition),
            route('public.competitions.standings', $this->competition),
        ];

        foreach ($live as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertMatchesRegularExpression('/data-test="live-region"[^>]*wire:poll\.10s\.visible|wire:poll\.10s\.visible[^>]*data-test="live-region"/', $html, $url);
            $this->assertSame(1, substr_count($html, 'wire:poll'), $url);
        }
    }

    public function test_the_static_pages_do_not_poll(): void
    {
        $category = $this->drawnCategory(4, competition: $this->competition);

        $static = [
            route('home'),
            route('public.competitions.show', $this->competition),
            route('public.competitions.categories', $this->competition),
            route('public.competitions.competitors', $this->competition),
        ];

        foreach ($static as $url) {
            $this->assertStringNotContainsString('wire:poll', $this->get($url)->assertOk()->getContent(), $url);
        }

        $this->assertNotNull($category);
    }

    public function test_a_refresh_picks_up_a_result_recorded_in_the_meantime(): void
    {
        $tatami = Tatami::factory()->for($this->competition)->create();
        $category = $this->drawnCategory(4, competition: $this->competition);
        $category->update(['tatami_id' => $tatami->id, 'sort_order' => 1]);

        foreach (['Alphaname', 'Bravoname', 'Charliename', 'Deltaname'] as $index => $name) {
            $category->entries()->orderBy('seed')->get()[$index]->competitor->update(['last_name' => $name]);
        }

        $first = $this->bout($category, 1, 1)->load(['aka.competitor', 'ao.competitor']);
        $second = $this->bout($category, 1, 2)->load(['aka.competitor', 'ao.competitor']);

        $page = Livewire::test('pages::public.competitions.timetable', ['publicCompetition' => $this->competition])
            ->assertSee($first->aka->competitor->last_name)
            ->assertSee($first->ao->competitor->last_name);

        app(RecordBoutResult::class)->handle($first, BoutSlot::Aka, null, null, BoutDecision::Hantei);

        $page->call('$refresh');

        $current = $page->html();
        $this->assertStringContainsString($second->aka->competitor->last_name, $current);
        $this->assertStringContainsString($second->ao->competitor->last_name, $current);
        $this->assertStringContainsString('In progress', $current);
    }
}
