<?php

namespace Tests\Feature\Public;

use App\Actions\Draw\GenerateDraw;
use App\Enums\CompetitionStatus;
use App\Enums\Gender;
use App\Models\Category;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Competitor;
use App\Models\Entry;
use App\Models\Tatami;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Draw\InteractsWithDraws;
use Tests\TestCase;

class PublicPrivacyTest extends TestCase
{
    use InteractsWithDraws;
    use RefreshDatabase;

    public function test_no_public_page_exposes_birth_dates_competitor_genders_or_operator_details(): void
    {
        app()->setLocale('en');

        $competition = Competition::factory()->create(['status' => CompetitionStatus::InProgress]);
        $tatami = Tatami::factory()->for($competition)->create();
        $category = Category::factory()->for($competition)->create(['gender' => null, 'tatami_id' => $tatami->id, 'sort_order' => 1]);

        $operator = User::factory()->create(['name' => 'Secret Operator Name', 'email' => 'secret.operator@example.com']);
        $tatami->operators()->attach($operator);

        // The tester is seed 1, so he is in the bracket and wins the category.
        $tester = Competitor::factory()->for(Club::factory())->create([
            'first_name' => 'Privacy', 'last_name' => 'Tester', 'gender' => Gender::Female, 'birth_date' => '2011-03-14',
        ]);
        Entry::factory()->for($category)->for($tester)->create(['seed' => 1]);
        foreach (range(2, 4) as $seed) {
            Entry::factory()->for($category)->create(['seed' => $seed]);
        }

        app(GenerateDraw::class)->handle($category);
        $this->playAll($category);

        $pages = [
            'home' => route('home'),
            'show' => route('public.competitions.show', $competition),
            'categories' => route('public.competitions.categories', $competition),
            'competitors' => route('public.competitions.competitors', $competition),
            'draw' => route('public.competitions.draw', $competition),
            'bracket' => route('public.competitions.bracket', [$competition, $category]),
            'timetable' => route('public.competitions.timetable', $competition),
            'results' => route('public.competitions.results', $competition),
            'standings' => route('public.competitions.standings', $competition),
        ];

        $html = [];

        foreach ($pages as $name => $url) {
            $html[$name] = $this->get($url)->assertOk()->getContent();

            foreach (['14.03.2011', '2011-03-14', 'Female', 'Secret Operator Name', 'secret.operator@example.com'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $html[$name], "{$forbidden} leaked on {$name}");
            }
        }

        // The pages do show the tester, so the checks above are not passing for lack of data.
        foreach (['competitors', 'bracket', 'results'] as $name) {
            $this->assertStringContainsString('Tester Privacy', $html[$name], $name);
        }

        $this->assertStringContainsString($tester->club->name, $html['standings']);
    }
}
