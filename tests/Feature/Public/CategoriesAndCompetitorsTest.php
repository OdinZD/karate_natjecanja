<?php

namespace Tests\Feature\Public;

use App\Enums\CompetitionStatus;
use App\Enums\Discipline;
use App\Enums\Gender;
use App\Models\Category;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Competitor;
use App\Models\Entry;
use App\Models\Tatami;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Draw\InteractsWithDraws;
use Tests\TestCase;

class CategoriesAndCompetitorsTest extends TestCase
{
    use InteractsWithDraws;
    use RefreshDatabase;

    private Competition $competition;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        $this->competition = Competition::factory()->create([
            'name' => 'Fixed Test Cup',
            'venue' => 'Fixed Hall',
            'organizer' => 'Fixed Organizer',
            'status' => CompetitionStatus::Drawn,
        ]);
    }

    public function test_categories_show_their_details_and_link_to_the_bracket_only_with_a_draw(): void
    {
        $tatami = Tatami::factory()->for($this->competition)->create(['number' => 2]);
        $drawn = $this->drawnCategory(4, competition: $this->competition);
        $drawn->update(['name' => 'Cadets Boys -52', 'gender' => Gender::Male, 'birth_year_from' => 2010, 'birth_year_to' => 2011, 'weight_min' => null, 'weight_max' => '52', 'tatami_id' => $tatami->id]);
        $plain = Category::factory()->for($this->competition)->create(['name' => 'Seniors Kata', 'discipline' => Discipline::Kata, 'gender' => null, 'weight_max' => null, 'birth_year_from' => 2000, 'birth_year_to' => 2008]);

        $response = $this->get(route('public.competitions.categories', $this->competition))->assertOk();

        $response
            ->assertSee('Cadets Boys -52')
            ->assertSee('Born 2010 to 2011')
            ->assertSee('up to 52 kg')
            ->assertSee('Tatami 2')
            ->assertSee('4 entries')
            ->assertSee('Seniors Kata')
            ->assertSee('Mixed')
            ->assertSee('No draw')
            ->assertSee(route('public.competitions.bracket', [$this->competition, $drawn]), false)
            ->assertDontSee(route('public.competitions.bracket', [$this->competition, $plain]), false);
    }

    public function test_categories_of_other_competitions_are_not_listed(): void
    {
        Category::factory()->create(['name' => 'Somebody Elses Category']);

        $this->get(route('public.competitions.categories', $this->competition))
            ->assertOk()
            ->assertDontSee('Somebody Elses Category')
            ->assertSee('Categories have not been published yet.');
    }

    private function enter(string $last, string $first, Club $club, Category ...$categories): Competitor
    {
        $competitor = Competitor::factory()->for($club)->create(['last_name' => $last, 'first_name' => $first]);

        foreach ($categories as $category) {
            Entry::factory()->for($category)->for($competitor)->create();
        }

        return $competitor;
    }

    public function test_competitors_are_listed_by_name_with_their_club_and_categories_without_private_data(): void
    {
        $club = Club::factory()->create(['name' => 'KK Sisak']);
        $kata = Category::factory()->for($this->competition)->create(['name' => 'Kata Juniors', 'sort_order' => 1]);
        $kumite = Category::factory()->for($this->competition)->create(['name' => 'Kumite Juniors', 'sort_order' => 2]);
        $other = $this->enter('Babić', 'Iva', $club, $kumite, $kata);
        $other->update(['birth_date' => '2011-03-14']);
        $this->enter('Horvat', 'Marko', $club, $kata);

        $html = $this->get(route('public.competitions.competitors', $this->competition))->assertOk()->getContent();

        $this->assertStringContainsString('Babić Iva', $html);
        $this->assertStringContainsString('KK Sisak', $html);
        $this->assertStringContainsString('Kata Juniors, Kumite Juniors', $html);
        $this->assertLessThan(strpos($html, 'Horvat Marko'), strpos($html, 'Babić Iva'));
        $this->assertStringNotContainsString('14.03.2011', $html);
        $this->assertStringNotContainsString('2011-03-14', $html);
    }

    public function test_only_competitors_entered_in_this_competition_are_listed(): void
    {
        $club = Club::factory()->create();
        $mine = Category::factory()->for($this->competition)->create();
        $this->enter('Included', 'Ana', $club, $mine);
        $this->enter('Foreign', 'Ivo', $club, Category::factory()->create());
        Competitor::factory()->for($club)->create(['last_name' => 'Unentered', 'first_name' => 'Zed']);

        $this->get(route('public.competitions.competitors', $this->competition))
            ->assertSee('Included Ana')
            ->assertDontSee('Foreign Ivo')
            ->assertDontSee('Unentered Zed');
    }

    public function test_search_needs_two_letters_and_the_club_filter_narrows_the_list(): void
    {
        $sisak = Club::factory()->create(['name' => 'KK Sisak']);
        $rijeka = Club::factory()->create(['name' => 'KK Rijeka']);
        $category = Category::factory()->for($this->competition)->create();
        $this->enter('Kovač', 'Ana', $sisak, $category);
        $this->enter('Babić', 'Iva', $rijeka, $category);

        Livewire::test('pages::public.competitions.competitors', ['publicCompetition' => $this->competition])
            ->set('search', 'K')
            ->assertSee('Kovač')
            ->assertSee('Babić')
            ->set('search', 'Ko')
            ->assertSee('Kovač')
            ->assertDontSee('Babić')
            ->set('search', '')
            ->set('clubFilter', (string) $rijeka->id)
            ->assertSee('Babić')
            ->assertDontSee('Kovač');
    }

    public function test_the_club_filter_offers_only_clubs_with_entries(): void
    {
        $with = Club::factory()->create(['name' => 'Club With Entries']);
        Club::factory()->create(['name' => 'Club Without Entries']);
        $this->enter('Kovač', 'Ana', $with, Category::factory()->for($this->competition)->create());

        $this->get(route('public.competitions.competitors', $this->competition))
            ->assertSee('Club With Entries')
            ->assertDontSee('Club Without Entries');
    }

    public function test_the_list_is_paginated_fifty_at_a_time(): void
    {
        $club = Club::factory()->create();
        $category = Category::factory()->for($this->competition)->create();

        foreach (range(1, 51) as $number) {
            $this->enter(sprintf('Surname%02d', $number), 'Test', $club, $category);
        }

        $first = $this->get(route('public.competitions.competitors', $this->competition))->getContent();
        $this->assertSame(50, substr_count($first, 'data-test="competitor-row"'));
        $this->assertStringNotContainsString('Surname51', $first);

        $second = $this->get(route('public.competitions.competitors', [$this->competition, 'page' => 2]))->getContent();
        $this->assertSame(1, substr_count($second, 'data-test="competitor-row"'));
        $this->assertStringContainsString('Surname51', $second);
    }

    public function test_a_full_name_finds_the_competitor_in_either_order_and_wildcards_are_literal(): void
    {
        $club = Club::factory()->create(['name' => 'KK Sisak']);
        $category = Category::factory()->for($this->competition)->create();
        $this->enter('Horvat', 'Ana', $club, $category);
        $this->enter('Horvat', 'Ivo', $club, $category);
        $this->enter('Babić', 'Ana', $club, $category);
        $this->enter('Jure_Test', 'Ivan', $club, $category);
        $this->enter('JureXTest', 'Pero', $club, $category);

        Livewire::test('pages::public.competitions.competitors', ['publicCompetition' => $this->competition])
            ->set('search', 'Ana Horvat')
            ->assertSee('Horvat Ana')
            ->assertDontSee('Horvat Ivo')
            ->assertDontSee('Babić Ana')
            ->set('search', 'horvat ana')
            ->assertSee('Horvat Ana')
            ->set('search', '%%')
            ->assertSee('No competitors found.')
            ->set('search', 'e_T')
            ->assertSee('Jure_Test Ivan')
            ->assertDontSee('JureXTest Pero');
    }

    public function test_competitor_categories_are_listed_in_category_order_not_alphabetically(): void
    {
        $club = Club::factory()->create();
        $ten = Category::factory()->for($this->competition)->create(['name' => 'Aaa Tenth', 'sort_order' => 10]);
        $two = Category::factory()->for($this->competition)->create(['name' => 'Zzz Second', 'sort_order' => 2]);
        $this->enter('Kovač', 'Ana', $club, $ten, $two);

        $this->get(route('public.competitions.competitors', $this->competition))->assertSee('Zzz Second, Aaa Tenth');
    }

    public function test_the_weight_is_shown_as_a_range_a_limit_or_a_dash(): void
    {
        Category::factory()->for($this->competition)->create(['name' => 'Range Cat', 'weight_min' => '30', 'weight_max' => '40.5', 'sort_order' => 1]);
        Category::factory()->for($this->competition)->create(['name' => 'Max Cat', 'weight_min' => null, 'weight_max' => '52', 'sort_order' => 2]);
        Category::factory()->for($this->competition)->create(['name' => 'Min Cat', 'weight_min' => '75', 'weight_max' => null, 'sort_order' => 3]);
        Category::factory()->for($this->competition)->create(['name' => 'Open Cat', 'weight_min' => null, 'weight_max' => null, 'sort_order' => 4]);

        $html = $this->get(route('public.competitions.categories', $this->competition))->assertOk()->getContent();

        $this->assertStringContainsString('30–40.5 kg', $html);
        $this->assertStringContainsString('up to 52 kg', $html);
        $this->assertStringContainsString('from 75 kg', $html);
        $this->assertStringNotContainsString('30– kg', $html);
        $this->assertStringContainsString('<span class="ps-num">—</span>', $html);

        app()->setLocale('hr');
        $category = Category::where('name', 'Range Cat')->firstOrFail();
        $this->assertSame('30–40,5 kg', $category->weightLabel());
    }

    public function test_pagination_is_translated_and_labels_are_not_double_escaped(): void
    {
        $club = Club::factory()->create();
        $category = Category::factory()->for($this->competition)->create();

        foreach (range(1, 51) as $number) {
            $this->enter(sprintf('Surname%02d', $number), 'Test', $club, $category);
        }

        app()->setLocale('hr');

        $html = $this->get(route('public.competitions.competitors', $this->competition))->assertOk()->getContent();

        $this->assertStringContainsString('Idi na stranicu 2', $html);
        $this->assertStringContainsString('Sljedeća »', $html);
        $this->assertStringNotContainsString('&amp;raquo;', $html);
        $this->assertStringNotContainsString('&raquo;', $html);
    }
}
