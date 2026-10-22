<?php

namespace Tests\Feature\Admin;

use App\Actions\Draw\GenerateDraw;
use App\Actions\Entries\ImportEntries;
use App\Actions\Entries\ImportEntriesResult;
use App\Enums\Gender;
use App\Models\Category;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Competitor;
use App\Models\Entry;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class ImportEntriesTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = ['Klub', 'Grad', 'Ime', 'Prezime', 'Spol', 'Datum rođenja', 'Kategorija', 'Težina'];

    private Competition $competition;

    private Category $boys;

    private Category $girls;

    /** @var array<int, string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        $this->competition = Competition::factory()->create();
        $this->boys = Category::factory()->kumite()->for($this->competition)->create([
            'name' => 'Boys -40',
            'gender' => Gender::Male,
            'birth_year_from' => 2010,
            'birth_year_to' => 2012,
            'weight_max' => '40',
        ]);
        $this->girls = Category::factory()->kata()->for($this->competition)->create([
            'name' => 'Girls Kata',
            'gender' => Gender::Female,
            'birth_year_from' => 2010,
            'birth_year_to' => 2012,
        ]);
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
        return $this->files[] = sys_get_temp_dir().'/'.uniqid('import_', true).'.'.$extension;
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function xlsx(array $rows, array $header = self::HEADER): string
    {
        $path = $this->tempFile('xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($header));

        foreach ($rows as $row) {
            $writer->addRow(new Row(array_map(
                fn (mixed $value) => $value instanceof Cell ? $value : Cell::fromValue($value),
                $row,
            )));
        }

        $writer->close();

        return $path;
    }

    private function dateCell(string $date): Cell
    {
        return Cell::fromValue(new DateTimeImmutable($date), (new Style)->setFormat('yyyy-mm-dd'));
    }

    private function csv(string $contents, string $encoding = 'UTF-8'): string
    {
        $path = $this->tempFile('csv');
        file_put_contents($path, $encoding === 'UTF-8' ? $contents : (string) iconv('UTF-8', $encoding, $contents));

        return $path;
    }

    private function import(string $path, string $extension): ImportEntriesResult
    {
        return app(ImportEntries::class)->handle($this->competition, $path, $extension);
    }

    public function test_xlsx_with_date_cells_creates_clubs_competitors_and_entries(): void
    {
        $path = $this->xlsx([
            ['KK Ivanec', 'Ivanec', 'Marko', 'Horvat', 'M', new DateTimeImmutable('2011-05-04'), 'Boys -40', '38,5'],
            ['KK Ivanec', 'Ivanec', 'Ana', 'Kovač', 'Ž', $this->dateCell('2011-02-01'), 'Girls Kata', ''],
            ['KK Zagreb', '', 'Iva', 'Babić', 'ženski', '14.03.2012.', 'Girls Kata', ''],
        ]);

        $result = $this->import($path, 'xlsx');

        $this->assertSame([], $result->errors);
        $this->assertSame(3, $result->entriesCreated);
        $this->assertSame(3, $result->competitorsCreated);
        $this->assertSame(2, $result->clubsCreated);
        $this->assertSame(3, Entry::count());

        $marko = Competitor::where('last_name', 'Horvat')->firstOrFail();
        $this->assertSame('2011-05-04', $marko->birth_date->format('Y-m-d'));
        $this->assertSame(Gender::Male, $marko->gender);
        $this->assertSame('38.50', Entry::where('competitor_id', $marko->id)->firstOrFail()->weight);

        $this->assertNull(Club::where('name', 'KK Zagreb')->firstOrFail()->city);
    }

    public function test_csv_with_semicolons_and_with_commas(): void
    {
        $semicolon = $this->csv("Klub;Grad;Ime;Prezime;Spol;Datum rođenja;Kategorija;Težina\nKK Sisak;Sisak;Luka;Maras;M;2011-01-10;Boys -40;35\n");
        $this->assertSame(1, $this->import($semicolon, 'csv')->entriesCreated);

        $comma = $this->csv("Club,City,First name,Last name,Gender,Date of birth,Category\nKK Sisak,Sisak,Mia,Novak,female,2011-03-03,Girls Kata\n");
        $this->assertSame(1, $this->import($comma, 'csv')->entriesCreated);

        $this->assertSame(2, Entry::count());
        $this->assertSame(1, Club::count());
    }

    public function test_windows_1250_csv_keeps_croatian_letters(): void
    {
        $path = $this->csv("Klub;Grad;Ime;Prezime;Spol;Datum rođenja;Kategorija\nKK Šibenik;Šibenik;Đurđica;Čačić;Ž;2011-01-10;Girls Kata\n", 'WINDOWS-1250');

        $result = $this->import($path, 'csv');

        $this->assertSame([], $result->errors);
        $this->assertDatabaseHas('clubs', ['name' => 'KK Šibenik', 'city' => 'Šibenik']);
        $this->assertDatabaseHas('competitors', ['first_name' => 'Đurđica', 'last_name' => 'Čačić']);
    }

    public function test_importing_the_same_file_twice_skips_existing_entries(): void
    {
        $path = $this->xlsx([
            ['KK Ivanec', 'Ivanec', 'Marko', 'Horvat', 'M', '2011-05-04', 'Boys -40', ''],
        ]);

        $this->import($path, 'xlsx');
        $second = $this->import($path, 'xlsx');

        $this->assertSame(0, $second->entriesCreated);
        $this->assertSame(1, $second->entriesSkipped);
        $this->assertSame(0, $second->competitorsCreated);
        $this->assertSame(1, Entry::count());
        $this->assertSame(1, Competitor::count());
    }

    public function test_existing_clubs_and_competitors_are_reused(): void
    {
        $club = Club::factory()->create(['name' => 'KK Ivanec', 'city' => 'Ivanec']);
        $competitor = Competitor::factory()->for($club)->create([
            'first_name' => 'Marko',
            'last_name' => 'Horvat',
            'gender' => Gender::Male,
            'birth_date' => '2011-05-04',
        ]);

        $result = $this->import($this->xlsx([
            ['KK Ivanec', 'Ivanec', 'Marko', 'Horvat', 'M', '2011-05-04', 'Boys -40', ''],
        ]), 'xlsx');

        $this->assertSame(1, $result->entriesCreated);
        $this->assertSame(0, $result->clubsCreated);
        $this->assertSame(0, $result->competitorsCreated);
        $this->assertSame($competitor->id, Entry::firstOrFail()->competitor_id);
    }

    public function test_duplicate_rows_in_the_file_are_entered_once(): void
    {
        $row = ['KK Ivanec', 'Ivanec', 'Marko', 'Horvat', 'M', '2011-05-04', 'Boys -40', ''];

        $result = $this->import($this->xlsx([$row, $row]), 'xlsx');

        $this->assertSame(1, $result->entriesCreated);
        $this->assertSame(1, $result->entriesSkipped);
        $this->assertSame(1, Competitor::count());
    }

    public function test_one_bad_row_saves_nothing_and_reports_the_row_number(): void
    {
        $result = $this->import($this->xlsx([
            ['KK Ivanec', 'Ivanec', 'Marko', 'Horvat', 'M', '2011-05-04', 'Boys -40', ''],
            ['KK Ivanec', 'Ivanec', 'Ana', 'Kovač', 'Ž', '2011-02-01', 'Boys -40', ''],
        ]), 'xlsx');

        $this->assertTrue($result->failed());
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('Row 3', $result->errors[0]);
        $this->assertStringContainsString('gender', $result->errors[0]);
        $this->assertSame(0, Entry::count());
        $this->assertSame(0, Competitor::count());
        $this->assertSame(0, Club::count());
    }

    public function test_weight_and_birth_year_mismatches_are_reported(): void
    {
        $result = $this->import($this->xlsx([
            ['KK Ivanec', 'Ivanec', 'Marko', 'Horvat', 'M', '2011-05-04', 'Boys -40', '45'],
            ['KK Ivanec', 'Ivanec', 'Luka', 'Marić', 'M', '2005-05-04', 'Boys -40', ''],
        ]), 'xlsx');

        $this->assertCount(2, $result->errors);
        $this->assertStringContainsString('Row 2', $result->errors[0]);
        $this->assertStringContainsString('Weight 45 kg', $result->errors[0]);
        $this->assertStringContainsString('Row 3', $result->errors[1]);
        $this->assertStringContainsString('Birth year 2005', $result->errors[1]);
    }

    public function test_unparseable_values_are_reported_per_row(): void
    {
        $result = $this->import($this->xlsx([
            ['KK Ivanec', 'Ivanec', 'Marko', 'Horvat', 'X', '31.02.2011.', 'Nonexistent', 'heavy'],
            ['', 'Ivanec', '', 'Horvat', 'M', '2011-05-04', 'Boys -40', ''],
        ]), 'xlsx');

        $this->assertTrue($result->failed());
        $this->assertStringContainsString('Unknown gender "X"', $result->errors[0]);
        $this->assertStringContainsString('Invalid date of birth "31.02.2011."', $result->errors[0]);
        $this->assertStringContainsString('Unknown category "Nonexistent"', $result->errors[0]);
        $this->assertStringContainsString('Invalid weight "heavy"', $result->errors[0]);
        $this->assertStringContainsString('Row 3', $result->errors[1]);
        $this->assertSame(0, Entry::count());
    }

    public function test_empty_rows_are_ignored_but_still_counted_in_row_numbers(): void
    {
        $path = $this->csv("Klub;Grad;Ime;Prezime;Spol;Datum rođenja;Kategorija\n\nKK Ivanec;Ivanec;Ana;Kovač;Ž;2011-02-01;Boys -40\n");

        $result = $this->import($path, 'csv');

        $this->assertStringContainsString('Row 3', $result->errors[0]);
    }

    public function test_a_missing_required_column_stops_the_import(): void
    {
        $result = $this->import($this->xlsx([], ['Klub', 'Ime', 'Prezime', 'Spol', 'Kategorija']), 'xlsx');

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('Datum rođenja', $result->errors[0]);
    }

    public function test_an_empty_file_is_reported(): void
    {
        $this->assertTrue($this->import($this->csv(''), 'csv')->failed());
    }

    public function test_a_corrupt_xlsx_file_is_reported_instead_of_crashing(): void
    {
        $result = $this->import($this->csv('this is not a zip archive'), 'xlsx');

        $this->assertTrue($result->failed());
        $this->assertSame(0, Entry::count());
    }

    public function test_unpadded_croatian_dates_and_excel_serial_numbers_are_accepted(): void
    {
        $path = $this->csv(
            "Klub;Grad;Ime;Prezime;Spol;Datum rođenja;Kategorija\n"
            ."KK Ivanec;Ivanec;Marko;Horvat;M;4.5.2011.;Boys -40\n"
            ."KK Ivanec;Ivanec;Luka;Marić;M;40667;Boys -40\n"
            ."KK Ivanec;Ivanec;Ivan;Babić;M;1.1.2011;Boys -40\n",
        );

        $result = $this->import($path, 'csv');

        $this->assertSame([], $result->errors);
        $this->assertSame(3, $result->entriesCreated);
        $this->assertSame('2011-05-04', Competitor::where('last_name', 'Horvat')->firstOrFail()->birth_date->format('Y-m-d'));
        $this->assertSame('2011-05-04', Competitor::where('last_name', 'Marić')->firstOrFail()->birth_date->format('Y-m-d'));
        $this->assertSame('2011-01-01', Competitor::where('last_name', 'Babić')->firstOrFail()->birth_date->format('Y-m-d'));
    }

    public function test_slash_dates_are_not_guessed(): void
    {
        $result = $this->import($this->csv("Klub;Ime;Prezime;Spol;Datum rođenja;Kategorija\nKK Ivanec;Marko;Horvat;M;05/04/2011;Boys -40\n"), 'csv');

        $this->assertStringContainsString('Invalid date of birth', $result->errors[0]);
    }

    public function test_category_names_must_match_exactly(): void
    {
        $result = $this->import($this->csv("Klub;Ime;Prezime;Spol;Datum rođenja;Kategorija\nKK Ivanec;Marko;Horvat;M;2011-05-04;boys -40\n"), 'csv');

        $this->assertStringContainsString('Unknown category "boys -40"', $result->errors[0]);
    }

    public function test_weight_must_be_a_plain_number_with_at_most_two_decimals(): void
    {
        foreach (['1e1', '38.555', '-5', '1000'] as $weight) {
            $result = $this->import($this->csv("Klub;Ime;Prezime;Spol;Datum rođenja;Kategorija;Težina\nKK Ivanec;Marko;Horvat;M;2011-05-04;Boys -40;{$weight}\n"), 'csv');

            $this->assertStringContainsString('Invalid weight', $result->errors[0], $weight);
        }

        $this->assertSame(0, Entry::count());
    }

    public function test_a_gender_that_contradicts_a_stored_competitor_is_reported(): void
    {
        $club = Club::factory()->create(['name' => 'KK Ivanec', 'city' => null]);
        Competitor::factory()->for($club)->create([
            'first_name' => 'Marko',
            'last_name' => 'Horvat',
            'gender' => Gender::Male,
            'birth_date' => '2011-05-04',
        ]);

        $result = $this->import($this->csv("Klub;Ime;Prezime;Spol;Datum rođenja;Kategorija\nKK Ivanec;Marko;Horvat;Ž;2011-05-04;Girls Kata\n"), 'csv');

        $this->assertStringContainsString('differs from the gender already recorded', $result->errors[0]);
        $this->assertSame(0, Entry::count());
    }

    public function test_missing_column_messages_use_the_real_header_names(): void
    {
        $result = $this->import($this->xlsx([], ['Klub', 'Ime', 'Prezime', 'Spol', 'Kategorija']), 'xlsx');

        $this->assertStringContainsString('Datum rođenja', $result->errors[0]);
    }

    public function test_rows_for_a_category_with_a_draw_are_refused(): void
    {
        Entry::factory()->count(2)->for($this->girls)->create();
        app(GenerateDraw::class)->handle($this->girls);

        $result = $this->import($this->csv("Klub;Ime;Prezime;Spol;Datum rođenja;Kategorija\nKK Ivanec;Ana;Kovač;Ž;2011-02-01;Girls Kata\nKK Ivanec;Marko;Horvat;M;2011-05-04;Boys -40\n"), 'csv');

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('Row 2', $result->errors[0]);
        $this->assertStringContainsString('already has a draw', $result->errors[0]);
        $this->assertSame(2, Entry::count());
    }
}
