<?php

namespace Tests\Feature\Admin;

use App\Actions\Draw\GenerateDraw;
use App\Enums\Gender;
use App\Models\Category;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Competitor;
use App\Models\Entry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class EntriesTest extends TestCase
{
    use RefreshDatabase;

    private Competition $competition;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        $this->actingAs(User::factory()->admin()->create());
        $this->competition = Competition::factory()->create();
        $this->category = Category::factory()->for($this->competition)->create([
            'name' => 'Boys -40',
            'gender' => Gender::Male,
            'birth_year_from' => 2010,
            'birth_year_to' => 2012,
            'weight_max' => '40',
        ]);
    }

    private function page(): Testable
    {
        return Livewire::test('pages::admin.competitions.entries', ['competition' => $this->competition]);
    }

    private function boy(string $birthDate = '2011-05-04'): Competitor
    {
        return Competitor::factory()->create(['gender' => Gender::Male, 'birth_date' => $birthDate]);
    }

    public function test_admin_can_enter_a_matching_competitor(): void
    {
        $competitor = $this->boy();

        $this->page()
            ->set('categoryId', (string) $this->category->id)
            ->set('competitorId', (string) $competitor->id)
            ->set('weight', '38.5')
            ->call('save')
            ->assertHasNoErrors();

        $entry = Entry::firstOrFail();

        $this->assertSame($this->category->id, $entry->category_id);
        $this->assertSame($competitor->id, $entry->competitor_id);
        $this->assertSame('38.50', $entry->weight);
    }

    public function test_weight_is_optional(): void
    {
        $this->page()
            ->set('categoryId', (string) $this->category->id)
            ->set('competitorId', (string) $this->boy()->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(Entry::firstOrFail()->weight);
    }

    public function test_mismatching_entries_are_blocked_with_a_message(): void
    {
        $girl = Competitor::factory()->create(['gender' => Gender::Female, 'birth_date' => '2011-05-04']);

        $this->page()
            ->set('categoryId', (string) $this->category->id)
            ->set('competitorId', (string) $girl->id)
            ->call('save')
            ->assertHasErrors('competitorId')
            ->assertSee('gender does not match');

        $this->page()
            ->set('categoryId', (string) $this->category->id)
            ->set('competitorId', (string) $this->boy('2001-01-01')->id)
            ->call('save')
            ->assertHasErrors('competitorId')
            ->assertSee('Birth year 2001');

        $this->page()
            ->set('categoryId', (string) $this->category->id)
            ->set('competitorId', (string) $this->boy()->id)
            ->set('weight', '41')
            ->call('save')
            ->assertHasErrors('competitorId')
            ->assertSee('above the category maximum');

        $this->assertSame(0, Entry::count());
    }

    public function test_a_competitor_cannot_be_entered_twice_in_the_same_category(): void
    {
        $competitor = $this->boy();
        Entry::factory()->for($this->category)->for($competitor)->create();

        $this->page()
            ->set('categoryId', (string) $this->category->id)
            ->set('competitorId', (string) $competitor->id)
            ->call('save')
            ->assertHasErrors('competitorId');

        $this->assertSame(1, Entry::count());
    }

    public function test_a_category_of_another_competition_is_rejected(): void
    {
        $foreign = Category::factory()->create(['gender' => null, 'birth_year_from' => 2000, 'birth_year_to' => 2020]);

        $this->page()
            ->set('categoryId', (string) $foreign->id)
            ->set('competitorId', (string) $this->boy()->id)
            ->call('save')
            ->assertHasErrors('categoryId');

        $this->assertSame(0, Entry::count());
    }

    public function test_competitor_search_needs_two_letters_and_limits_results(): void
    {
        $club = Club::factory()->create();
        Competitor::factory()->count(30)->for($club)->create(['last_name' => 'Testić']);
        Competitor::factory()->for($club)->create(['last_name' => 'Drugi']);

        $page = $this->page();

        $this->assertCount(0, $page->set('competitorSearch', 'T')->instance()->competitorResults);
        $this->assertCount(25, $page->set('competitorSearch', 'Test')->instance()->competitorResults);
        $this->assertCount(1, $page->set('competitorSearch', 'Drug')->instance()->competitorResults);
    }

    public function test_entries_can_be_filtered_by_category(): void
    {
        $other = Category::factory()->for($this->competition)->create(['name' => 'Girls Kata']);
        $inFirst = Entry::factory()->for($this->category)->create();
        $inOther = Entry::factory()->for($other)->create();
        $inForeign = Entry::factory()->create();

        $page = $this->page();

        $this->assertEqualsCanonicalizing(
            [$inFirst->id, $inOther->id],
            $page->instance()->entries->pluck('id')->all(),
        );

        $this->assertSame(
            [$inOther->id],
            $page->set('categoryFilter', (string) $other->id)->instance()->entries->pluck('id')->all(),
        );
    }

    public function test_admin_can_delete_an_entry_of_this_competition_only(): void
    {
        $entry = Entry::factory()->for($this->category)->create();
        $foreign = Entry::factory()->create();

        $this->page()->call('delete', $entry->id);
        $this->assertModelMissing($entry);

        $this->page()->call('delete', $foreign->id)->assertStatus(404);
        $this->assertModelExists($foreign);
    }

    public function test_admin_can_import_entries_from_an_uploaded_file(): void
    {
        $csv = "Klub;Grad;Ime;Prezime;Spol;Datum rođenja;Kategorija;Težina\nKK Ivanec;Ivanec;Marko;Horvat;M;2011-05-04;Boys -40;38\n";

        $this->page()
            ->set('file', UploadedFile::fake()->createWithContent('prijave.csv', $csv))
            ->call('import')
            ->assertHasNoErrors()
            ->assertSet('importErrors', []);

        $this->assertSame(1, Entry::count());
        $this->assertDatabaseHas('competitors', ['last_name' => 'Horvat']);
    }

    public function test_a_failed_import_lists_the_errors_and_saves_nothing(): void
    {
        $csv = "Klub;Grad;Ime;Prezime;Spol;Datum rođenja;Kategorija\nKK Ivanec;Ivanec;Ana;Kovač;Ž;2011-05-04;Boys -40\n";

        $page = $this->page()
            ->set('file', UploadedFile::fake()->createWithContent('prijave.csv', $csv))
            ->call('import');

        $this->assertCount(1, $page->get('importErrors'));
        $this->assertStringContainsString('Row 2', $page->get('importErrors')[0]);
        $this->assertSame(0, Entry::count());
        $this->assertSame(0, Competitor::count());
    }

    public function test_the_upload_must_be_an_xlsx_or_csv_file(): void
    {
        $this->page()
            ->set('file', UploadedFile::fake()->create('prijave.pdf', 10, 'application/pdf'))
            ->call('import')
            ->assertHasErrors('file');

        $this->page()->call('import')->assertHasErrors(['file' => 'required']);
    }

    private function drawCategory(): void
    {
        Entry::factory()->count(2)->for($this->category)->create();

        app(GenerateDraw::class)->handle($this->category);
    }

    public function test_entries_cannot_be_added_to_a_category_with_a_draw(): void
    {
        $this->drawCategory();
        $competitor = $this->boy();

        $this->page()
            ->set('categoryId', (string) $this->category->id)
            ->set('competitorId', (string) $competitor->id)
            ->call('save')
            ->assertHasErrors('categoryId');

        $this->assertSame(2, Entry::count());
    }

    public function test_entries_cannot_be_deleted_from_a_category_with_a_draw(): void
    {
        $this->drawCategory();
        $entry = Entry::firstOrFail();

        $this->page()->call('delete', $entry->id);

        $this->assertModelExists($entry);
    }
}
