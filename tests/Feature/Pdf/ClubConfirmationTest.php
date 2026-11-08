<?php

namespace Tests\Feature\Pdf;

use App\Actions\Entries\ClubEntryList;
use App\Enums\Gender;
use App\Models\Category;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Competitor;
use App\Models\Entry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ClubConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private Competition $competition;

    private Club $club;

    private Category $kata;

    private Category $kumite;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        $this->competition = Competition::factory()->create(['name' => 'Ivanec Open', 'slug' => 'ivanec-open-2026', 'date' => '2026-05-10', 'venue' => 'SD Ivanec', 'registration_deadline' => null]);
        $this->club = Club::factory()->create(['name' => 'KK Šibenik', 'city' => 'Šibenik']);
        $this->kata = Category::factory()->for($this->competition)->create(['name' => 'Kadeti - Kata', 'sort_order' => 1]);
        $this->kumite = Category::factory()->for($this->competition)->create(['name' => 'Kadeti - Kumite -52 kg', 'sort_order' => 2]);
    }

    private function url(array $query = []): string
    {
        return route('admin.competitions.entries.confirmation', [$this->competition, ...($query ?: ['club' => $this->club->id])]);
    }

    private function child(string $last, string $first, string $birth = '2011-03-14', ?Club $club = null): Competitor
    {
        return Competitor::factory()->for($club ?? $this->club)->create([
            'last_name' => $last,
            'first_name' => $first,
            'gender' => Gender::Female,
            'birth_date' => $birth,
        ]);
    }

    private function enter(Competitor $competitor, Category $category, ?string $weight = null): Entry
    {
        return Entry::factory()->for($category)->create(['competitor_id' => $competitor->id, 'weight' => $weight]);
    }

    private function renderedHtml(): string
    {
        $competitors = app(ClubEntryList::class)->handle($this->competition, $this->club);

        return view('pdf.club-entries', ['competition' => $this->competition, 'club' => $this->club, 'competitors' => $competitors])->render();
    }

    public function test_guests_are_sent_to_the_login_page_and_operators_are_forbidden(): void
    {
        $this->enter($this->child('Čačić', 'Đurđica'), $this->kata);

        $this->get($this->url())->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create());
        $this->get($this->url())->assertForbidden();
    }

    public function test_an_admin_gets_the_pdf(): void
    {
        $this->enter($this->child('Čačić', 'Đurđica'), $this->kata);
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->get($this->url())->assertOk();

        $this->assertStringStartsWith('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('potvrda-prijava-ivanec-open-kk-sibenik.pdf', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('DejaVu', $response->getContent());
    }

    public function test_the_route_carries_the_admin_authorization(): void
    {
        $route = app('router')->getRoutes()->getByName('admin.competitions.entries.confirmation');

        $this->assertNotNull($route);
        $this->assertContains('can:admin', $route->gatherMiddleware());
    }

    public function test_there_is_nothing_to_print_for_a_missing_unknown_or_empty_club(): void
    {
        $this->enter($this->child('Čačić', 'Đurđica'), $this->kata);
        $empty = Club::factory()->create();
        $this->actingAs(User::factory()->admin()->create());

        $this->get($this->url(['club' => 999999]))->assertNotFound();
        $this->get($this->url(['club' => 'abc']))->assertNotFound();
        $this->get($this->url(['club' => $this->club->id.'abc']))->assertNotFound();
        $this->get($this->url(['club' => $empty->id]))->assertNotFound();
        $this->get(route('admin.competitions.entries.confirmation', $this->competition))->assertNotFound();
        $this->get(route('admin.competitions.entries.confirmation', [$this->competition, 'club' => [1]]))->assertNotFound();
    }

    public function test_a_club_with_entries_only_in_another_competition_has_nothing_to_print(): void
    {
        $other = Category::factory()->for(Competition::factory()->create())->create();
        $this->enter($this->child('Čačić', 'Đurđica'), $other);
        $this->actingAs(User::factory()->admin()->create());

        $this->get($this->url())->assertNotFound();
    }

    public function test_the_list_holds_only_this_clubs_competitors_and_this_competitions_entries(): void
    {
        $ana = $this->child('Babić', 'Ana');
        $this->enter($ana, $this->kumite, '45');
        $this->enter($ana, $this->kata);
        $this->enter($ana, Category::factory()->for(Competition::factory()->create())->create());
        $this->enter($this->child('Tuđić', 'Tin', club: Club::factory()->create()), $this->kata);
        $this->child('Bez', 'Prijave');

        $competitors = app(ClubEntryList::class)->handle($this->competition, $this->club);

        $this->assertSame(['Babić'], $competitors->pluck('last_name')->all());
        $this->assertSame(['Kadeti - Kata', 'Kadeti - Kumite -52 kg'], $competitors->first()->entries->pluck('category.name')->all());
    }

    public function test_the_document_lists_names_categories_weights_and_totals(): void
    {
        $ana = $this->child('Babić', 'Ana', '2011-03-14');
        $this->enter($ana, $this->kata);
        $this->enter($ana, $this->kumite, '45.5');
        $this->enter($this->child('Čačić', 'Đurđica', '2012-07-01'), $this->kata);

        $html = $this->renderedHtml();

        $this->assertStringContainsString('Entry confirmation', $html);
        $this->assertStringContainsString('Ivanec Open', $html);
        $this->assertStringContainsString('10.05.2026., SD Ivanec', $html);
        $this->assertStringContainsString('KK Šibenik, Šibenik', $html);
        $this->assertStringContainsString('Babić Ana', $html);
        $this->assertStringContainsString('Čačić Đurđica', $html);
        $this->assertStringContainsString('Kadeti - Kumite -52 kg', $html);
        $this->assertStringContainsString('45.5', $html);
        $this->assertStringContainsString('Total: 2 competitors, 3 entries', $html);
        $this->assertLessThan(strpos($html, 'Čačić'), strpos($html, 'Babić'));
    }

    public function test_only_the_year_of_birth_is_printed_and_no_gender(): void
    {
        $this->enter($this->child('Babić', 'Ana', '2011-03-14'), $this->kata);

        $html = $this->renderedHtml();

        $this->assertStringContainsString('<td>2011</td>', $html);
        $this->assertStringNotContainsString('14.03.2011', $html);
        $this->assertStringNotContainsString('2011-03-14', $html);
        $this->assertStringNotContainsString('Female', $html);
        $this->assertStringNotContainsString('Žensk', $html);
    }

    public function test_names_are_escaped(): void
    {
        $this->enter($this->child('<b>Evil</b>', 'A&B'), $this->kata);

        $html = $this->renderedHtml();

        $this->assertStringNotContainsString('<b>Evil</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;Evil&lt;/b&gt; A&amp;B', $html);
    }

    public function test_the_note_mentions_the_registration_deadline_when_there_is_one(): void
    {
        $this->enter($this->child('Babić', 'Ana'), $this->kata);

        $this->assertStringContainsString('report any corrections to the organiser.', $this->renderedHtml());

        $this->competition->update(['registration_deadline' => '2026-04-01 12:00:00']);
        $this->competition->refresh();

        $this->assertStringContainsString('by 01.04.2026. 12:00.', $this->renderedHtml());
    }

    public function test_a_weight_is_written_with_a_comma_in_croatian_and_sits_in_the_row_of_its_category(): void
    {
        app()->setLocale('hr');
        $ana = $this->child('Babić', 'Ana');
        $this->enter($ana, $this->kata);
        $this->enter($ana, $this->kumite, '45.5');

        $html = preg_replace('/\s+/', ' ', $this->renderedHtml());

        $this->assertStringContainsString('<td>Kadeti - Kata</td> <td>–</td>', $html);
        $this->assertStringContainsString('<td>Kadeti - Kumite -52 kg</td> <td>45,5</td>', $html);
    }

    public function test_the_later_rows_of_a_competitor_have_empty_leading_cells_and_no_rowspan(): void
    {
        $ana = $this->child('Babić', 'Ana');
        $this->enter($ana, $this->kata);
        $this->enter($ana, $this->kumite, '45');
        $this->enter($this->child('Čačić', 'Đurđica'), $this->kata);

        $html = preg_replace('/\s+/', ' ', $this->renderedHtml());

        $this->assertStringNotContainsString('rowspan', $html);
        $this->assertStringContainsString('<td>1</td> <td><strong>Babić Ana</strong></td> <td>2011</td> <td>Kadeti - Kata</td>', $html);
        $this->assertStringContainsString('<td></td> <td></td> <td></td> <td>Kadeti - Kumite -52 kg</td> <td>45</td>', $html);
        $this->assertSame(1, substr_count($html, 'Babić Ana'));
    }

    public function test_a_big_club_makes_a_multi_page_pdf(): void
    {
        foreach (range(1, 60) as $number) {
            $child = $this->child('Prezime'.str_pad((string) $number, 3, '0', STR_PAD_LEFT), 'Ime');
            $this->enter($child, $this->kata);

            if ($number % 2 === 0) {
                $this->enter($child, $this->kumite, '40.5');
            }
        }

        $this->actingAs(User::factory()->admin()->create());
        $pdf = $this->get($this->url())->assertOk()->getContent();

        $this->assertGreaterThan(1, (int) preg_match_all('/\/Type\s*\/Page\b(?!s)/', $pdf));
    }

    private function page(): Testable
    {
        $this->actingAs(User::factory()->admin()->create());

        return Livewire::test('pages::admin.competitions.entries', ['competition' => $this->competition]);
    }

    public function test_the_panel_offers_the_confirmation_only_for_clubs_with_entries(): void
    {
        $this->enter($this->child('Babić', 'Ana'), $this->kata);
        $silent = Club::factory()->create(['name' => 'KK Šuti']);
        $this->competition->clubs()->attach($silent->id);

        $page = $this->page();

        $this->assertSame(1, substr_count($page->html(), 'data-test="club-overview-confirmation"'));
        $page->assertSeeHtml('confirmation.pdf?club='.$this->club->id);
        $page->assertSeeHtml('aria-label="Entry confirmation for KK Šibenik (Opens in a new tab)"');
        $page->assertDontSeeHtml('confirmation.pdf?club='.$silent->id);
    }
}
