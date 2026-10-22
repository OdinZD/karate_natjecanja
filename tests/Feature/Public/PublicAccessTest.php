<?php

namespace Tests\Feature\Public;

use App\Enums\CompetitionStatus;
use App\Models\Category;
use App\Models\Competition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function urls(Competition $competition, Category $category): array
    {
        return [
            'show' => route('public.competitions.show', $competition),
            'categories' => route('public.competitions.categories', $competition),
            'competitors' => route('public.competitions.competitors', $competition),
            'draw' => route('public.competitions.draw', $competition),
            'bracket' => route('public.competitions.bracket', [$competition, $category]),
            'timetable' => route('public.competitions.timetable', $competition),
            'results' => route('public.competitions.results', $competition),
            'standings' => route('public.competitions.standings', $competition),
        ];
    }

    public function test_every_public_route_is_open_to_guests_for_every_published_status(): void
    {
        foreach ([CompetitionStatus::Registration, CompetitionStatus::Drawn, CompetitionStatus::InProgress, CompetitionStatus::Finished] as $status) {
            $competition = Competition::factory()->create(['status' => $status]);
            $category = Category::factory()->for($competition)->create();

            foreach ($this->urls($competition, $category) as $name => $url) {
                $this->get($url)->assertOk("{$name} for {$status->value}");
            }
        }

        $this->get(route('home'))->assertOk();
    }

    public function test_drafts_are_hidden_on_every_route_even_from_administrators(): void
    {
        $draft = Competition::factory()->create(['status' => CompetitionStatus::Draft]);
        $category = Category::factory()->for($draft)->create();

        foreach ($this->urls($draft, $category) as $name => $url) {
            $this->get($url)->assertNotFound($name);
        }

        $this->actingAs(User::factory()->admin()->create());

        foreach ($this->urls($draft, $category) as $name => $url) {
            $this->get($url)->assertNotFound("{$name} as admin");
        }
    }

    public function test_signed_in_users_see_the_public_pages_without_redirects(): void
    {
        $competition = Competition::factory()->create(['status' => CompetitionStatus::Drawn]);
        $category = Category::factory()->for($competition)->create();

        foreach ([User::factory()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user);

            foreach ($this->urls($competition, $category) as $url) {
                $this->get($url)->assertOk();
            }
        }
    }

    public function test_unknown_slugs_and_numeric_ids_are_not_found(): void
    {
        $competition = Competition::factory()->create(['status' => CompetitionStatus::Drawn]);

        $this->get('/competitions/no-such-competition')->assertNotFound();
        $this->get('/competitions/'.$competition->id)->assertNotFound();
        $this->get('/competitions/'.$competition->id.'/draw')->assertNotFound();
    }

    public function test_a_bracket_cannot_be_opened_through_another_competition(): void
    {
        $mine = Competition::factory()->create(['status' => CompetitionStatus::Drawn]);
        $foreign = Category::factory()->for(Competition::factory()->create(['status' => CompetitionStatus::Drawn]))->create();

        $this->get(route('public.competitions.bracket', [$mine, $foreign]))->assertNotFound();
    }

    public function test_the_public_routes_carry_no_authentication_or_authorization_middleware(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => $route->getName() === 'home' || str_starts_with((string) $route->getName(), 'public.competitions.'));

        $this->assertCount(9, $routes);

        foreach ($routes as $route) {
            $middleware = implode(',', app('router')->gatherRouteMiddleware($route));

            $this->assertStringNotContainsString('Authenticate', $middleware, (string) $route->getName());
            $this->assertStringNotContainsString('Authorize', $middleware, (string) $route->getName());
            $this->assertStringNotContainsString('Throttle', $middleware, (string) $route->getName());
        }
    }

    public function test_an_open_page_stops_updating_once_the_competition_is_back_to_draft(): void
    {
        $competition = Competition::factory()->create(['status' => CompetitionStatus::InProgress]);

        $html = $this->get(route('public.competitions.timetable', $competition))->assertOk()->getContent();
        preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);
        $payload = [
            'components' => [[
                'snapshot' => html_entity_decode($matches[1], ENT_QUOTES),
                'updates' => (object) [],
                'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
            ]],
        ];

        // Livewire re-applies route model binding on updates (SubstituteBindings is a persistent
        // middleware), and the public binder hides drafts.
        $competition->update(['status' => CompetitionStatus::Draft]);

        $this->withHeaders(['X-Livewire' => 'true'])->postJson(app('livewire')->getUpdateUri(), $payload)->assertNotFound();
    }

    public function test_page_titles_are_translated(): void
    {
        $competition = Competition::factory()->create(['status' => CompetitionStatus::Drawn]);

        $this->get(route('public.competitions.timetable', $competition))->assertSee('<title>', false)->assertSee('Time Table - ', false);
        $this->get(route('public.competitions.draw', $competition))->assertSee('Ždrijeb - ', false);
    }
}
