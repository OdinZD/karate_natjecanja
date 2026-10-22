<?php

namespace Tests\Feature\Public;

use App\Actions\Results\ClubStandings;
use App\Models\Category;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Competitor;
use App\Models\Entry;
use App\Models\Placement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClubStandingsTest extends TestCase
{
    use RefreshDatabase;

    private Competition $competition;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->competition = Competition::factory()->create();
        $this->category = Category::factory()->for($this->competition)->create();
    }

    private function club(string $name): Club
    {
        return Club::factory()->create(['name' => $name, 'city' => null]);
    }

    private function medal(Club $club, int $place, ?Category $category = null): void
    {
        $category ??= $this->category;
        $entry = Entry::factory()->for($category)->for(Competitor::factory()->for($club))->create();

        Placement::factory()->create(['category_id' => $category->id, 'entry_id' => $entry->id, 'place' => $place]);
    }

    /**
     * @param  array<int, int>  $medals  [gold, silver, bronze]
     */
    private function award(Club $club, array $medals, ?Category $category = null): void
    {
        foreach ($medals as $index => $count) {
            for ($i = 0; $i < $count; $i++) {
                $this->medal($club, $index + 1, $category);
            }
        }
    }

    private function table(): array
    {
        return app(ClubStandings::class)->handle($this->competition)
            ->map(fn ($row) => [$row->rank, $row->club, $row->gold, $row->silver, $row->bronze, $row->total])
            ->all();
    }

    public function test_clubs_are_ranked_by_gold_then_silver_then_bronze_with_shared_ranks(): void
    {
        $this->award($this->club('Alfa'), [2, 0, 0]);
        $this->award($this->club('Charlie'), [1, 1, 0]);
        $this->award($this->club('Bravo'), [1, 1, 0]);
        $this->award($this->club('Echo'), [1, 0, 9]);
        $this->award($this->club('Delta'), [0, 0, 3]);

        $this->assertSame([
            [1, 'Alfa', 2, 0, 0, 2],
            [2, 'Bravo', 1, 1, 0, 2],
            [2, 'Charlie', 1, 1, 0, 2],
            [4, 'Echo', 1, 0, 9, 10],
            [5, 'Delta', 0, 0, 3, 3],
        ], $this->table());
    }

    public function test_both_bronze_medals_of_one_club_are_counted(): void
    {
        $this->award($this->club('Alfa'), [0, 0, 2]);

        $this->assertSame([[1, 'Alfa', 0, 0, 2, 2]], $this->table());
    }

    public function test_clubs_without_medals_and_other_competitions_are_left_out(): void
    {
        $this->award($this->club('Alfa'), [1, 0, 0]);

        $silent = $this->club('Silent');
        Entry::factory()->for($this->category)->for(Competitor::factory()->for($silent))->create();

        $other = Category::factory()->for(Competition::factory()->create())->create();
        $this->award($this->club('Foreign'), [3, 3, 3], $other);

        $this->assertSame([[1, 'Alfa', 1, 0, 0, 1]], $this->table());
    }

    public function test_an_empty_competition_has_an_empty_table(): void
    {
        $this->assertSame([], $this->table());
    }

    public function test_the_city_is_passed_through(): void
    {
        $club = Club::factory()->create(['name' => 'Alfa', 'city' => 'Ivanec']);
        $this->award($club, [1, 0, 0]);

        $this->assertSame('Ivanec', app(ClubStandings::class)->handle($this->competition)->first()->city);
    }
}
