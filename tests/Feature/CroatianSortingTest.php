<?php

namespace Tests\Feature;

use App\Actions\Results\CategoryResults;
use App\Actions\Results\ClubStandings;
use App\Models\Category;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Competitor;
use App\Models\Entry;
use App\Models\Placement;
use App\Models\User;
use App\Support\Croatian;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\TestCase;

class CroatianSortingTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private const NAMES = ['Cvitan', 'Čolić', 'Ćorić', 'Dukić', 'Džeko', 'Đurić', 'Sabo', 'Šimić', 'Zec', 'Žic'];

    public function test_compare_follows_croatian_alphabetical_order(): void
    {
        $shuffled = collect(self::NAMES)->shuffle(1);

        $this->assertSame(self::NAMES, Croatian::sortBy($shuffled, fn (string $name) => $name)->all());
    }

    public function test_compare_is_case_insensitive_at_the_first_level(): void
    {
        $this->assertSame(['babić', 'Babić', 'Cvitan'], Croatian::sortBy(collect(['Cvitan', 'Babić', 'babić']), fn (string $name) => $name)->all());
    }

    public function test_the_order_by_name_macro_sorts_in_croatian_order(): void
    {
        $club = Club::factory()->create();

        foreach (collect(self::NAMES)->shuffle(2) as $name) {
            Competitor::factory()->for($club)->create(['last_name' => $name]);
        }

        $this->assertSame(self::NAMES, Competitor::query()->orderByName('last_name')->pluck('last_name')->all());
        $this->assertSame(array_reverse(self::NAMES), Competitor::query()->orderByName('last_name', 'desc')->pluck('last_name')->all());
        $this->assertSame(self::NAMES, $club->competitors()->orderByName('last_name')->pluck('last_name')->all());
    }

    public function test_plain_ordering_would_put_the_croatian_letters_last(): void
    {
        $club = Club::factory()->create();

        foreach (['Zec', 'Čolić', 'Cvitan'] as $name) {
            Competitor::factory()->for($club)->create(['last_name' => $name]);
        }

        $this->assertNotSame(['Cvitan', 'Čolić', 'Zec'], Competitor::query()->orderBy('last_name')->pluck('last_name')->all());
        $this->assertSame(['Cvitan', 'Čolić', 'Zec'], Competitor::query()->orderByName('last_name')->pluck('last_name')->all());
    }

    public function test_the_admin_competitors_list_is_in_croatian_order(): void
    {
        $club = Club::factory()->create();

        foreach (collect(self::NAMES)->shuffle(3) as $name) {
            Competitor::factory()->for($club)->create(['last_name' => $name, 'first_name' => 'Ana']);
        }

        $this->actingAs(User::factory()->admin()->create());

        $page = Livewire::test('pages::admin.competitors.index');

        $this->assertSame(array_slice(self::NAMES, 0, 10), $page->get('competitors')->pluck('last_name')->take(10)->all());
    }

    public function test_club_standings_use_croatian_order(): void
    {
        $competition = Competition::factory()->create();
        $category = Category::factory()->for($competition)->create();
        $names = ['KK Cvitan', 'KK Čolić', 'KK Đurić', 'KK Sabo', 'KK Šimić'];

        foreach (collect($names)->shuffle(4) as $name) {
            $club = Club::factory()->create(['name' => $name]);
            Placement::factory()->create([
                'category_id' => $category->id,
                'entry_id' => Entry::factory()->for($category)->create(['competitor_id' => Competitor::factory()->for($club)->create()->id])->id,
                'place' => 1,
            ]);
        }

        $standings = app(ClubStandings::class)->handle($competition)->pluck('club')->all();

        $this->assertSame($names, $standings);
    }

    public function test_results_list_the_medallists_of_a_place_in_croatian_order(): void
    {
        $competition = Competition::factory()->create();
        $category = Category::factory()->for($competition)->create();

        foreach (['Žic', 'Sabo', 'Čolić', 'Cvitan'] as $name) {
            $entry = Entry::factory()->for($category)->create(['competitor_id' => Competitor::factory()->create(['last_name' => $name])->id]);
            Placement::factory()->create(['category_id' => $category->id, 'entry_id' => $entry->id, 'place' => 3]);
        }

        $third = app(CategoryResults::class)->handle($competition)->first()->third;

        $this->assertSame(['Cvitan', 'Čolić', 'Sabo', 'Žic'], Collection::make($third)->map(fn (Entry $entry) => $entry->competitor->last_name)->all());
    }

    public function test_the_admin_clubs_list_uses_croatian_order(): void
    {
        foreach (collect(['KK Cvitan', 'KK Čolić', 'KK Đurić', 'KK Sabo', 'KK Šimić'])->shuffle(5) as $name) {
            Club::factory()->create(['name' => $name]);
        }

        $this->actingAs(User::factory()->admin()->create());

        $names = Livewire::test('pages::admin.clubs.index')->get('clubs')->pluck('name')->all();

        $this->assertSame(['KK Cvitan', 'KK Čolić', 'KK Đurić', 'KK Sabo', 'KK Šimić'], $names);
    }
}
