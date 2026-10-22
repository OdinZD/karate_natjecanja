<?php

namespace Tests\Feature\Public;

use App\Enums\CompetitionStatus;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Entry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompetitionVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_drafts_are_not_visible_but_every_other_status_is(): void
    {
        foreach (CompetitionStatus::cases() as $status) {
            Competition::factory()->create(['status' => $status]);
        }

        $visible = Competition::visible()->pluck('status')->map(fn (CompetitionStatus $status) => $status->value)->all();

        $this->assertEqualsCanonicalizing(['registration', 'drawn', 'in_progress', 'finished'], $visible);
    }

    public function test_entries_are_counted_through_the_categories_of_the_competition_only(): void
    {
        $competition = Competition::factory()->create();
        $first = Category::factory()->for($competition)->create();
        $second = Category::factory()->for($competition)->create();
        Entry::factory()->count(2)->for($first)->create();
        Entry::factory()->count(3)->for($second)->create();
        Entry::factory()->count(4)->for(Category::factory()->create())->create();

        $this->assertSame(5, $competition->entries()->count());
        $this->assertSame(5, Competition::withCount('entries')->findOrFail($competition->id)->entries_count);
    }
}
