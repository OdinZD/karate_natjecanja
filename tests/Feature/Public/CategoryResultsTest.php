<?php

namespace Tests\Feature\Public;

use App\Actions\Draw\RecordBoutResult;
use App\Actions\Results\CategoryResults;
use App\Enums\BoutDecision;
use App\Enums\BoutSlot;
use App\Enums\Discipline;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Entry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Draw\InteractsWithDraws;
use Tests\TestCase;

class CategoryResultsTest extends TestCase
{
    use InteractsWithDraws;
    use RefreshDatabase;

    private Competition $competition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->competition = Competition::factory()->create();
    }

    private function results(?Discipline $discipline = null): Collection
    {
        return app(CategoryResults::class)->handle($this->competition, $discipline);
    }

    /**
     * @param  array<int, Entry>  $entries
     * @return array<int, int|null>
     */
    private function seeds(array $entries): array
    {
        return array_map(fn ($entry) => $entry->seed, $entries);
    }

    public function test_only_categories_with_placements_are_returned(): void
    {
        $played = $this->drawnCategory(4, competition: $this->competition);
        $this->playAll($played);
        $this->drawnCategory(4, competition: $this->competition);
        Category::factory()->for($this->competition)->create();
        $this->drawnCategory(4);

        $rows = $this->results();

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]->category->is($played));
    }

    public function test_the_podium_lists_first_second_and_both_bronze_medallists(): void
    {
        $category = $this->drawnCategory(8, competition: $this->competition);
        $this->playAll($category);

        $row = $this->results()->sole();

        $this->assertSame([1], $this->seeds($row->first));
        $this->assertSame([2], $this->seeds($row->second));
        $this->assertEqualsCanonicalizing([3, 4], $this->seeds($row->third));
        $this->assertTrue($row->complete);
    }

    public function test_a_category_in_progress_is_incomplete_and_shows_what_is_decided(): void
    {
        $category = $this->drawnCategory(4, competition: $this->competition);
        $semifinal = $this->bout($category, 1, 1);

        app(RecordBoutResult::class)->handle($semifinal, BoutSlot::Aka, null, null, BoutDecision::Hantei);

        $row = $this->results()->sole();

        $this->assertSame([], $row->first);
        $this->assertSame([], $row->second);
        $this->assertCount(1, $row->third);
        $this->assertFalse($row->complete);
    }

    public function test_the_discipline_filter_narrows_the_list(): void
    {
        $kumite = $this->drawnCategory(4, Discipline::Kumite, competition: $this->competition);
        $kata = $this->drawnCategory(4, Discipline::Kata, competition: $this->competition);
        $this->playAll($kumite);
        $this->playAll($kata);

        $this->assertCount(2, $this->results());
        $this->assertTrue($this->results(Discipline::Kata)->sole()->category->is($kata));
        $this->assertTrue($this->results(Discipline::Kumite)->sole()->category->is($kumite));
    }

    public function test_the_number_of_queries_does_not_depend_on_the_number_of_categories(): void
    {
        $this->playAll($this->drawnCategory(4, competition: $this->competition));
        $this->playAll($this->drawnCategory(4, competition: $this->competition));

        $few = $this->countQueries(fn () => $this->results());

        foreach (range(1, 6) as $ignored) {
            $this->playAll($this->drawnCategory(4, competition: $this->competition));
        }

        $this->assertSame($few, $this->countQueries(fn () => $this->results()));
    }

    private function countQueries(\Closure $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
