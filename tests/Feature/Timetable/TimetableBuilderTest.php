<?php

namespace Tests\Feature\Timetable;

use App\Actions\Draw\GenerateDraw;
use App\Actions\Draw\RecordBoutResult;
use App\Actions\Timetable\TimetableBuilder;
use App\Enums\BoutDecision;
use App\Enums\BoutSlot;
use App\Enums\Discipline;
use App\Enums\DrawProgress;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Entry;
use App\Models\Tatami;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Draw\InteractsWithDraws;
use Tests\TestCase;

class TimetableBuilderTest extends TestCase
{
    use InteractsWithDraws;
    use RefreshDatabase;

    private Competition $competition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->competition = Competition::factory()->create(['date' => '2026-12-05', 'start_time' => '10:00:00']);
    }

    private function category(string $name, int $entries, Discipline $discipline, int $duration, ?Tatami $tatami, int $order): Category
    {
        $category = Category::factory()->for($this->competition)->create([
            'name' => $name,
            'discipline' => $discipline,
            'bout_duration_seconds' => $duration,
            'tatami_id' => $tatami?->id,
            'sort_order' => $order,
        ]);
        Entry::factory()->count($entries)->for($category)->create();

        return $category;
    }

    public function test_categories_follow_each_other_on_a_tatami_from_the_start_time(): void
    {
        $tatami = Tatami::factory()->for($this->competition)->create(['number' => 1]);
        $this->category('Second', 4, Discipline::Kata, 90, $tatami, 2);
        $this->category('First', 8, Discipline::Kumite, 120, $tatami, 1);

        $table = (new TimetableBuilder)->build($this->competition);

        $this->assertCount(1, $table);
        [$first, $second] = $table[0]->rows;

        $this->assertSame('First', $first->category->name);
        $this->assertSame(9, $first->bouts);
        $this->assertSame(8, $first->entries);
        $this->assertSame('2026-12-05 10:00:00', $first->start->format('Y-m-d H:i:s'));
        $this->assertSame('10:27:00', $first->end->format('H:i:s'));

        $this->assertSame('Second', $second->category->name);
        $this->assertSame(3, $second->bouts);
        $this->assertSame('10:27:00', $second->start->format('H:i:s'));
        $this->assertSame('10:34:30', $second->end->format('H:i:s'));
        $this->assertEquals($second->end, $table[0]->end);
        $this->assertSame(12, $table[0]->totalBouts());
        $this->assertSame(35, $table[0]->durationMinutes());
    }

    public function test_tatamis_run_in_parallel_and_empty_ones_end_at_the_start(): void
    {
        $one = Tatami::factory()->for($this->competition)->create(['number' => 1]);
        $two = Tatami::factory()->for($this->competition)->create(['number' => 2]);
        $empty = Tatami::factory()->for($this->competition)->create(['number' => 3]);
        $this->category('On one', 2, Discipline::Kata, 120, $one, 1);
        $this->category('On two', 2, Discipline::Kata, 120, $two, 1);

        $table = (new TimetableBuilder)->build($this->competition);

        $this->assertSame([1, 2, 3], array_map(fn ($tatami) => $tatami->tatami->number, $table));
        $this->assertSame('10:03:00', $table[0]->end->format('H:i:s'));
        $this->assertSame('10:00:00', $table[0]->rows[0]->start->format('H:i:s'));
        $this->assertSame('10:00:00', $table[1]->rows[0]->start->format('H:i:s'));
        $this->assertSame([], $table[2]->rows);
        $this->assertSame('10:00:00', $table[2]->end->format('H:i:s'));
        $this->assertSame($empty->id, $table[2]->tatami->id);
    }

    public function test_categories_without_a_tatami_are_listed_separately(): void
    {
        $tatami = Tatami::factory()->for($this->competition)->create();
        $this->category('Scheduled', 2, Discipline::Kata, 120, $tatami, 1);
        $this->category('Loose', 2, Discipline::Kata, 120, null, 1);

        $this->assertSame(['Loose'], (new TimetableBuilder)->unassigned($this->competition)->pluck('name')->all());
        $this->assertSame(['Scheduled'], collect((new TimetableBuilder)->build($this->competition)[0]->rows)->map(fn ($row) => $row->category->name)->all());
    }

    public function test_progress_follows_the_draw_and_the_results(): void
    {
        $category = $this->category('Progress', 4, Discipline::Kumite, 120, null, 1);
        $builder = new TimetableBuilder;

        $this->assertSame(DrawProgress::NoDraw, $builder->progress($builder->unassigned($this->competition)->first()));

        app(GenerateDraw::class)->handle($category);
        $this->assertSame(DrawProgress::Pending, $builder->progress($builder->unassigned($this->competition)->first()));

        $this->playOne($category);
        $this->assertSame(DrawProgress::Running, $builder->progress($builder->unassigned($this->competition)->first()));

        $this->playAll($category);
        $this->assertSame(DrawProgress::Finished, $builder->progress($builder->unassigned($this->competition)->first()));
    }

    private function playOne(Category $category): void
    {
        $bout = $category->bouts()->where('status', 'ready')->orderBy('id')->firstOrFail();

        app(RecordBoutResult::class)->handle($bout, BoutSlot::Aka, null, null, BoutDecision::Hantei);
    }
}
