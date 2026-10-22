<?php

namespace Tests\Feature\Admin;

use App\Actions\Draw\GenerateDraw;
use App\Enums\Discipline;
use App\Enums\Gender;
use App\Models\Bout;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Entry;
use App\Models\Tatami;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class CategoriesTest extends TestCase
{
    use RefreshDatabase;

    private Competition $competition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
        $this->competition = Competition::factory()->create();
    }

    private function page(?Competition $competition = null): Testable
    {
        return Livewire::test('pages::admin.competitions.categories', ['competition' => $competition ?? $this->competition]);
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function fillForm(Testable $page, array $overrides = []): Testable
    {
        $values = [
            'name' => 'Kumite Cadets Boys -52',
            'discipline' => 'kumite',
            'gender' => 'male',
            'birth_year_from' => '2010',
            'birth_year_to' => '2011',
            'weight_min' => '',
            'weight_max' => '52',
            'bout_duration_seconds' => '120',
            ...$overrides,
        ];

        foreach ($values as $field => $value) {
            $page->set($field, $value);
        }

        return $page;
    }

    public function test_admin_can_create_a_category(): void
    {
        $this->fillForm($this->page())->call('save')->assertHasNoErrors();

        $category = $this->competition->categories()->firstOrFail();

        $this->assertSame(Discipline::Kumite, $category->discipline);
        $this->assertSame(Gender::Male, $category->gender);
        $this->assertNull($category->weight_min);
        $this->assertSame('52.00', $category->weight_max);
        $this->assertSame(2010, $category->birth_year_from);
    }

    public function test_an_empty_gender_means_a_mixed_category(): void
    {
        $this->fillForm($this->page(), ['gender' => ''])->call('save')->assertHasNoErrors();

        $this->assertNull($this->competition->categories()->firstOrFail()->gender);
    }

    public function test_name_is_unique_within_a_competition_only(): void
    {
        Category::factory()->for($this->competition)->create(['name' => 'Kata Seniors']);

        $this->fillForm($this->page(), ['name' => 'Kata Seniors'])
            ->call('save')
            ->assertHasErrors(['name' => 'unique']);

        $other = Competition::factory()->create();

        $this->fillForm($this->page($other), ['name' => 'Kata Seniors'])
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_reversed_ranges_are_rejected(): void
    {
        $this->fillForm($this->page(), ['birth_year_from' => '2012', 'birth_year_to' => '2010'])
            ->call('save')
            ->assertHasErrors(['birth_year_to' => 'gte']);

        $this->fillForm($this->page(), ['weight_min' => '60', 'weight_max' => '50'])
            ->call('save')
            ->assertHasErrors('weight_max');

        $this->assertSame(0, Category::count());
    }

    public function test_admin_can_edit_a_category(): void
    {
        $category = Category::factory()->for($this->competition)->create(['name' => 'Old', 'weight_max' => '45']);

        $this->page()
            ->call('edit', $category->id)
            ->assertSet('name', 'Old')
            ->assertSet('weight_max', '45')
            ->set('name', 'New')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('New', $category->refresh()->name);
    }

    public function test_a_category_of_another_competition_cannot_be_touched(): void
    {
        $foreign = Category::factory()->create();

        $this->page()->call('edit', $foreign->id)->assertStatus(404);
        $this->page()->call('confirmDelete', $foreign->id)->assertStatus(404);

        $this->assertModelExists($foreign);
    }

    public function test_deleting_a_category_deletes_its_entries(): void
    {
        $category = Category::factory()->for($this->competition)->create();
        $entry = Entry::factory()->for($category)->create();

        $this->page()->call('confirmDelete', $category->id)->call('delete');

        $this->assertModelMissing($category);
        $this->assertModelMissing($entry);
        $this->assertModelExists($entry->competitor);
    }

    public function test_categories_can_be_copied_from_another_competition(): void
    {
        $source = Competition::factory()->create();
        $tatami = Tatami::factory()->for($source)->create();
        $original = Category::factory()->for($source)->create([
            'name' => 'Kata Juniors',
            'discipline' => Discipline::Kata,
            'gender' => Gender::Female,
            'birth_year_from' => 2008,
            'birth_year_to' => 2009,
            'weight_min' => '40',
            'tatami_id' => $tatami->id,
        ]);
        Entry::factory()->for($original)->create();
        Category::factory()->for($source)->create(['name' => 'Already Here']);
        Category::factory()->for($this->competition)->create(['name' => 'Already Here']);

        $this->page()
            ->call('openCopy')
            ->set('sourceId', (string) $source->id)
            ->call('copy')
            ->assertHasNoErrors();

        $copy = $this->competition->categories()->where('name', 'Kata Juniors')->firstOrFail();

        $this->assertSame(Discipline::Kata, $copy->discipline);
        $this->assertSame(Gender::Female, $copy->gender);
        $this->assertSame(2008, $copy->birth_year_from);
        $this->assertSame('40.00', $copy->weight_min);
        $this->assertNull($copy->tatami_id);
        $this->assertSame(0, $copy->entries()->count());
        $this->assertSame(2, $this->competition->categories()->count());
        $this->assertSame(2, $source->categories()->count());
    }

    public function test_a_competition_cannot_be_copied_onto_itself(): void
    {
        Category::factory()->for($this->competition)->create();

        $this->page()
            ->set('sourceId', (string) $this->competition->id)
            ->call('copy')
            ->assertHasErrors('sourceId');

        $this->assertSame(1, Category::count());
    }

    public function test_source_competitions_without_categories_are_not_offered(): void
    {
        $empty = Competition::factory()->create(['name' => 'Empty Cup']);
        $filled = Competition::factory()->create(['name' => 'Filled Cup']);
        Category::factory()->for($filled)->create();

        $this->page()
            ->call('openCopy')
            ->assertSee('Filled Cup')
            ->assertDontSee('Empty Cup');
    }

    public function test_tampered_ids_of_another_competition_are_rejected_on_save_and_delete(): void
    {
        $foreign = Category::factory()->create(['name' => 'Foreign']);

        $this->fillForm($this->page()->set('editingId', $foreign->id), ['name' => 'Hijacked'])
            ->call('save')
            ->assertStatus(404);

        $this->page()->set('deletingId', $foreign->id)->call('delete')->assertStatus(404);

        $this->assertSame('Foreign', $foreign->refresh()->name);
        $this->assertModelExists($foreign);
    }

    public function test_a_category_with_a_draw_cannot_be_edited_but_can_be_deleted(): void
    {
        $category = Category::factory()->for($this->competition)->create(['name' => 'Drawn']);
        Entry::factory()->count(2)->for($category)->create();
        app(GenerateDraw::class)->handle($category);

        $this->page()
            ->call('edit', $category->id)
            ->set('name', 'Renamed')
            ->call('save')
            ->assertHasErrors('name');

        $this->assertSame('Drawn', $category->refresh()->name);

        $this->page()->call('confirmDelete', $category->id)->call('delete');

        $this->assertModelMissing($category);
        $this->assertSame(0, Bout::count());
    }
}
