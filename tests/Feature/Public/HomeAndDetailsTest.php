<?php

namespace Tests\Feature\Public;

use App\Enums\CompetitionStatus;
use App\Models\Category;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Competitor;
use App\Models\Entry;
use App\Models\Tatami;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeAndDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
    }

    public function test_the_home_page_lists_published_competitions_in_two_sections_and_hides_drafts(): void
    {
        Competition::factory()->create(['name' => 'Hidden Draft Cup', 'status' => CompetitionStatus::Draft]);
        Competition::factory()->create(['name' => 'Open Registration Cup', 'status' => CompetitionStatus::Registration, 'date' => '2026-12-12']);
        Competition::factory()->create(['name' => 'Earlier Live Cup', 'status' => CompetitionStatus::InProgress, 'date' => '2026-12-05']);
        Competition::factory()->create(['name' => 'Old Finished Cup', 'status' => CompetitionStatus::Finished, 'date' => '2026-01-10']);

        $response = $this->get(route('home'))->assertOk();

        $response
            ->assertDontSee('Hidden Draft Cup')
            ->assertSeeInOrder(['Current and upcoming', 'Earlier Live Cup', 'Open Registration Cup', 'Finished', 'Old Finished Cup'])
            ->assertSee('data-test="live-badge"', false);

        $this->assertSame(3, substr_count($response->getContent(), 'data-test="competition-card"'));
    }

    public function test_a_card_shows_the_number_of_entries_and_links_to_the_competition(): void
    {
        $competition = Competition::factory()->create(['status' => CompetitionStatus::Drawn]);
        Entry::factory()->count(3)->for(Category::factory()->for($competition))->create();

        $this->get(route('home'))
            ->assertSee('3 entries')
            ->assertSee(route('public.competitions.show', $competition), false);
    }

    public function test_the_home_page_has_a_friendly_empty_state(): void
    {
        $this->get(route('home'))->assertOk()->assertSee('There are no published competitions right now.');
    }

    public function test_finished_competitions_are_paginated_twelve_at_a_time(): void
    {
        foreach (range(1, 13) as $day) {
            Competition::factory()->create([
                'name' => sprintf('Finished Cup %02d', $day),
                'status' => CompetitionStatus::Finished,
                'date' => sprintf('2026-03-%02d', $day),
            ]);
        }

        $first = $this->get(route('home'))->assertOk();
        $this->assertSame(12, substr_count($first->getContent(), 'data-test="competition-card"'));
        $first->assertDontSee('Finished Cup 01');

        $second = $this->get(route('home', ['finished' => 2]))->assertOk();
        $this->assertSame(1, substr_count($second->getContent(), 'data-test="competition-card"'));
        $second->assertSee('Finished Cup 01');
    }

    public function test_the_home_page_query_count_does_not_depend_on_the_number_of_competitions(): void
    {
        Competition::factory()->count(2)->create(['status' => CompetitionStatus::Registration]);

        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $this->get(route('home'))->assertOk();
        $few = count(\DB::getQueryLog());

        Competition::factory()->count(8)->create(['status' => CompetitionStatus::Registration]);
        \DB::flushQueryLog();
        $this->get(route('home'))->assertOk();
        $many = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertSame($few, $many);
    }

    public function test_the_details_page_shows_the_facts_and_exact_numbers(): void
    {
        $competition = Competition::factory()->create([
            'name' => 'Ivanec Open',
            'status' => CompetitionStatus::Drawn,
            'date' => '2026-12-05',
            'start_time' => '10:00:00',
            'venue' => 'Dvorana Ivanec',
            'organizer' => 'KK Ivanec',
            'registration_deadline' => '2026-12-01 23:59:00',
        ]);
        $first = Category::factory()->for($competition)->create();
        $second = Category::factory()->for($competition)->create();
        Tatami::factory()->for($competition)->create(['number' => 1]);
        Tatami::factory()->for($competition)->create(['number' => 2]);

        $clubA = Club::factory()->create();
        $clubB = Club::factory()->create();
        $ana = Competitor::factory()->for($clubA)->create();
        $iva = Competitor::factory()->for($clubA)->create();
        $marko = Competitor::factory()->for($clubB)->create();

        foreach ([[$first, $ana], [$second, $ana], [$first, $iva], [$first, $marko]] as [$category, $competitor]) {
            Entry::factory()->for($category)->for($competitor)->create();
        }

        $unrelated = Entry::factory()->create();

        $response = $this->get(route('public.competitions.show', $competition))->assertOk();

        $response
            ->assertSee('Ivanec Open')
            ->assertSee('05.12.2026.')
            ->assertSee('10:00')
            ->assertSee('Dvorana Ivanec')
            ->assertSee('KK Ivanec')
            ->assertSee('01.12.2026. 23:59');

        foreach (['categories' => 2, 'entries' => 4, 'competitors' => 3, 'clubs' => 2, 'tatamis' => 2] as $stat => $value) {
            $response->assertSeeHtml('data-stat="'.$stat.'">'.$value.'<');
        }

        $this->assertNotNull($unrelated);
    }

    public function test_the_follow_live_link_is_shown_only_while_the_competition_is_in_progress(): void
    {
        $live = Competition::factory()->create(['status' => CompetitionStatus::InProgress]);
        $drawn = Competition::factory()->create(['status' => CompetitionStatus::Drawn]);

        $this->get(route('public.competitions.show', $live))->assertSee('data-test="follow-live"', false);
        $this->get(route('public.competitions.show', $drawn))->assertDontSee('data-test="follow-live"', false);
    }

    public function test_the_header_has_all_seven_tabs_with_the_active_one_marked(): void
    {
        $competition = Competition::factory()->create(['status' => CompetitionStatus::Registration]);

        $html = $this->get(route('public.competitions.show', $competition))->assertOk()->getContent();

        foreach (['show', 'categories', 'competitors', 'draw', 'timetable', 'results', 'standings'] as $tab) {
            $this->assertStringContainsString('data-test="tab-'.$tab.'"', $html);
        }

        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('/data-test="tab-show"[^>]*aria-current="page"|aria-current="page"[^>]*data-test="tab-show"/s', $html);
    }

    public function test_drafts_and_unknown_slugs_are_not_found(): void
    {
        $draft = Competition::factory()->create(['status' => CompetitionStatus::Draft]);

        $this->get(route('public.competitions.show', $draft))->assertNotFound();
        $this->get('/competitions/no-such-competition')->assertNotFound();
    }

    public function test_the_home_page_says_so_when_only_finished_competitions_exist(): void
    {
        Competition::factory()->create(['name' => 'Only Finished Cup', 'status' => CompetitionStatus::Finished]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('There are no current or upcoming competitions right now.')
            ->assertDontSee('There are no published competitions right now.')
            ->assertSee('Finished competitions')
            ->assertSee('Only Finished Cup');
    }

    public function test_a_live_card_links_to_the_time_table_and_the_name_links_to_the_competition(): void
    {
        $live = Competition::factory()->create(['name' => 'Live Cup', 'status' => CompetitionStatus::InProgress]);
        $drawn = Competition::factory()->create(['name' => 'Drawn Cup', 'status' => CompetitionStatus::Drawn]);

        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('public.competitions.timetable', $live), '/').'"[^>]*data-test="live-badge"/', $html);
        $this->assertStringContainsString('href="'.route('public.competitions.show', $live).'"', $html);
        $this->assertStringContainsString('href="'.route('public.competitions.show', $drawn).'"', $html);
        $this->assertStringNotContainsString(route('public.competitions.timetable', $drawn), $html);
        $this->assertSame(1, substr_count($html, 'data-test="live-badge"'));
    }

    public function test_the_home_page_uses_at_most_three_queries_with_finished_competitions(): void
    {
        Competition::factory()->count(3)->create(['status' => CompetitionStatus::Finished]);
        Competition::factory()->count(3)->create(['status' => CompetitionStatus::Registration]);

        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $this->get(route('home'))->assertOk();
        $count = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertLessThanOrEqual(3, $count);
    }
}
