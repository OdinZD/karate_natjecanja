<?php

namespace Tests\Feature\Admin;

use App\Actions\Categories\ImportOfficialCategories;
use App\Actions\Draw\GenerateDraw;
use App\Actions\Entries\ImportClubEntries;
use App\Actions\Entries\ImportEntries;
use App\Actions\Entries\UndoClubImport;
use App\Enums\CategorySet;
use App\Enums\CompetitionStatus;
use App\Enums\Gender;
use App\Models\Category;
use App\Models\Club;
use App\Models\ClubImport;
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

class ClubImportUndoTest extends TestCase
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

        $this->competition = Competition::factory()->create(['date' => '2026-05-10', 'status' => CompetitionStatus::Registration, 'registration_deadline' => null]);
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
     */
    private function xlsx(array $rows): string
    {
        $path = $this->files[] = sys_get_temp_dir().'/'.uniqid('undo_', true).'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(self::HEADER));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return $path;
    }

    private function importer(): ImportClubEntries
    {
        return app(ImportClubEntries::class);
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function importFile(array $rows, ?Club $club = null): ClubImport
    {
        $club ??= $this->club;
        $preview = $this->importer()->analyze($this->competition, $club, $this->xlsx($rows), 'xlsx');
        $log = new ClubImport(['competition_id' => $this->competition->id, 'file_name' => 'klub.xlsx']);

        $this->importer()->commit($club, $preview, ImportClubEntries::MODE_VALID, true, $log);

        return $log->refresh();
    }

    private function marko(): array
    {
        return ['Marko', 'Horvat', 'M', '14.03.2014.', '33', 'DA', 'DA'];
    }

    public function test_an_import_tags_what_it_creates(): void
    {
        $existing = Competitor::factory()->for($this->club)->create(['first_name' => 'Ana', 'last_name' => 'Babić', 'gender' => Gender::Female, 'birth_date' => '2010-06-21']);

        $import = $this->importFile([$this->marko(), ['Ana', 'Babić', 'Ž', '21.06.2010.', '', 'DA', '']]);

        $this->assertSame(3, Entry::where('club_import_id', $import->id)->count());
        $this->assertSame(1, Competitor::where('created_by_import_id', $import->id)->count());
        $this->assertNull($existing->refresh()->created_by_import_id);
    }

    public function test_undoing_an_import_removes_its_entries_and_new_competitors_only(): void
    {
        $existing = Competitor::factory()->for($this->club)->create(['first_name' => 'Ana', 'last_name' => 'Babić', 'gender' => Gender::Female, 'birth_date' => '2010-06-21']);
        $other = $this->importFile([['Luka', 'Jurić', 'M', '11.11.2011.', '70', '', 'DA']], Club::factory()->create());

        $import = $this->importFile([$this->marko(), ['Ana', 'Babić', 'Ž', '21.06.2010.', '', 'DA', '']]);

        $result = app(UndoClubImport::class)->handle($import);

        $this->assertFalse($result->failed());
        $this->assertSame([3, 1], [$result->entriesRemoved, $result->competitorsRemoved]);
        $this->assertSame(1, Entry::count());
        $this->assertSame(1, Entry::where('club_import_id', $other->id)->count());
        $this->assertSame(['Ana', 'Luka'], Competitor::orderBy('first_name')->pluck('first_name')->all());
        $this->assertNotNull($existing->fresh());
        $this->assertNotNull($import->refresh()->undone_at);
    }

    public function test_a_new_competitor_with_other_entries_is_kept(): void
    {
        $import = $this->importFile([$this->marko()]);
        $marko = Competitor::where('first_name', 'Marko')->sole();
        $otherCompetition = Competition::factory()->create();
        Entry::factory()->create(['competitor_id' => $marko->id, 'category_id' => Category::factory()->for($otherCompetition)->create()->id]);

        $result = app(UndoClubImport::class)->handle($import);

        $this->assertSame([2, 0], [$result->entriesRemoved, $result->competitorsRemoved]);
        $this->assertNotNull($marko->fresh());
    }

    public function test_entries_the_import_only_changed_stay(): void
    {
        $this->importFile([['Marko', 'Horvat', 'M', '14.03.2014.', '33', '', 'DA']]);
        $import = $this->importFile([['Marko', 'Horvat', 'M', '14.03.2014.', '44', '', 'DA']]);

        $result = app(UndoClubImport::class)->handle($import);

        $this->assertSame(0, $result->entriesRemoved);
        $this->assertSame('44.00', Entry::sole()->weight);
    }

    public function test_an_import_cannot_be_undone_twice_or_when_refused(): void
    {
        $import = $this->importFile([$this->marko()]);
        app(UndoClubImport::class)->handle($import);

        $this->assertTrue(app(UndoClubImport::class)->handle($import->refresh())->failed());

        $rejected = ClubImport::factory()->create(['competition_id' => $this->competition->id, 'club_id' => $this->club->id, 'status' => ClubImport::REJECTED]);
        $this->assertTrue(app(UndoClubImport::class)->handle($rejected)->failed());
    }

    public function test_an_import_with_a_drawn_category_cannot_be_undone(): void
    {
        $import = $this->importFile([['Marko', 'Horvat', 'M', '14.03.2014.', '33', '', 'DA']]);
        $category = Entry::sole()->category;
        Entry::factory()->for($category)->create();
        app(GenerateDraw::class)->handle($category);

        $result = app(UndoClubImport::class)->handle($import);

        $this->assertTrue($result->failed());
        $this->assertStringContainsString($category->name, $result->error);
        $this->assertSame(2, Entry::count());
        $this->assertNull($import->refresh()->undone_at);
    }

    private function page(): Testable
    {
        $this->actingAs(User::factory()->admin()->create());

        return Livewire::test('pages::admin.competitions.entries', ['competition' => $this->competition]);
    }

    public function test_the_panel_offers_to_undo_the_last_import_and_does_it(): void
    {
        $import = $this->importFile([$this->marko()]);

        $page = $this->page();
        $this->assertSame(2, $page->get('clubOverview')[0]['undoable']);
        $page->assertSeeHtml('data-test="club-overview-undo"');

        $page->call('undoClubImport', $import->id);

        $this->assertSame(0, Entry::count());
        $this->assertSame('waiting', $page->get('clubOverview')[0]['state']);
    }

    public function test_only_the_latest_import_of_a_club_can_be_undone_from_the_page(): void
    {
        $first = $this->importFile([$this->marko()]);
        $this->importFile([['Ana', 'Babić', 'Ž', '21.06.2010.', '', 'DA', '']]);

        $this->page()->call('undoClubImport', $first->id)->assertHasNoErrors();

        $this->assertSame(3, Entry::count());
        $this->assertNull($first->refresh()->undone_at);
    }

    public function test_an_import_of_another_competition_cannot_be_undone_from_the_page(): void
    {
        $foreign = ClubImport::factory()->create();

        $this->page()->call('undoClubImport', $foreign->id)->assertStatus(404);
    }

    public function test_names_are_matched_without_case_diacritics_or_extra_spaces(): void
    {
        $ana = Competitor::factory()->for($this->club)->create(['first_name' => 'Ana', 'last_name' => 'Babić', 'gender' => Gender::Female, 'birth_date' => '2010-06-21']);

        $preview = $this->importer()->analyze($this->competition, $this->club, $this->xlsx([[' ana ', 'BABIC', 'Ž', '21.06.2010.', '', 'DA', '']]), 'xlsx');

        $this->assertTrue($preview->rows[0]->competitor->is($ana));
    }

    public function test_the_same_child_written_two_ways_is_one_competitor(): void
    {
        $this->importFile([
            ['Đurđa', 'Šimić', 'Ž', '21.06.2010.', '', 'DA', ''],
            ['durda', 'simic', 'Ž', '21.06.2010.', '', 'DA', ''],
        ]);

        $this->assertSame(1, Competitor::count());
        $this->assertSame(1, Entry::count());
    }

    public function test_the_same_name_in_another_club_is_flagged_but_not_refused(): void
    {
        $other = Club::factory()->create(['name' => 'KK Drugi']);
        Competitor::factory()->for($other)->create(['first_name' => 'Ana', 'last_name' => 'Babić', 'gender' => Gender::Female, 'birth_date' => '2010-06-21']);

        $preview = $this->importer()->analyze($this->competition, $this->club, $this->xlsx([['ana', 'babic', 'Ž', '21.06.2010.', '', 'DA', '']]), 'xlsx');

        $this->assertFalse($preview->failed());
        $this->assertStringContainsString('already exists in KK Drugi', $preview->rows[0]->messages[0]);
        $this->assertSame('ok', $preview->rows[0]->status);
    }

    public function test_a_child_of_this_club_is_not_flagged(): void
    {
        Competitor::factory()->for($this->club)->create(['first_name' => 'Ana', 'last_name' => 'Babić', 'gender' => Gender::Female, 'birth_date' => '2010-06-21']);

        $preview = $this->importer()->analyze($this->competition, $this->club, $this->xlsx([['Ana', 'Babić', 'Ž', '21.06.2010.', '', 'DA', '']]), 'xlsx');

        $this->assertSame([], $preview->rows[0]->messages);
    }

    public function test_a_closed_competition_and_a_passed_deadline_are_warned_about(): void
    {
        $this->competition->update(['status' => CompetitionStatus::Drawn, 'registration_deadline' => '2026-04-01 12:00:00']);

        $preview = $this->importer()->analyze($this->competition->refresh(), $this->club, $this->xlsx([$this->marko()]), 'xlsx');

        $this->assertSame([
            'The competition is not in the registration phase.',
            'The registration deadline passed on 01.04.2026. 12:00.',
        ], $preview->warnings);
        $this->assertFalse($preview->failed());
    }

    public function test_an_open_competition_has_no_warnings(): void
    {
        $preview = $this->importer()->analyze($this->competition, $this->club, $this->xlsx([$this->marko()]), 'xlsx');

        $this->assertSame([], $preview->warnings);
    }

    public function test_the_page_shows_the_competition_warnings_in_the_preview(): void
    {
        $this->competition->update(['status' => CompetitionStatus::InProgress]);
        $csv = "Ime;Prezime;Spol;Datum rođenja;Težina;Kata;Kumite\nAna;Babić;Ž;21.06.2010.;;DA;\n";

        $this->page()
            ->set('clubId', (string) $this->club->id)
            ->set('clubFile', UploadedFile::fake()->createWithContent('klub.csv', $csv))
            ->assertSet('clubFileWarnings', ['The competition is not in the registration phase.']);
    }

    public function test_the_general_import_enters_a_child_written_two_ways_once(): void
    {
        Competitor::factory()->for($this->club)->create(['first_name' => 'Ana', 'last_name' => 'Babić', 'gender' => Gender::Female, 'birth_date' => '2010-06-21']);
        $category = Category::where('competition_id', $this->competition->id)->where('name', 'Juniorke - Kata')->firstOrFail();

        $path = $this->files[] = sys_get_temp_dir().'/'.uniqid('general_', true).'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Klub', 'Grad', 'Ime', 'Prezime', 'Spol', 'Datum rođenja', 'Kategorija', 'Težina']));
        $writer->addRow(Row::fromValues(['KK Sokol', 'Ivanec', 'Ana', 'Babić', 'Ž', '21.06.2010.', $category->name, '']));
        $writer->addRow(Row::fromValues(['KK Sokol', 'Ivanec', 'ana', 'babic', 'Ž', '21.06.2010.', $category->name, '']));
        $writer->close();

        $result = app(ImportEntries::class)->handle($this->competition, $path, 'xlsx');

        $this->assertSame([], $result->errors);
        $this->assertSame(1, $result->entriesCreated);
        $this->assertSame(1, Competitor::count());
        $this->assertSame(1, Entry::count());
    }

    public function test_the_existing_competitor_with_the_lowest_id_is_picked_among_near_duplicates(): void
    {
        $first = Competitor::factory()->for($this->club)->create(['first_name' => 'Ana', 'last_name' => 'Babić', 'gender' => Gender::Female, 'birth_date' => '2010-06-21']);
        Competitor::factory()->for($this->club)->create(['first_name' => 'ana', 'last_name' => 'babic', 'gender' => Gender::Female, 'birth_date' => '2010-06-21']);

        $preview = $this->importer()->analyze($this->competition, $this->club, $this->xlsx([['Ana', 'Babic', 'Ž', '21.06.2010.', '', 'DA', '']]), 'xlsx');

        $this->assertTrue($preview->rows[0]->competitor->is($first));
    }
}
