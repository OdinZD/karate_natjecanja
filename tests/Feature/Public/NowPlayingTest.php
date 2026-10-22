<?php

namespace Tests\Feature\Public;

use App\Actions\Draw\RecordBoutResult;
use App\Actions\Timetable\NowPlaying;
use App\Actions\Timetable\NowPlayingRow;
use App\Actions\Timetable\TimetableBuilder;
use App\Enums\BoutDecision;
use App\Enums\BoutSlot;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Tatami;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Draw\InteractsWithDraws;
use Tests\TestCase;

class NowPlayingTest extends TestCase
{
    use InteractsWithDraws;
    use RefreshDatabase;

    private Competition $competition;

    private Tatami $tatami;

    protected function setUp(): void
    {
        parent::setUp();

        $this->competition = Competition::factory()->create();
        $this->tatami = Tatami::factory()->for($this->competition)->create(['number' => 1]);
    }

    private function scheduled(int $entries, int $order): Category
    {
        $category = $this->drawnCategory($entries, competition: $this->competition);
        $category->update(['tatami_id' => $this->tatami->id, 'sort_order' => $order]);

        return $category;
    }

    /**
     * @return array<int, NowPlayingRow>
     */
    private function nowPlaying(): array
    {
        return app(NowPlaying::class)->handle(app(TimetableBuilder::class)->build($this->competition));
    }

    private function playFirst(Category $category): void
    {
        $bout = $category->bouts()->where('status', 'ready')->orderBy('id')->firstOrFail();

        app(RecordBoutResult::class)->handle($bout, BoutSlot::Aka, null, null, BoutDecision::Hantei);
    }

    public function test_the_first_pending_category_is_active_when_nothing_is_running(): void
    {
        $first = $this->scheduled(4, 1);
        $this->scheduled(4, 2);

        $row = $this->nowPlaying()[0];

        $this->assertTrue($row->category->is($first));
        $this->assertTrue($row->queue->current->is($this->bout($first, 1, 1)));
    }

    public function test_a_running_category_wins_over_an_earlier_pending_one(): void
    {
        $this->scheduled(4, 1);
        $running = $this->scheduled(4, 2);
        $this->playFirst($running);

        $this->assertTrue($this->nowPlaying()[0]->category->is($running));
    }

    public function test_finished_and_undrawn_categories_are_skipped(): void
    {
        $finished = $this->scheduled(2, 1);
        $this->playAll($finished);
        Category::factory()->for($this->competition)->create(['tatami_id' => $this->tatami->id, 'sort_order' => 2]);
        $pending = $this->scheduled(2, 3);

        $this->assertTrue($this->nowPlaying()[0]->category->is($pending));
    }

    public function test_a_tatami_without_an_active_category_has_nothing_playing(): void
    {
        $finished = $this->scheduled(2, 1);
        $this->playAll($finished);
        Tatami::factory()->for($this->competition)->create(['number' => 2]);

        $rows = $this->nowPlaying();

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertNull($row->category);
            $this->assertNull($row->queue);
        }
    }

    public function test_the_current_bout_moves_on_after_a_result(): void
    {
        $category = $this->scheduled(4, 1);

        $this->assertTrue($this->nowPlaying()[0]->queue->current->is($this->bout($category, 1, 1)));

        $this->playFirst($category);

        $queue = $this->nowPlaying()[0]->queue;
        $this->assertTrue($queue->current->is($this->bout($category, 1, 2)));
        $this->assertCount(1, $queue->recent);
    }
}
