<?php

namespace Tests\Feature\Admin;

use App\Actions\Categories\ImportOfficialCategories;
use App\Actions\Draw\GenerateDraw;
use App\Actions\Entries\ClubImportErrorSheet;
use App\Actions\Entries\ClubImportRow;
use App\Actions\Entries\EntryTemplate;
use App\Actions\Entries\ImportClubEntries;
use App\Enums\CategorySet;
use App\Enums\CompetitionStatus;
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
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class ClubImportPreviewTest extends TestCase
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

        $this->competition = Competition::factory()->create(['name' => 'Ivanec Open', 'slug' => 'ivanec-open-2026', 'date' => '2026-05-10', 'status' => CompetitionStatus::Registration, 'registration_deadline' => null]);
        $this->club = Club::factory()->create(['name' => 'KK Sokol', 'city' => 'Ivanec']);

        app(ImportOfficialCategories::class)->handle($this->competition, CategorySet::Tournament);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, string>  $header
     */
    private function xlsx(array $rows, array $header = self::HEADER): string
    {
        $path = $this->files[] = sys_get_temp_dir().'/'.uniqid('preview_', true).'.xlsx';
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
     * @return array<string, array<int, array<int, mixed>>>
     */
    private function sheets(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);

        $sheets = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            $sheets[$sheet->getName()] = [];

            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }

        $reader->close();

        return $sheets;
    }

    private function importer(): ImportClubEntries
    {
        return app(ImportClubEntries::class);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function mixedRows(): array
    {
        return [
            ['Marko', 'Horvat', 'M', '14.03.2014.', '33', 'DA', 'DA'],
            ['Mali', 'Mališan', 'M', '01.01.2021.', '20', '', 'DA'],
            ['Ana', 'Babić', 'Ž', '21.06.2010.', '', 'DA', ''],
        ];
    }

    public function test_analyze_saves_nothing_and_describes_every_row(): void
    {
        $preview = $this->importer()->analyze($this->competition, $this->club, $this->xlsx($this->mixedRows()), 'xlsx');

        $this->assertSame(0, Entry::count());
        $this->assertSame(0, Competitor::count());

        $this->assertCount(3, $preview->rows);
        $this->assertSame(1, $preview->errorCount());
        $this->assertSame(2, $preview->readyCount());
        $this->assertSame(3, $preview->newEntryCount());

        [$marko, $mali] = $preview->rows;

        $this->assertSame('Marko Horvat', $marko->name);
        $this->assertSame(2014, $marko->birthYear);
        $this->assertSame(ClubImportRow::OK, $marko->status);
        $this->assertSame(['U14 - Mlađi kadeti - Kata', 'U14 - Mlađi kadeti - Kumite -40 kg'], array_column($marko->entries, 'category'));

        $this->assertSame(ClubImportRow::ERROR, $mali->status);
        $this->assertSame('Mali Mališan', $mali->name);
        $this->assertStringContainsString('born 2021', $mali->messages[0]);
    }

    public function test_the_one_step_import_still_refuses_a_file_with_a_bad_row(): void
    {
        $result = $this->importer()->handle($this->competition, $this->club, $this->xlsx($this->mixedRows()), 'xlsx');

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('Row 3', $result->errors[0]);
        $this->assertSame(0, Entry::count());
    }

    public function test_the_valid_mode_enters_only_the_rows_without_problems(): void
    {
        $preview = $this->importer()->analyze($this->competition, $this->club, $this->xlsx($this->mixedRows()), 'xlsx');

        $result = $this->importer()->commit($this->club, $preview, ImportClubEntries::MODE_VALID);

        $this->assertSame([], $result->errors);
        $this->assertSame(3, $result->entriesCreated);
        $this->assertSame(2, $result->competitorsCreated);
        $this->assertSame(['Ana', 'Marko'], Competitor::orderBy('first_name')->pluck('first_name')->all());
        $this->assertSame(0, Competitor::where('first_name', 'Mali')->count());
    }

    public function test_the_all_mode_refuses_a_preview_with_problems(): void
    {
        $preview = $this->importer()->analyze($this->competition, $this->club, $this->xlsx($this->mixedRows()), 'xlsx');

        $result = $this->importer()->commit($this->club, $preview);

        $this->assertTrue($result->failed());
        $this->assertSame(0, Entry::count());
    }

    public function test_a_row_with_a_problem_does_not_take_the_place_of_a_valid_duplicate(): void
    {
        $drawn = Category::where('name', 'Juniorke - Kumite -66 kg')->firstOrFail();
        Entry::factory()->count(2)->for($drawn)->create();
        app(GenerateDraw::class)->handle($drawn);

        $preview = $this->importer()->analyze($this->competition, $this->club, $this->xlsx([
            ['Ana', 'Babić', 'Ž', '21.06.2010.', '60', 'DA', 'DA'],
            ['Ana', 'Babić', 'Ž', '21.06.2010.', '', 'DA', ''],
        ]), 'xlsx');

        $this->assertSame([ClubImportRow::ERROR, ClubImportRow::OK], array_map(fn (ClubImportRow $row) => $row->status, $preview->rows));

        $result = $this->importer()->commit($this->club, $preview, ImportClubEntries::MODE_VALID);

        $this->assertSame(1, $result->entriesCreated);
    }

    public function test_a_row_that_only_repeats_an_entry_is_skipped(): void
    {
        $row = ['Ana', 'Babić', 'Ž', '21.06.2010.', '', 'DA', ''];

        $preview = $this->importer()->analyze($this->competition, $this->club, $this->xlsx([$row, $row]), 'xlsx');

        $this->assertSame([ClubImportRow::OK, ClubImportRow::SKIPPED], array_map(fn (ClubImportRow $row) => $row->status, $preview->rows));
        $this->assertSame(1, $preview->newEntryCount());
        $this->assertSame(1, $preview->existingEntryCount());
    }

    public function test_the_header_may_say_kata_da_and_kumite_da(): void
    {
        $result = $this->importer()->handle(
            $this->competition,
            $this->club,
            $this->xlsx([['Ana', 'Babić', 'Ž', '21.06.2010.', '', 'DA', '']], ['Ime', 'Prezime', 'Spol', 'Datum rođenja', 'Težina', 'Kata DA', 'Kumite DA']),
            'xlsx',
        );

        $this->assertSame([], $result->errors);
        $this->assertSame(1, $result->entriesCreated);
    }

    public function test_the_sheet_named_prijave_is_read_even_when_it_is_not_the_first(): void
    {
        $path = $this->files[] = sys_get_temp_dir().'/'.uniqid('second_', true).'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Upute');
        $writer->addRow(Row::fromValues(['Upute za ispunjavanje']));
        $writer->addNewSheetAndMakeItCurrent()->setName(EntryTemplate::SHEET);
        $writer->addRow(Row::fromValues(self::HEADER));
        $writer->addRow(Row::fromValues(['Ana', 'Babić', 'Ž', '21.06.2010.', '', 'DA', '']));
        $writer->close();

        $result = $this->importer()->handle($this->competition, $this->club, $path, 'xlsx');

        $this->assertSame([], $result->errors);
        $this->assertSame(1, $result->entriesCreated);
    }

    public function test_a_file_with_too_many_rows_is_refused(): void
    {
        $rows = array_fill(0, 1001, ['Ana', 'Babić', 'Ž', '21.06.2010.', '', 'DA', '']);

        $preview = $this->importer()->analyze($this->competition, $this->club, $this->xlsx($rows), 'xlsx');

        $this->assertSame(['The file has more than 1000 rows. Split it into smaller files.'], $preview->fileErrors);
        $this->assertSame(0, Entry::count());
    }

    public function test_the_error_sheet_lists_the_rows_with_problems_and_the_reason(): void
    {
        $preview = $this->importer()->analyze($this->competition, $this->club, $this->xlsx($this->mixedRows()), 'xlsx');

        $path = $this->files[] = app(ClubImportErrorSheet::class)->build($preview);
        $rows = $this->sheets($path)[EntryTemplate::SHEET];

        $this->assertSame([...EntryTemplate::HEADER, 'Greška'], $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame(['Mali', 'Mališan', 'M', '01.01.2021.', '20', '', 'DA'], array_slice($rows[1], 0, 7));
        $this->assertStringContainsString('born 2021', $rows[1][7]);
    }

    private function child(string $firstName, string $lastName, Gender $gender, string $birthDate, ?Club $club = null): Competitor
    {
        return Competitor::factory()->for($club ?? $this->club)->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'gender' => $gender,
            'birth_date' => $birthDate,
        ]);
    }

    public function test_the_club_template_lists_only_the_known_children_of_that_club(): void
    {
        $this->child('Marko', 'Horvat', Gender::Male, '2014-03-14');
        $this->child('Ana', 'Babić', Gender::Female, '2010-06-21');
        $this->child('Tuđe', 'Dijete', Gender::Male, '2014-01-01', Club::factory()->create());

        $path = $this->files[] = app(EntryTemplate::class)->build($this->competition, $this->club);
        $sheets = $this->sheets($path);

        $this->assertSame(['Prijave', 'Upute', 'Klub'], array_keys($sheets));
        $this->assertSame([...EntryTemplate::HEADER, EntryTemplate::LAST_WEIGHT_HEADER], $sheets['Prijave'][0]);
        $this->assertSame(
            [['Ana', 'Babić', 'Ž', '21.06.2010.', '', '', '', ''], ['Marko', 'Horvat', 'M', '14.03.2014.', '', '', '', '']],
            array_slice($sheets['Prijave'], 1),
        );
        $this->assertSame([['club_id', $this->club->id], ['competition_id', $this->competition->id], ['Klub', 'KK Sokol']], $sheets['Klub']);
    }

    public function test_the_club_template_reminds_of_the_last_weight(): void
    {
        $marko = $this->child('Marko', 'Horvat', Gender::Male, '2014-03-14');
        Entry::factory()->create(['competitor_id' => $marko->id, 'weight' => '31.50']);
        Entry::factory()->create(['competitor_id' => $marko->id, 'weight' => '33.00']);

        $path = $this->files[] = app(EntryTemplate::class)->build($this->competition, $this->club);

        $this->assertSame(33, $this->sheets($path)['Prijave'][1][7]);
    }

    public function test_the_untouched_club_template_imports_nothing_without_complaining(): void
    {
        $this->child('Marko', 'Horvat', Gender::Male, '2014-03-14');

        $path = $this->files[] = app(EntryTemplate::class)->build($this->competition, $this->club);
        $preview = $this->importer()->analyze($this->competition, $this->club, $path, 'xlsx');

        $this->assertFalse($preview->failed());
        $this->assertSame([ClubImportRow::SKIPPED], array_map(fn (ClubImportRow $row) => $row->status, $preview->rows));
        $this->assertSame($this->club->id, $preview->meta['club_id']);
        $this->assertSame($this->competition->id, $preview->meta['competition_id']);
    }

    public function test_a_filled_in_club_template_enters_the_known_child_without_a_duplicate(): void
    {
        $marko = $this->child('Marko', 'Horvat', Gender::Male, '2014-03-14');

        $template = app(EntryTemplate::class)->build($this->competition, $this->club);
        $rows = $this->sheets($template)['Prijave'];
        $rows[1][4] = '33';
        $rows[1][5] = 'DA';
        $rows[1][6] = 'DA';

        $path = $this->files[] = sys_get_temp_dir().'/'.uniqid('filled_club_', true).'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(EntryTemplate::SHEET);

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->addNewSheetAndMakeItCurrent()->setName(EntryTemplate::META_SHEET);
        $writer->addRow(Row::fromValues(['club_id', $this->club->id]));
        $writer->close();
        @unlink($template);

        $result = $this->importer()->handle($this->competition, $this->club, $path, 'xlsx');

        $this->assertSame([], $result->errors);
        $this->assertSame(2, $result->entriesCreated);
        $this->assertSame(0, $result->competitorsCreated);
        $this->assertSame(1, Competitor::count());
        $this->assertSame(2, $marko->entries()->count());
    }

    public function test_a_plain_file_still_needs_a_discipline_on_every_row(): void
    {
        $preview = $this->importer()->analyze($this->competition, $this->club, $this->xlsx([['Ana', 'Babić', 'Ž', '21.06.2010.', '', '', '']]), 'xlsx');

        $this->assertSame(ClubImportRow::ERROR, $preview->rows[0]->status);
    }

    public function test_an_admin_downloads_the_club_template_with_the_club_in_the_file_name(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->get(route('admin.competitions.entries.template', [$this->competition, 'club' => $this->club->id]));

        $response->assertOk();
        $response->assertDownload('prijave-ivanec-open-2026-kk-sokol.xlsx');
        $this->files[] = $response->baseResponse->getFile()->getPathname();
    }

    public function test_an_unknown_club_has_no_template(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('admin.competitions.entries.template', [$this->competition, 'club' => 999999]))->assertNotFound();
        $this->get(route('admin.competitions.entries.template', [$this->competition, 'club' => 'abc']))->assertNotFound();
    }

    private function page(): Testable
    {
        $this->actingAs(User::factory()->admin()->create());

        return Livewire::test('pages::admin.competitions.entries', ['competition' => $this->competition]);
    }

    private function upload(string $path): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('klub.xlsx', (string) file_get_contents($path));
    }

    public function test_choosing_a_file_shows_the_preview_before_anything_is_saved(): void
    {
        $page = $this->page()
            ->set('clubId', (string) $this->club->id)
            ->set('clubFile', $this->upload($this->xlsx($this->mixedRows())));

        $page->assertSet('clubImportErrors', []);
        $this->assertSame(['rows' => 3, 'changed' => 0, 'ready' => 2, 'skipped' => 0, 'errors' => 1, 'new' => 3, 'existing' => 0], $page->get('clubPreviewSummary'));
        $this->assertSame(['Mali Mališan', 'Marko Horvat', 'Ana Babić'], array_column($page->get('clubPreview'), 'name'));
        $this->assertSame('error', $page->get('clubPreview')[0]['status']);
        $this->assertSame(0, Entry::count());

        $page->assertSeeHtml('data-test="club-import-preview"')
            ->assertSeeHtml('data-test="run-club-valid-import-button"')
            ->assertSeeHtml('data-test="download-club-errors-button"');
    }

    public function test_choosing_the_club_after_the_file_builds_the_preview(): void
    {
        $page = $this->page()
            ->set('clubFile', $this->upload($this->xlsx($this->mixedRows())));

        $this->assertSame([], $page->get('clubPreview'));

        $page->set('clubId', (string) $this->club->id);

        $this->assertCount(3, $page->get('clubPreview'));
    }

    public function test_a_club_template_selects_its_club_and_a_mismatch_is_flagged(): void
    {
        $other = Club::factory()->create(['name' => 'KK Drugi', 'city' => 'Varaždin']);
        $this->child('Marko', 'Horvat', Gender::Male, '2014-03-14');
        $template = $this->files[] = app(EntryTemplate::class)->build($this->competition, $this->club);

        $this->page()
            ->set('clubFile', $this->upload($template))
            ->assertSet('clubId', (string) $this->club->id)
            ->assertSet('clubFileWarnings', []);

        $this->page()
            ->set('clubId', (string) $other->id)
            ->set('clubFile', $this->upload($template))
            ->assertSet('clubId', (string) $other->id)
            ->assertSet('clubFileWarnings', ['The file was prepared for KK Sokol (Ivanec).']);
    }

    public function test_a_template_for_another_competition_is_flagged(): void
    {
        $template = $this->files[] = app(EntryTemplate::class)->build(Competition::factory()->create(), $this->club);

        $this->page()
            ->set('clubFile', $this->upload($template))
            ->assertSet('clubFileWarnings', ['The file was prepared for a different competition.']);
    }

    public function test_the_page_imports_only_the_valid_rows_when_asked(): void
    {
        $this->page()
            ->set('clubId', (string) $this->club->id)
            ->set('clubFile', $this->upload($this->xlsx($this->mixedRows())))
            ->call('confirmClubImport', ImportClubEntries::MODE_VALID)
            ->assertHasNoErrors()
            ->assertSet('clubImportErrors', [])
            ->assertSet('clubPreview', []);

        $this->assertSame(3, Entry::count());
        $this->assertSame(2, $this->club->competitors()->count());
    }

    public function test_the_page_refuses_the_all_mode_while_rows_have_problems(): void
    {
        $page = $this->page()
            ->set('clubId', (string) $this->club->id)
            ->set('clubFile', $this->upload($this->xlsx($this->mixedRows())))
            ->call('confirmClubImport', ImportClubEntries::MODE_ALL);

        $this->assertCount(1, $page->get('clubImportErrors'));
        $this->assertSame(0, Entry::count());
    }

    public function test_an_unknown_import_mode_is_rejected(): void
    {
        $this->page()
            ->set('clubId', (string) $this->club->id)
            ->set('clubFile', $this->upload($this->xlsx($this->mixedRows())))
            ->call('confirmClubImport', 'everything')
            ->assertStatus(422);

        $this->assertSame(0, Entry::count());
    }

    public function test_a_file_level_problem_is_shown_as_an_error_not_a_preview(): void
    {
        $page = $this->page()
            ->set('clubId', (string) $this->club->id)
            ->set('clubFile', $this->upload($this->xlsx([['Ana', 'Babić', 'Ž', '21.06.2010.', '', 'DA']], ['Ime', 'Prezime', 'Spol', 'Datum rođenja', 'Težina', 'Kata'])));

        $page->assertSet('clubImportErrors', ['Missing required column(s): Kumite.']);
        $page->assertSet('clubPreviewSummary', []);
    }

    public function test_the_page_offers_the_rows_with_problems_as_a_download(): void
    {
        $this->page()
            ->set('clubId', (string) $this->club->id)
            ->set('clubFile', $this->upload($this->xlsx($this->mixedRows())))
            ->call('downloadClubErrors')
            ->assertFileDownloaded('greske-uvoza.xlsx');
    }

    public function test_a_category_with_a_draw_shows_up_as_a_problem_in_the_preview(): void
    {
        $category = Category::where('name', 'Juniorke - Kata')->firstOrFail();
        Entry::factory()->count(2)->for($category)->create();
        app(GenerateDraw::class)->handle($category);

        $preview = $this->importer()->analyze($this->competition, $this->club, $this->xlsx([['Ana', 'Babić', 'Ž', '21.06.2010.', '', 'DA', '']]), 'xlsx');

        $this->assertSame(ClubImportRow::ERROR, $preview->rows[0]->status);
        $this->assertSame('Category "Juniorke - Kata" already has a draw.', $preview->rows[0]->messages[0]);
    }

    public function test_commit_refuses_an_unknown_mode(): void
    {
        $preview = $this->importer()->analyze($this->competition, $this->club, $this->xlsx($this->mixedRows()), 'xlsx');

        $this->expectException(\InvalidArgumentException::class);

        $this->importer()->commit($this->club, $preview, 'everything');
    }

    public function test_a_club_template_row_with_a_weight_but_no_discipline_is_a_problem(): void
    {
        $this->child('Marko', 'Horvat', Gender::Male, '2014-03-14');

        $template = $this->files[] = app(EntryTemplate::class)->build($this->competition, $this->club);
        $rows = $this->sheets($template)['Prijave'];
        $rows[1][4] = '33';

        $path = $this->files[] = sys_get_temp_dir().'/'.uniqid('weight_only_', true).'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(EntryTemplate::SHEET);

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->addNewSheetAndMakeItCurrent()->setName(EntryTemplate::META_SHEET);
        $writer->addRow(Row::fromValues(['club_id', $this->club->id]));
        $writer->close();

        $preview = $this->importer()->analyze($this->competition, $this->club, $path, 'xlsx');

        $this->assertSame(ClubImportRow::ERROR, $preview->rows[0]->status);
        $this->assertStringContainsString('Neither Kata nor Kumite is marked.', $preview->rows[0]->messages[0]);
    }

    public function test_a_club_id_that_is_not_a_whole_number_has_no_template(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('admin.competitions.entries.template', [$this->competition, 'club' => $this->club->id.'abc']))->assertNotFound();
    }
}
