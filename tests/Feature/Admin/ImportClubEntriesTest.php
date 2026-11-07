<?php

namespace Tests\Feature\Admin;

use App\Actions\Categories\ImportOfficialCategories;
use App\Actions\Draw\GenerateDraw;
use App\Actions\Entries\ImportClubEntries;
use App\Actions\Entries\ImportEntriesResult;
use App\Enums\CategorySet;
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
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class ImportClubEntriesTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = ['Ime', 'Prezime', 'Spol', 'Datum rođenja', 'Težina', 'Kata', 'Kumite'];

    private Competition $competition;

    private Club $club;

    /** @var array<int, string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        $this->competition = Competition::factory()->create(['date' => '2026-05-10']);
        $this->club = Club::factory()->create();

        app(ImportOfficialCategories::class)->handle($this->competition, CategorySet::Tournament);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function tempFile(string $extension): string
    {
        return $this->files[] = sys_get_temp_dir().'/'.uniqid('club_import_', true).'.'.$extension;
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, string>  $header
     */
    private function xlsx(array $rows, array $header = self::HEADER): string
    {
        $path = $this->tempFile('xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($header));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return $path;
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function import(array $rows, array $header = self::HEADER): ImportEntriesResult
    {
        return app(ImportClubEntries::class)->handle($this->competition, $this->club, $this->xlsx($rows, $header), 'xlsx');
    }

    /**
     * @return array<int, string>
     */
    private function categoriesOf(string $firstName): array
    {
        return Entry::query()
            ->whereHas('competitor', fn ($query) => $query->where('first_name', $firstName))
            ->with('category')
            ->get()
            ->map(fn (Entry $entry) => $entry->category->name)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function children(): array
    {
        return [
            ['Marko', 'Horvat', 'M', '14.03.2014.', '33', 'DA', 'DA'],
            ['Ivan', 'Kovač', 'M', '02.09.2007.', '70', '', 'DA'],
            ['Ana', 'Babić', 'Ž', '21.06.2010.', '', 'DA', ''],
            ['Petar', 'Perić', 'M', '30.01.1986.', '90', '', 'DA'],
            ['Luka', 'Jurić', 'M', '11.11.2011.', '70', '', 'DA'],
            ['Josip', 'Marić', 'M', '12.12.2011.', '75', '', 'DA'],
        ];
    }

    public function test_children_are_entered_in_the_categories_that_fit_them(): void
    {
        $result = $this->import($this->children());

        $this->assertSame([], $result->errors);
        $this->assertSame(7, $result->entriesCreated);
        $this->assertSame(6, $result->competitorsCreated);
        $this->assertSame(0, $result->clubsCreated);

        $this->assertSame(['U14 - Mlađi kadeti - Kata', 'U14 - Mlađi kadeti - Kumite -40 kg'], $this->categoriesOf('Marko'));
        $this->assertSame(['Mlađi seniori - Kumite -75 kg'], $this->categoriesOf('Ivan'));
        $this->assertSame(['Juniorke - Kata'], $this->categoriesOf('Ana'));
        $this->assertSame(['Veterani 35+ - Kumite +85 kg'], $this->categoriesOf('Petar'));
        $this->assertSame(['Kadeti - Kumite -70 kg'], $this->categoriesOf('Luka'));
        $this->assertSame(['Kadeti - Kumite +70 kg'], $this->categoriesOf('Josip'));
    }

    public function test_competitors_belong_to_the_chosen_club_and_only_kumite_entries_store_a_weight(): void
    {
        $this->import($this->children());

        $this->assertSame(6, $this->club->competitors()->count());

        $marko = Competitor::where('first_name', 'Marko')->firstOrFail();
        $this->assertSame(Gender::Male, $marko->gender);
        $this->assertSame('2014-03-14', $marko->birth_date->format('Y-m-d'));

        $kata = $marko->entries()->whereHas('category', fn ($query) => $query->where('name', 'like', '% - Kata'))->firstOrFail();
        $kumite = $marko->entries()->whereHas('category', fn ($query) => $query->where('name', 'like', '%Kumite%'))->firstOrFail();

        $this->assertNull($kata->weight);
        $this->assertSame('33.00', $kumite->weight);
    }

    public function test_a_child_that_fits_no_category_saves_nothing_and_names_the_row(): void
    {
        $result = $this->import([...$this->children(), ['Mali', 'Mališan', 'M', '01.01.2021.', '20', '', 'DA']]);

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('Row 8', $result->errors[0]);
        $this->assertStringContainsString('born 2021', $result->errors[0]);
        $this->assertStringContainsString('Male', $result->errors[0]);
        $this->assertStringContainsString('20 kg', $result->errors[0]);
        $this->assertSame(0, Entry::count());
        $this->assertSame(0, Competitor::count());
    }

    public function test_importing_the_same_file_twice_skips_existing_entries(): void
    {
        $this->import($this->children());
        $second = $this->import($this->children());

        $this->assertSame(0, $second->entriesCreated);
        $this->assertSame(7, $second->entriesSkipped);
        $this->assertSame(7, Entry::count());
        $this->assertSame(6, Competitor::count());
    }

    public function test_an_existing_competitor_of_the_club_is_reused(): void
    {
        Competitor::factory()->for($this->club)->create([
            'first_name' => 'Ana',
            'last_name' => 'Babić',
            'gender' => Gender::Female,
            'birth_date' => '2010-06-21',
        ]);

        $result = $this->import([['Ana', 'Babić', 'Ž', '21.06.2010.', '', 'DA', '']]);

        $this->assertSame(1, $result->entriesCreated);
        $this->assertSame(0, $result->competitorsCreated);
        $this->assertSame(1, Competitor::count());
    }

    public function test_duplicate_rows_are_entered_once(): void
    {
        $row = ['Ana', 'Babić', 'Ž', '21.06.2010.', '', 'DA', ''];

        $result = $this->import([$row, $row]);

        $this->assertSame(1, $result->entriesCreated);
        $this->assertSame(1, $result->entriesSkipped);
    }

    public function test_kata_never_lands_in_a_b_category(): void
    {
        $this->import([['Mia', 'Matić', 'Ž', '05.05.2017.', '', 'DA', '']]);

        $this->assertSame(['U10 - Mlađe učenice - Kata'], $this->categoriesOf('Mia'));
    }

    public function test_a_row_must_mark_kata_or_kumite(): void
    {
        $result = $this->import([['Ana', 'Babić', 'Ž', '21.06.2010.', '', '', '']]);

        $this->assertStringContainsString('Row 2', $result->errors[0]);
        $this->assertStringContainsString('Neither Kata nor Kumite is marked.', $result->errors[0]);
    }

    public function test_kumite_needs_a_weight(): void
    {
        $result = $this->import([['Ivan', 'Kovač', 'M', '02.09.2007.', '', '', 'DA']]);

        $this->assertStringContainsString('Enter the weight for Kumite.', $result->errors[0]);
        $this->assertSame(0, Entry::count());
    }

    public function test_unknown_flag_values_and_bad_cells_are_reported_per_row(): void
    {
        $result = $this->import([
            ['Ana', 'Babić', 'Ž', '21.06.2010.', '', 'Možda', ''],
            ['', 'Babić', 'X', 'jučer', '12abc', 'DA', 'ne'],
        ]);

        $this->assertCount(2, $result->errors);
        $this->assertStringContainsString('Invalid value "Možda" in column Kata', $result->errors[0]);
        $this->assertStringContainsString('The Ime is empty.', $result->errors[1]);
        $this->assertStringContainsString('Unknown gender "X"', $result->errors[1]);
        $this->assertStringContainsString('Invalid date of birth "jučer"', $result->errors[1]);
        $this->assertStringContainsString('Invalid weight "12abc"', $result->errors[1]);
    }

    public function test_yes_variants_and_ne_are_understood(): void
    {
        $result = $this->import([
            ['Ana', 'Babić', 'Ž', '21.06.2010.', '', ' da ', 'NE'],
            ['Eva', 'Ević', 'Ž', '21.06.2010.', '60', 'ne', 'x'],
        ]);

        $this->assertSame([], $result->errors);
        $this->assertSame(['Juniorke - Kata'], $this->categoriesOf('Ana'));
        $this->assertSame(['Juniorke - Kumite -66 kg'], $this->categoriesOf('Eva'));
    }

    public function test_a_gender_that_contradicts_a_stored_competitor_is_reported(): void
    {
        Competitor::factory()->for($this->club)->create([
            'first_name' => 'Ana',
            'last_name' => 'Babić',
            'gender' => Gender::Male,
            'birth_date' => '2010-06-21',
        ]);

        $result = $this->import([['Ana', 'Babić', 'Ž', '21.06.2010.', '', 'DA', '']]);

        $this->assertStringContainsString('differs from the gender', $result->errors[0]);
        $this->assertSame(0, Entry::count());
    }

    public function test_a_category_with_a_draw_is_refused(): void
    {
        $category = Category::where('name', 'Juniorke - Kata')->firstOrFail();
        Entry::factory()->count(2)->for($category)->create();
        app(GenerateDraw::class)->handle($category);

        $result = $this->import([['Ana', 'Babić', 'Ž', '21.06.2010.', '', 'DA', '']]);

        $this->assertStringContainsString('Category "Juniorke - Kata" already has a draw.', $result->errors[0]);
        $this->assertSame(2, Entry::count());
    }

    public function test_a_missing_column_stops_the_import(): void
    {
        $result = $this->import([['Ana', 'Babić', 'Ž', '21.06.2010.', '', 'DA']], ['Ime', 'Prezime', 'Spol', 'Datum rođenja', 'Težina', 'Kata']);

        $this->assertSame(['Missing required column(s): Kumite.'], $result->errors);
    }

    public function test_only_the_first_sheet_is_read_and_empty_rows_are_ignored(): void
    {
        $path = $this->tempFile('xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(self::HEADER));
        $writer->addRow(Row::fromValues(['Ana', 'Babić', 'Ž', '21.06.2010.', '', 'DA', '']));
        $writer->addRow(Row::fromValues(['', '', '', '', '', '', '']));
        $writer->addNewSheetAndMakeItCurrent()->setName('Upute');
        $writer->addRow(Row::fromValues(['Ime', 'Prezime']));
        $writer->addRow(Row::fromValues(['Netko', 'Drugi']));
        $writer->close();

        $result = app(ImportClubEntries::class)->handle($this->competition, $this->club, $path, 'xlsx');

        $this->assertSame([], $result->errors);
        $this->assertSame(1, $result->entriesCreated);
    }

    public function test_a_corrupt_file_is_reported_instead_of_crashing(): void
    {
        $path = $this->tempFile('xlsx');
        file_put_contents($path, 'not a zip');

        $result = app(ImportClubEntries::class)->handle($this->competition, $this->club, $path, 'xlsx');

        $this->assertTrue($result->failed());
    }

    public function test_a_semicolon_csv_works_too(): void
    {
        $path = $this->tempFile('csv');
        file_put_contents($path, "Ime;Prezime;Spol;Datum rođenja;Težina;Kata;Kumite\nAna;Babić;Ž;21.06.2010.;;DA;\n");

        $result = app(ImportClubEntries::class)->handle($this->competition, $this->club, $path, 'csv');

        $this->assertSame([], $result->errors);
        $this->assertSame(['Juniorke - Kata'], $this->categoriesOf('Ana'));
    }

    private function page(): Testable
    {
        $this->actingAs(User::factory()->admin()->create());

        return Livewire::test('pages::admin.competitions.entries', ['competition' => $this->competition]);
    }

    public function test_admin_imports_a_club_file_from_the_entries_page(): void
    {
        $csv = "Ime;Prezime;Spol;Datum rođenja;Težina;Kata;Kumite\nAna;Babić;Ž;21.06.2010.;;DA;\n";

        $this->page()
            ->call('openClubImport')
            ->set('clubId', (string) $this->club->id)
            ->set('clubFile', UploadedFile::fake()->createWithContent('klub.csv', $csv))
            ->call('importClub')
            ->assertHasNoErrors()
            ->assertSet('clubImportErrors', []);

        $this->assertSame(['Juniorke - Kata'], $this->categoriesOf('Ana'));
        $this->assertSame(1, $this->club->competitors()->count());
    }

    public function test_the_entries_page_lists_the_row_errors_and_saves_nothing(): void
    {
        $csv = "Ime;Prezime;Spol;Datum rođenja;Težina;Kata;Kumite\nMali;Mališan;M;01.01.2021.;20;;DA\n";

        $page = $this->page()
            ->set('clubId', (string) $this->club->id)
            ->set('clubFile', UploadedFile::fake()->createWithContent('klub.csv', $csv))
            ->call('importClub');

        $this->assertCount(1, $page->get('clubImportErrors'));
        $this->assertSame(0, Entry::count());
    }

    public function test_the_entries_page_requires_a_club_and_a_file(): void
    {
        $this->page()
            ->call('importClub')
            ->assertHasErrors(['clubId' => 'required', 'clubFile' => 'required']);
    }
}
