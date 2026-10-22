<?php

namespace Tests\Feature\Models;

use App\Enums\CompetitionStatus;
use App\Enums\Discipline;
use App\Enums\Gender;
use App\Enums\UserRole;
use App\Models\Bout;
use App\Models\Category;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Competitor;
use App\Models\Entry;
use App\Models\Placement;
use App\Models\Tatami;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_factories_build_the_whole_domain_graph(): void
    {
        $entry = Entry::factory()->create();

        $this->assertInstanceOf(Category::class, $entry->category);
        $this->assertInstanceOf(Competition::class, $entry->category->competition);
        $this->assertInstanceOf(Competitor::class, $entry->competitor);
        $this->assertInstanceOf(Club::class, $entry->competitor->club);

        $tatami = Tatami::factory()->create();
        $bout = Bout::factory()->create(['aka_entry_id' => $entry->id]);
        $placement = Placement::factory()->create();

        $this->assertModelExists($tatami);
        $this->assertTrue($bout->aka->is($entry));
        $this->assertSame($placement->category_id, $placement->entry->category_id);
    }

    public function test_enum_casts_return_enum_instances(): void
    {
        $competition = Competition::factory()->create();
        $category = Category::factory()->kata()->create();
        $competitor = Competitor::factory()->create(['gender' => Gender::Female]);
        $user = User::factory()->create();

        $this->assertSame(CompetitionStatus::Draft, $competition->fresh()->status);
        $this->assertSame(Discipline::Kata, $category->fresh()->discipline);
        $this->assertNull($category->fresh()->gender);
        $this->assertSame(Gender::Female, $competitor->fresh()->gender);
        $this->assertSame(UserRole::Operator, $user->fresh()->role);
    }

    public function test_deleting_a_competition_cascades_to_its_data(): void
    {
        $competition = Competition::factory()->create();
        $tatami = Tatami::factory()->for($competition)->create();
        $category = Category::factory()->for($competition)->create();
        $entry = Entry::factory()->for($category)->create();
        Bout::factory()->for($category)->create(['aka_entry_id' => $entry->id]);
        Placement::factory()->create(['category_id' => $category->id, 'entry_id' => $entry->id]);

        $competition->delete();

        $this->assertModelMissing($tatami);
        $this->assertModelMissing($category);
        $this->assertModelMissing($entry);
        $this->assertSame(0, Bout::count());
        $this->assertSame(0, Placement::count());
        $this->assertModelExists($entry->competitor);
    }

    public function test_a_club_with_competitors_cannot_be_deleted(): void
    {
        $competitor = Competitor::factory()->create();

        $this->expectException(QueryException::class);

        $competitor->club->delete();
    }

    public function test_a_competitor_cannot_enter_the_same_category_twice(): void
    {
        $entry = Entry::factory()->create();

        $this->expectException(QueryException::class);

        Entry::factory()->create([
            'category_id' => $entry->category_id,
            'competitor_id' => $entry->competitor_id,
        ]);
    }

    public function test_user_roles_and_tatami_assignment(): void
    {
        $admin = User::factory()->admin()->create();
        $operator = User::factory()->create();
        $tatami = Tatami::factory()->create();

        $tatami->operators()->attach($operator);

        $this->assertTrue($admin->isAdmin());
        $this->assertSame(UserRole::Operator, $operator->role);
        $this->assertFalse($operator->isAdmin());
        $this->assertTrue($operator->tatamis->first()->is($tatami));
        $this->assertTrue($tatami->operators->first()->is($operator));
    }
}
