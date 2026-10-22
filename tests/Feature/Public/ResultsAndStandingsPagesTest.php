<?php

namespace Tests\Feature\Public;

use App\Actions\Draw\RecordBoutResult;
use App\Enums\BoutDecision;
use App\Enums\BoutSlot;
use App\Enums\CompetitionStatus;
use App\Enums\Discipline;
use App\Models\Competition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Draw\InteractsWithDraws;
use Tests\TestCase;

class ResultsAndStandingsPagesTest extends TestCase
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

    public function test_the_results_page_shows_podiums_with_both_bronze_medallists(): void
    {
        $category = $this->drawnCategory(8, competition: $this->competition);
        $category->update(['name' => 'Cadets']);
        $this->playAll($category);

        $html = $this->get(route('public.competitions.results', $this->competition))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-test="result-card"'));
        $this->assertSame(1, substr_count($html, 'data-test="podium-1"'));
        $this->assertSame(1, substr_count($html, 'data-test="podium-2"'));
        $this->assertSame(1, substr_count($html, 'data-test="podium-3"'));

        foreach ($category->placements()->with('entry.competitor.club')->get() as $placement) {
            $this->assertStringContainsString(e($placement->entry->competitor->last_name), $html);
            $this->assertStringContainsString(e($placement->entry->competitor->club->name), $html);
        }

        $this->assertStringContainsString('1st place', $html);
        $this->assertStringContainsString('3rd place', $html);
    }

    public function test_a_category_in_progress_is_shown_as_such_and_waits_for_the_final(): void
    {
        $category = $this->drawnCategory(4, competition: $this->competition);
        app(RecordBoutResult::class)->handle($this->bout($category, 1, 1), BoutSlot::Aka, null, null, BoutDecision::Hantei);

        $this->get(route('public.competitions.results', $this->competition))
            ->assertOk()
            ->assertSee('In progress')
            ->assertSee('The final has not been played yet.')
            ->assertSee('data-test="podium-3"', false)
            ->assertDontSee('data-test="podium-1"', false);
    }

    public function test_the_results_page_has_an_empty_state_and_ignores_categories_without_placements(): void
    {
        $this->drawnCategory(4, competition: $this->competition);

        $this->get(route('public.competitions.results', $this->competition))
            ->assertOk()
            ->assertSee('There are no results yet.');
    }

    public function test_the_results_can_be_filtered_by_discipline(): void
    {
        $kumite = $this->drawnCategory(4, Discipline::Kumite, competition: $this->competition);
        $kata = $this->drawnCategory(4, Discipline::Kata, competition: $this->competition);
        $kumite->update(['name' => 'Kumite Cadets']);
        $kata->update(['name' => 'Kata Cadets']);
        $this->playAll($kumite);
        $this->playAll($kata);

        Livewire::test('pages::public.competitions.results', ['publicCompetition' => $this->competition])
            ->assertSee('Kumite Cadets')
            ->assertSee('Kata Cadets')
            ->set('discipline', 'kata')
            ->assertSee('Kata Cadets')
            ->assertDontSee('Kumite Cadets')
            ->set('discipline', 'kumite')
            ->assertSee('Kumite Cadets')
            ->assertDontSee('Kata Cadets')
            ->set('discipline', 'nonsense')
            ->assertSee('Kumite Cadets')
            ->assertSee('Kata Cadets');
    }

    public function test_the_standings_page_ranks_clubs_by_medals(): void
    {
        $category = $this->drawnCategory(8, competition: $this->competition);
        $this->playAll($category);

        $html = $this->get(route('public.competitions.standings', $this->competition))->assertOk()->getContent();

        $this->assertSame(4, substr_count($html, 'data-test="standings-row"'));
        $this->assertSame(1, substr_count($html, 'data-rank="1"'));
        $this->assertSame(1, substr_count($html, 'data-rank="2"'));
        $this->assertSame(2, substr_count($html, 'data-rank="3"'));
        $this->assertStringContainsString('Ranked by gold medals, then silver, then bronze.', $html);
        $this->assertLessThan(strpos($html, 'data-rank="2"'), strpos($html, 'data-rank="1"'));
    }

    public function test_the_standings_page_has_an_empty_state(): void
    {
        $this->get(route('public.competitions.standings', $this->competition))
            ->assertOk()
            ->assertSee('No medals have been won yet.');
    }
}
