<?php

namespace Tests\Feature\Admin;

use App\Enums\Discipline;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Entry;
use App\Models\Tatami;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class TimetablePageTest extends TestCase
{
    use RefreshDatabase;

    private Competition $competition;

    private Tatami $tatami;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        $this->actingAs(User::factory()->admin()->create());
        $this->competition = Competition::factory()->create(['date' => '2026-12-05', 'start_time' => '10:00:00']);
        $this->tatami = Tatami::factory()->for($this->competition)->create(['number' => 1]);
    }

    private function page(): Testable
    {
        return Livewire::test('pages::admin.competitions.timetable', ['competition' => $this->competition]);
    }

    private function category(string $name, ?Tatami $tatami = null, int $order = 0): Category
    {
        $category = Category::factory()->for($this->competition)->create([
            'name' => $name,
            'discipline' => Discipline::Kata,
            'tatami_id' => $tatami?->id,
            'sort_order' => $order,
        ]);
        Entry::factory()->count(4)->for($category)->create();

        return $category;
    }

    public function test_the_competition_page_links_to_the_time_table(): void
    {
        $this->get(route('admin.competitions.show', $this->competition))
            ->assertOk()
            ->assertSee(route('admin.competitions.timetable', $this->competition));
    }

    public function test_a_competition_without_tatamis_explains_what_to_do(): void
    {
        $empty = Competition::factory()->create();

        Livewire::test('pages::admin.competitions.timetable', ['competition' => $empty])
            ->assertSee('no tatamis yet');
    }

    public function test_categories_can_be_assigned_moved_and_removed(): void
    {
        $a = $this->category('Alpha');
        $b = $this->category('Bravo');

        $page = $this->page()
            ->call('assign', $a->id, $this->tatami->id)
            ->call('assign', $b->id, $this->tatami->id);

        $this->assertSame(['Alpha', 'Bravo'], Category::where('tatami_id', $this->tatami->id)->orderBy('sort_order')->pluck('name')->all());

        $page->call('move', $b->id, 'up');
        $this->assertSame(['Bravo', 'Alpha'], Category::where('tatami_id', $this->tatami->id)->orderBy('sort_order')->pluck('name')->all());

        $page->call('unassign', $a->id);
        $this->assertNull($a->fresh()->tatami_id);
        $this->assertSame($this->tatami->id, $b->fresh()->tatami_id);
    }

    public function test_the_estimated_times_are_shown(): void
    {
        $this->category('Alpha', $this->tatami, 1);
        $this->category('Bravo', $this->tatami, 2);

        // 4 kata entries = 3 bouts of 120 + 60 seconds = 9 minutes per category.
        $this->page()
            ->assertSee('10:00–10:09')
            ->assertSee('10:09–10:18')
            ->assertSee('estimated end 10:18')
            ->assertSee('6 bouts')
            ->assertSee('0 h 18 min');
    }

    public function test_unassigned_categories_are_offered_with_the_tatamis(): void
    {
        $this->category('Loose');
        Tatami::factory()->for($this->competition)->create(['number' => 2]);

        $this->page()
            ->assertSee('Categories without a tatami')
            ->assertSee('Loose')
            ->assertSee('Tatami 1')
            ->assertSee('Tatami 2');
    }

    public function test_ids_of_another_competition_are_rejected(): void
    {
        $foreignCategory = Category::factory()->create();
        $foreignTatami = Tatami::factory()->create();
        $own = $this->category('Own');

        $this->page()->call('assign', $foreignCategory->id, $this->tatami->id)->assertStatus(404);
        $this->page()->call('assign', $own->id, $foreignTatami->id)->assertStatus(404);
        $this->page()->call('unassign', $foreignCategory->id)->assertStatus(404);
        $this->page()->call('move', $foreignCategory->id, 'up')->assertStatus(404);

        $this->assertNull($own->fresh()->tatami_id);
        $this->assertNull($foreignCategory->fresh()->tatami_id);
    }

    public function test_an_unknown_direction_is_rejected(): void
    {
        $category = $this->category('Alpha', $this->tatami, 1);

        $this->page()->call('move', $category->id, 'sideways')->assertStatus(404);
    }

    public function test_every_row_links_to_the_bracket_of_its_category(): void
    {
        $category = $this->category('Alpha', $this->tatami, 1);

        $this->page()->assertSee(route('admin.competitions.bracket', [$this->competition, $category]));
    }
}
