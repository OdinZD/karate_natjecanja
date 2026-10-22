<?php

namespace Tests\Feature\Admin;

use App\Actions\Draw\DrawArranger;
use App\Actions\Draw\DrawException;
use App\Actions\Draw\GenerateDraw;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Entry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Draw\InteractsWithDraws;
use Tests\TestCase;

class DrawPageTest extends TestCase
{
    use InteractsWithDraws;
    use RefreshDatabase;

    private Competition $competition;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        $this->actingAs(User::factory()->admin()->create());
        $this->competition = Competition::factory()->create();
    }

    private function page(): Testable
    {
        return Livewire::test('pages::admin.competitions.draw', ['competition' => $this->competition]);
    }

    private function category(int $entries, string $name = 'Category'): Category
    {
        $category = Category::factory()->for($this->competition)->create(['name' => $name]);
        Entry::factory()->count($entries)->for($category)->create();

        return $category;
    }

    public function test_the_competition_page_links_to_the_draw(): void
    {
        $this->get(route('admin.competitions.show', $this->competition))
            ->assertOk()
            ->assertSee(route('admin.competitions.draw', $this->competition));
    }

    public function test_admin_can_generate_and_regenerate_a_draw(): void
    {
        $category = $this->category(4);

        $this->page()->call('generate', $category->id);

        $this->assertSame(3, $category->bouts()->count());
        $ids = $category->bouts()->pluck('id')->all();

        $this->page()->call('generate', $category->id);

        $this->assertSame(3, $category->bouts()->count());
        $this->assertEmpty(array_intersect($ids, $category->bouts()->pluck('id')->all()));
    }

    public function test_a_category_with_fewer_than_two_entries_gets_no_draw(): void
    {
        $category = $this->category(1);

        $this->page()->call('generate', $category->id)->assertHasNoErrors();

        $this->assertFalse($category->hasDraw());
    }

    public function test_a_played_draw_is_kept_when_regeneration_or_deletion_is_attempted(): void
    {
        $category = $this->drawnCategory(4);
        $this->competition = $category->competition;
        $this->playAll($category);
        $ids = $category->bouts()->pluck('id')->all();

        $this->page()->call('generate', $category->id)->call('delete', $category->id);

        $this->assertSame($ids, $category->bouts()->pluck('id')->all());
        $this->assertSame(4, $category->placements()->count());
    }

    public function test_admin_can_delete_an_unplayed_draw(): void
    {
        $category = $this->category(4);
        app(GenerateDraw::class)->handle($category);

        $this->page()->call('delete', $category->id);

        $this->assertFalse($category->hasDraw());
    }

    public function test_generate_all_skips_drawn_and_too_small_categories(): void
    {
        $fresh = $this->category(4, 'Fresh');
        $single = $this->category(1, 'Single');
        $empty = $this->category(0, 'Empty');
        $drawn = $this->category(3, 'Drawn');
        app(GenerateDraw::class)->handle($drawn);
        $drawnIds = $drawn->bouts()->pluck('id')->all();

        $this->page()->call('generateAll');

        $this->assertTrue($fresh->hasDraw());
        $this->assertFalse($single->hasDraw());
        $this->assertFalse($empty->hasDraw());
        $this->assertSame($drawnIds, $drawn->bouts()->pluck('id')->all());
    }

    public function test_a_category_of_another_competition_cannot_be_drawn(): void
    {
        $foreign = Category::factory()->create();
        Entry::factory()->count(2)->for($foreign)->create();

        $this->page()->call('generate', $foreign->id)->assertStatus(404);

        $this->assertFalse($foreign->hasDraw());
    }

    public function test_seeds_can_be_saved_and_are_used_by_the_draw(): void
    {
        $category = $this->category(4);
        $entries = $category->entries()->orderBy('id')->get();

        $page = $this->page()->call('openSeeds', $category->id);

        $this->assertSame(['', '', '', ''], array_values($page->get('seeds')));

        $page->set("seeds.{$entries[2]->id}", '1')
            ->set("seeds.{$entries[0]->id}", '2')
            ->call('saveSeeds')
            ->assertHasNoErrors();

        $this->assertSame(1, $entries[2]->fresh()->seed);
        $this->assertSame(2, $entries[0]->fresh()->seed);
        $this->assertNull($entries[1]->fresh()->seed);

        app(GenerateDraw::class)->handle($category);

        $first = $category->bouts()->where('round', 1)->orderBy('position')->get();
        $this->assertSame($entries[2]->id, $first[0]->aka_entry_id);
        $this->assertSame($entries[0]->id, $first[1]->aka_entry_id);
    }

    public function test_seeds_must_be_unique_and_within_range(): void
    {
        $category = $this->category(3);
        $entries = $category->entries()->orderBy('id')->get();

        $this->page()
            ->call('openSeeds', $category->id)
            ->set("seeds.{$entries[0]->id}", '1')
            ->set("seeds.{$entries[1]->id}", '1')
            ->call('saveSeeds')
            ->assertHasErrors('seeds');

        $this->page()
            ->call('openSeeds', $category->id)
            ->set("seeds.{$entries[0]->id}", '4')
            ->call('saveSeeds')
            ->assertHasErrors("seeds.{$entries[0]->id}");

        $this->assertSame(0, Entry::whereNotNull('seed')->count());
    }

    public function test_seeds_cannot_be_changed_once_a_draw_exists(): void
    {
        $category = $this->category(3);
        $entry = $category->entries()->firstOrFail();

        $page = $this->page()->call('openSeeds', $category->id);
        app(GenerateDraw::class)->handle($category);

        $page->set("seeds.{$entry->id}", '1')->call('saveSeeds');

        $this->assertNull($entry->fresh()->seed);

        $this->page()->call('openSeeds', $category->id)->assertSet('seedCategoryId', null);
    }

    public function test_the_bracket_page_shows_rounds_repechage_and_results(): void
    {
        $category = $this->drawnCategory(8);
        $this->playAll($category);
        $competition = $category->competition;
        $winner = $category->placements()->where('place', 1)->firstOrFail()->entry->competitor;

        $this->get(route('admin.competitions.bracket', [$competition, $category]))
            ->assertOk()
            ->assertSee('Quarterfinals')
            ->assertSee('Semifinals')
            ->assertSee('Final')
            ->assertSee('Repechage')
            ->assertSee('1st place')
            ->assertSee($winner->last_name);
    }

    public function test_the_bracket_page_handles_a_category_without_a_draw_and_foreign_categories(): void
    {
        $category = Category::factory()->for($this->competition)->create();
        $foreign = Category::factory()->create();

        $this->get(route('admin.competitions.bracket', [$this->competition, $category]))
            ->assertOk()
            ->assertSee('No draw has been generated');

        $this->get(route('admin.competitions.bracket', [$this->competition, $foreign]))->assertNotFound();
    }

    public function test_equivalent_seed_spellings_count_as_duplicates(): void
    {
        $category = $this->category(3);
        $entries = $category->entries()->orderBy('id')->get();

        $this->page()
            ->call('openSeeds', $category->id)
            ->set("seeds.{$entries[0]->id}", '2')
            ->set("seeds.{$entries[1]->id}", '+2')
            ->call('saveSeeds')
            ->assertHasErrors('seeds');

        $this->assertSame(0, Entry::whereNotNull('seed')->count());
    }

    public function test_generate_all_continues_and_reports_a_category_whose_draw_fails(): void
    {
        $first = $this->category(4, 'Alpha');
        $bad = $this->category(4, 'Bad');
        $last = $this->category(4, 'Zulu');
        $small = $this->category(1, 'Small');

        $this->app->bind(GenerateDraw::class, fn () => new class(new DrawArranger) extends GenerateDraw
        {
            public function handle(Category $category): void
            {
                if ($category->name === 'Bad') {
                    throw new DrawException('Boom');
                }

                parent::handle($category);
            }
        });

        $this->page()
            ->call('generateAll')
            ->assertDispatched('toast-show', function (string $event, array $params) {
                $text = json_encode($params, JSON_UNESCAPED_UNICODE);

                return str_contains($text, 'Bad (Boom)') && str_contains($text, 'Small (fewer than 2 entries)');
            });

        $this->assertTrue($first->hasDraw());
        $this->assertFalse($bad->hasDraw());
        $this->assertTrue($last->hasDraw());
        $this->assertFalse($small->hasDraw());
    }
}
