<?php

namespace Tests\Feature\Public;

use App\Actions\Draw\RecordBoutResult;
use App\Enums\BoutDecision;
use App\Enums\BoutSlot;
use App\Enums\CompetitionStatus;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Tatami;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Draw\InteractsWithDraws;
use Tests\TestCase;

class PublicQueryCountTest extends TestCase
{
    use InteractsWithDraws;
    use RefreshDatabase;

    private Competition $competition;

    private Tatami $tatami;

    protected function setUp(): void
    {
        parent::setUp();

        $this->competition = Competition::factory()->create(['status' => CompetitionStatus::InProgress]);
        $this->tatami = Tatami::factory()->for($this->competition)->create(['number' => 1]);
    }

    /**
     * Add finished categories to the tatami.
     */
    private function grow(int $categories, int $entries): void
    {
        foreach (range(1, $categories) as $ignored) {
            $category = $this->drawnCategory($entries, competition: $this->competition);
            $category->update(['tatami_id' => $this->tatami->id, 'sort_order' => $category->id]);
            $this->playAll($category);
        }
    }

    /**
     * A category that is being played right now: one bout decided, the rest still to come, so the
     * "now playing" board and the highlighted bout of the bracket are rendered.
     */
    private function startLive(int $entries): Category
    {
        $category = $this->drawnCategory($entries, competition: $this->competition);
        $category->update(['tatami_id' => $this->tatami->id, 'sort_order' => 1000 + $category->id]);

        app(RecordBoutResult::class)->handle($this->bout($category, 1, 1), BoutSlot::Aka, null, null, BoutDecision::Hantei);

        return $category;
    }

    private function queries(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @return array<string, array{string, int}>
     */
    private function pages(Category $category): array
    {
        return [
            'categories' => [route('public.competitions.categories', $this->competition), 14],
            'competitors' => [route('public.competitions.competitors', $this->competition), 14],
            'draw' => [route('public.competitions.draw', $this->competition), 14],
            'bracket' => [route('public.competitions.bracket', [$this->competition, $category]), 20],
            'timetable' => [route('public.competitions.timetable', $this->competition), 16],
            'results' => [route('public.competitions.results', $this->competition), 14],
            'standings' => [route('public.competitions.standings', $this->competition), 14],
        ];
    }

    public function test_the_number_of_queries_does_not_grow_with_the_amount_of_data(): void
    {
        $this->grow(2, 4);
        $live = $this->startLive(8);

        $small = [];
        foreach ($this->pages($live) as $name => [$url]) {
            $small[$name] = $this->queries($url);
        }

        $this->grow(5, 8);
        $live = $this->startLive(16);

        foreach ($this->pages($live) as $name => [$url, $ceiling]) {
            $large = $this->queries($url);

            $this->assertSame($small[$name], $large, "{$name} issues more queries with more data ({$small[$name]} vs {$large})");
            $this->assertLessThanOrEqual($ceiling, $large, "{$name} issues {$large} queries");
        }
    }
}
