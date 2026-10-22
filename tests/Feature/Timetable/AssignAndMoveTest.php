<?php

namespace Tests\Feature\Timetable;

use App\Actions\Timetable\AssignCategoryToTatami;
use App\Actions\Timetable\MoveCategory;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Tatami;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class AssignAndMoveTest extends TestCase
{
    use RefreshDatabase;

    private Competition $competition;

    private Tatami $tatami;

    protected function setUp(): void
    {
        parent::setUp();

        $this->competition = Competition::factory()->create();
        $this->tatami = Tatami::factory()->for($this->competition)->create();
    }

    private function category(string $name, int $order = 0, ?Tatami $tatami = null): Category
    {
        return Category::factory()->for($this->competition)->create([
            'name' => $name,
            'sort_order' => $order,
            'tatami_id' => $tatami?->id,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function schedule(Tatami $tatami): array
    {
        return Category::where('tatami_id', $tatami->id)->orderBy('sort_order')->orderBy('id')->pluck('name')->all();
    }

    public function test_a_category_is_appended_to_the_tatami(): void
    {
        $this->category('A', 3, $this->tatami);
        $b = $this->category('B', 0);

        app(AssignCategoryToTatami::class)->handle($b, $this->tatami);

        $this->assertSame(['A', 'B'], $this->schedule($this->tatami));
        $this->assertSame(4, $b->fresh()->sort_order);
    }

    public function test_assigning_to_another_tatami_moves_the_category(): void
    {
        $other = Tatami::factory()->for($this->competition)->create();
        $a = $this->category('A', 1, $this->tatami);

        app(AssignCategoryToTatami::class)->handle($a, $other);

        $this->assertSame([], $this->schedule($this->tatami));
        $this->assertSame(['A'], $this->schedule($other));
    }

    public function test_assigning_to_the_same_tatami_keeps_the_position(): void
    {
        $a = $this->category('A', 1, $this->tatami);
        $this->category('B', 2, $this->tatami);

        app(AssignCategoryToTatami::class)->handle($a, $this->tatami);

        $this->assertSame(['A', 'B'], $this->schedule($this->tatami));
    }

    public function test_a_category_can_be_taken_off_the_schedule(): void
    {
        $a = $this->category('A', 1, $this->tatami);

        app(AssignCategoryToTatami::class)->handle($a, null);

        $this->assertNull($a->fresh()->tatami_id);
    }

    public function test_a_tatami_of_another_competition_is_refused(): void
    {
        $foreign = Tatami::factory()->create();
        $a = $this->category('A');

        $this->expectException(InvalidArgumentException::class);

        app(AssignCategoryToTatami::class)->handle($a, $foreign);
    }

    public function test_categories_move_up_and_down_even_when_stored_orders_are_duplicated(): void
    {
        $a = $this->category('A', 0, $this->tatami);
        $b = $this->category('B', 0, $this->tatami);
        $c = $this->category('C', 0, $this->tatami);
        $mover = app(MoveCategory::class);

        $mover->handle($c, 'up');
        $this->assertSame(['A', 'C', 'B'], $this->schedule($this->tatami));

        $mover->handle($c, 'up');
        $this->assertSame(['C', 'A', 'B'], $this->schedule($this->tatami));

        $mover->handle($a, 'down');
        $this->assertSame(['C', 'B', 'A'], $this->schedule($this->tatami));

        $this->assertSame([1, 2, 3], Category::where('tatami_id', $this->tatami->id)->orderBy('sort_order')->pluck('sort_order')->all());
        $this->assertSame(1, $c->sort_order);
        $this->assertSame(3, $a->fresh()->sort_order);
        $this->assertSame(2, $b->fresh()->sort_order);
    }

    public function test_the_first_cannot_move_up_and_the_last_cannot_move_down(): void
    {
        $a = $this->category('A', 1, $this->tatami);
        $b = $this->category('B', 2, $this->tatami);
        $mover = app(MoveCategory::class);

        $mover->handle($a, 'up');
        $mover->handle($b, 'down');

        $this->assertSame(['A', 'B'], $this->schedule($this->tatami));
    }

    public function test_moving_only_affects_the_own_tatami_and_ignores_unassigned_categories(): void
    {
        $other = Tatami::factory()->for($this->competition)->create();
        $this->category('A', 1, $this->tatami);
        $b = $this->category('B', 2, $this->tatami);
        $this->category('X', 1, $other);
        $loose = $this->category('Loose', 5);

        app(MoveCategory::class)->handle($b, 'up');
        app(MoveCategory::class)->handle($loose, 'up');

        $this->assertSame(['B', 'A'], $this->schedule($this->tatami));
        $this->assertSame(['X'], $this->schedule($other));
        $this->assertSame(5, $loose->fresh()->sort_order);
    }

    public function test_an_unknown_direction_is_refused(): void
    {
        $a = $this->category('A', 1, $this->tatami);

        $this->expectException(InvalidArgumentException::class);

        app(MoveCategory::class)->handle($a, 'sideways');
    }
}
