<?php

namespace Tests\Feature\Admin;

use App\Actions\Categories\ImportOfficialCategories;
use App\Actions\Draw\GenerateDraw;
use App\Actions\Entries\ImportClubEntries;
use App\Enums\CategorySet;
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

class ClubImportLogTest extends TestCase
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

        $this->competition = Competition::factory()->create(['name' => 'Ivanec Open', 'slug' => 'ivanec-open-2026', 'date' => '2026-05-10']);
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
        $path = $this->files[] = sys_get_temp_dir().'/'.uniqid('log_', true).'.xlsx';
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
    private function importFile(array $rows, string $mode = ImportClubEntries::MODE_ALL, bool $replace = true): ClubImport
    {
        $preview = $this->importer()->analyze($this->competition, $this->club, $this->xlsx($rows), 'xlsx');
        $log = new ClubImport(['competition_id' => $this->competition->id, 'file_name' => 'klub.xlsx']);

        $this->importer()->commit($this->club, $preview, $mode, $replace, $log);

        return $log->refresh();
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

    public function test_a_saved_import_is_logged_and_adds_the_club_to_the_competition(): void
    {
        $user = User::factory()->admin()->create();
        $preview = $this->importer()->analyze($this->competition, $this->club, $this->xlsx([
            ['Marko', 'Horvat', 'M', '14.03.2014.', '33', 'DA', 'DA'],
            ['Ana', 'Babić', 'Ž', '21.06.2010.', '', 'DA', ''],
        ]), 'xlsx');

        $this->importer()->commit($this->club, $preview, log: new ClubImport([
            'competition_id' => $this->competition->id,
            'user_id' => $user->id,
            'file_name' => 'sokol.xlsx',
        ]));

        $log = ClubImport::sole();

        $this->assertSame(ClubImport::IMPORTED, $log->status);
        $this->assertSame('all', $log->mode);
        $this->assertSame('sokol.xlsx', $log->file_name);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($this->club->id, $log->club_id);
        $this->assertSame([2, 2, 0], [$log->rows_total, $log->rows_valid, $log->rows_failed]);
        $this->assertSame([3, 0, 0, 2], [$log->entries_created, $log->entries_replaced, $log->entries_skipped, $log->competitors_created]);
        $this->assertSame(0, $log->error_count);
        $this->assertSame([$this->club->id], $this->competition->clubs()->pluck('clubs.id')->all());
    }

    public function test_a_refused_import_is_logged_with_its_errors(): void
    {
        $log = $this->importFile([
            ['Marko', 'Horvat', 'M', '14.03.2014.', '33', 'DA', 'DA'],
            ['Ivan', 'Kovač', 'M', 'jučer', '70', '', 'DA'],
        ]);

        $this->assertSame(ClubImport::REJECTED, $log->status);
        $this->assertSame([2, 1, 1], [$log->rows_total, $log->rows_valid, $log->rows_failed]);
        $this->assertSame(0, $log->entries_created);
        $this->assertSame(1, $log->error_count);
        $this->assertStringContainsString('Row 3', $log->errors[0]);
        $this->assertSame(0, Entry::count());
        $this->assertSame([$this->club->id], $this->competition->clubs()->pluck('clubs.id')->all());
    }

    public function test_a_file_level_refusal_is_logged_too(): void
    {
        $path = $this->files[] = sys_get_temp_dir().'/'.uniqid('bad_', true).'.xlsx';
        file_put_contents($path, 'not a zip');

        $preview = $this->importer()->analyze($this->competition, $this->club, $path, 'xlsx');
        $this->importer()->commit($this->club, $preview, log: new ClubImport(['competition_id' => $this->competition->id, 'file_name' => 'bad.xlsx']));

        $log = ClubImport::sole();

        $this->assertSame(ClubImport::REJECTED, $log->status);
        $this->assertSame(0, $log->rows_total);
        $this->assertSame(1, $log->error_count);
    }

    public function test_a_partial_import_logs_the_rows_it_left_out(): void
    {
        $log = $this->importFile([
            ['Marko', 'Horvat', 'M', '14.03.2014.', '33', 'DA', 'DA'],
            ['Ivan', 'Kovač', 'M', 'jučer', '70', '', 'DA'],
        ], ImportClubEntries::MODE_VALID);

        $this->assertSame(ClubImport::IMPORTED, $log->status);
        $this->assertSame('valid', $log->mode);
        $this->assertSame([1, 1], [$log->rows_failed, $log->error_count]);
        $this->assertSame(2, $log->entries_created);
    }

    public function test_importing_the_same_file_again_changes_nothing(): void
    {
        $rows = [['Marko', 'Horvat', 'M', '14.03.2014.', '33', 'DA', 'DA']];

        $this->importFile($rows);
        $second = $this->importFile($rows);

        $this->assertSame([0, 0, 2], [$second->entries_created, $second->entries_replaced, $second->entries_skipped]);
        $this->assertSame(2, Entry::count());
        $this->assertSame(2, ClubImport::count());
    }

    public function test_a_new_weight_in_another_class_moves_the_entry(): void
    {
        $this->importFile([['Marko', 'Horvat', 'M', '14.03.2014.', '33', '', 'DA']]);
        $entryId = Entry::sole()->id;
        $before = $this->categoriesOf('Marko');

        $preview = $this->importer()->analyze($this->competition, $this->club, $this->xlsx([['Marko', 'Horvat', 'M', '14.03.2014.', '44', '', 'DA']]), 'xlsx');
        $this->assertSame('replace', $preview->rows[0]->entries[0]['state']);
        $this->assertSame($before[0], $preview->rows[0]->entries[0]['replaces']);
        $this->assertSame(1, $preview->changedEntryCount());

        $log = $this->importFile([['Marko', 'Horvat', 'M', '14.03.2014.', '44', '', 'DA']]);

        $this->assertSame([0, 1, 0], [$log->entries_created, $log->entries_replaced, $log->entries_skipped]);
        $this->assertSame(1, Entry::count());
        $this->assertSame($entryId, Entry::sole()->id);
        $this->assertNotSame($before, $this->categoriesOf('Marko'));
        $this->assertSame('44.00', Entry::sole()->weight);
    }

    public function test_a_new_weight_in_the_same_class_only_updates_the_weight(): void
    {
        $this->importFile([['Marko', 'Horvat', 'M', '14.03.2014.', '33', '', 'DA']]);
        $before = $this->categoriesOf('Marko');

        $preview = $this->importer()->analyze($this->competition, $this->club, $this->xlsx([['Marko', 'Horvat', 'M', '14.03.2014.', '36', '', 'DA']]), 'xlsx');
        $this->assertSame('update', $preview->rows[0]->entries[0]['state']);
        $this->assertSame('33', $preview->rows[0]->entries[0]['previous_weight']);

        $log = $this->importFile([['Marko', 'Horvat', 'M', '14.03.2014.', '36', '', 'DA']]);

        $this->assertSame(1, $log->entries_replaced);
        $this->assertSame($before, $this->categoriesOf('Marko'));
        $this->assertSame('36.00', Entry::sole()->weight);
    }

    public function test_changes_can_be_left_out(): void
    {
        $this->importFile([['Marko', 'Horvat', 'M', '14.03.2014.', '33', '', 'DA']]);
        $before = $this->categoriesOf('Marko');

        $log = $this->importFile([['Marko', 'Horvat', 'M', '14.03.2014.', '44', '', 'DA']], replace: false);

        $this->assertSame([0, 0, 1], [$log->entries_created, $log->entries_replaced, $log->entries_skipped]);
        $this->assertSame($before, $this->categoriesOf('Marko'));
        $this->assertSame('33.00', Entry::sole()->weight);
    }

    public function test_an_entry_in_a_category_with_a_draw_cannot_be_moved(): void
    {
        $this->importFile([['Marko', 'Horvat', 'M', '14.03.2014.', '33', '', 'DA']]);
        $category = Entry::sole()->category;
        Entry::factory()->for($category)->create();
        app(GenerateDraw::class)->handle($category);

        $log = $this->importFile([['Marko', 'Horvat', 'M', '14.03.2014.', '44', '', 'DA']]);

        $this->assertSame(ClubImport::REJECTED, $log->status);
        $this->assertStringContainsString('which has a draw', $log->errors[0]);
        $this->assertSame('33.00', Entry::whereHas('competitor', fn ($query) => $query->where('first_name', 'Marko'))->sole()->weight);
    }

    public function test_an_ambiguous_change_adds_the_entry_next_to_the_others(): void
    {
        $marko = Competitor::factory()->for($this->club)->create(['first_name' => 'Marko', 'last_name' => 'Horvat', 'gender' => 'male', 'birth_date' => '2014-03-14']);
        $kumite = Category::where('competition_id', $this->competition->id)->where('name', 'like', 'U14 - Mlađi kadeti - Kumite%')->orderBy('id')->get();
        Entry::factory()->for($kumite[0])->create(['competitor_id' => $marko->id, 'weight' => 33]);
        Entry::factory()->for($kumite[1])->create(['competitor_id' => $marko->id, 'weight' => 44]);

        $preview = $this->importer()->analyze($this->competition, $this->club, $this->xlsx([['Marko', 'Horvat', 'M', '14.03.2014.', '55', '', 'DA']]), 'xlsx');

        $this->assertSame('new', $preview->rows[0]->entries[0]['state']);
        $this->assertStringContainsString('several Kumite categories', $preview->rows[0]->messages[0]);
        $this->assertFalse($preview->failed());
    }

    public function test_downloading_a_club_template_adds_the_club_to_the_competition(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->get(route('admin.competitions.entries.template', [$this->competition, 'club' => $this->club->id]));
        $this->files[] = $response->baseResponse->getFile()->getPathname();

        $this->assertSame([$this->club->id], $this->competition->clubs()->pluck('clubs.id')->all());

        $this->get(route('admin.competitions.entries.template', [$this->competition, 'club' => $this->club->id]));
        $this->assertSame(1, $this->competition->clubs()->count());
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

    /**
     * @return array<string, string>
     */
    private function states(Testable $page): array
    {
        $states = [];

        foreach ($page->get('clubOverview') as $row) {
            $states[$row['club']->name] = $row['state'];
        }

        return $states;
    }

    public function test_the_panel_tells_which_clubs_have_sent_entries(): void
    {
        $sent = Club::factory()->create(['name' => 'KK Poslan']);
        $broken = Club::factory()->create(['name' => 'KK Greške']);
        $silent = Club::factory()->create(['name' => 'KK Šuti']);
        $unrelated = Club::factory()->create(['name' => 'KK Nitko']);
        $this->competition->clubs()->attach([$sent->id, $broken->id, $silent->id]);

        $rows = [['Marko', 'Horvat', 'M', '14.03.2014.', '33', '', 'DA']];
        $this->importer()->commit($sent, $this->importer()->analyze($this->competition, $sent, $this->xlsx($rows), 'xlsx'), log: new ClubImport(['competition_id' => $this->competition->id, 'file_name' => 'a.xlsx']));
        $this->importer()->commit($broken, $this->importer()->analyze($this->competition, $broken, $this->xlsx([['', '', '', '', '', '', '']]), 'xlsx'), log: new ClubImport(['competition_id' => $this->competition->id, 'file_name' => 'b.xlsx']));
        $badRows = [['Ivan', 'Kovač', 'M', 'jučer', '70', '', 'DA']];
        $this->importer()->commit($broken, $this->importer()->analyze($this->competition, $broken, $this->xlsx($badRows), 'xlsx'), log: new ClubImport(['competition_id' => $this->competition->id, 'file_name' => 'c.xlsx']));

        $page = $this->page();

        $this->assertSame(['KK Greške' => 'problem', 'KK Poslan' => 'sent', 'KK Šuti' => 'waiting'], $this->states($page));
        $this->assertArrayNotHasKey('KK Nitko', $this->states($page));
        $this->assertNotNull($unrelated);

        $page->assertSeeHtml('data-test="club-overview-counter"')->assertSee('1 of 3 clubs sent entries');
    }

    public function test_the_overview_includes_clubs_with_hand_entered_entries(): void
    {
        $category = Category::where('competition_id', $this->competition->id)->firstOrFail();
        $competitor = Competitor::factory()->for($this->club)->create();
        Entry::factory()->for($category)->create(['competitor_id' => $competitor->id]);

        $this->assertSame(['KK Sokol' => 'sent'], $this->states($this->page()));
    }

    public function test_the_page_logs_an_import_with_the_user_and_file_name(): void
    {
        $csv = "Ime;Prezime;Spol;Datum rođenja;Težina;Kata;Kumite\nAna;Babić;Ž;21.06.2010.;;DA;\n";

        $page = $this->page()
            ->call('openClubImport', $this->club->id)
            ->assertSet('clubId', (string) $this->club->id)
            ->set('clubFile', UploadedFile::fake()->createWithContent('sokol.csv', $csv))
            ->call('importClub');

        $log = ClubImport::sole();

        $this->assertSame('sokol.csv', $log->file_name);
        $this->assertSame(auth()->id(), $log->user_id);
        $this->assertSame(ClubImport::IMPORTED, $log->status);
        $this->assertSame(['KK Sokol' => 'sent'], $this->states($page));
    }

    public function test_the_page_leaves_changes_out_when_asked(): void
    {
        $this->importFile([['Marko', 'Horvat', 'M', '14.03.2014.', '33', '', 'DA']]);
        $csv = "Ime;Prezime;Spol;Datum rođenja;Težina;Kata;Kumite\nMarko;Horvat;M;14.03.2014.;44;;DA\n";

        $page = $this->page()
            ->set('clubId', (string) $this->club->id)
            ->set('clubFile', UploadedFile::fake()->createWithContent('klub.csv', $csv));

        $this->assertSame(1, $page->get('clubPreviewSummary')['changed']);

        $page->set('clubReplace', false)->call('importClub');

        $this->assertSame('33.00', Entry::sole()->weight);
        $this->assertSame(0, ClubImport::latest('id')->first()->entries_replaced);
    }

    public function test_a_club_is_added_to_the_competition_from_the_list(): void
    {
        $page = $this->page()
            ->call('openAddClub')
            ->set('addClubId', (string) $this->club->id)
            ->call('addClub')
            ->assertHasNoErrors();

        $this->assertSame(['KK Sokol' => 'waiting'], $this->states($page));
    }

    public function test_a_new_club_can_be_created_and_added(): void
    {
        $page = $this->page()
            ->set('newClubName', 'KK Novi')
            ->set('newClubCity', 'Zadar')
            ->call('addClub')
            ->assertHasNoErrors();

        $this->assertSame(['KK Novi' => 'waiting'], $this->states($page));
        $this->assertSame(1, Club::where('name', 'KK Novi')->where('city', 'Zadar')->count());
    }

    public function test_adding_a_club_needs_a_choice_or_a_name(): void
    {
        $this->page()->call('addClub')->assertHasErrors(['addClubId']);
    }

    public function test_moving_an_entry_drops_its_seed(): void
    {
        $this->importFile([['Marko', 'Horvat', 'M', '14.03.2014.', '33', '', 'DA']]);
        Entry::sole()->update(['seed' => 2]);

        $this->importFile([['Marko', 'Horvat', 'M', '14.03.2014.', '44', '', 'DA']]);

        $this->assertNull(Entry::sole()->seed);
    }

    public function test_a_weight_update_keeps_the_seed(): void
    {
        $this->importFile([['Marko', 'Horvat', 'M', '14.03.2014.', '33', '', 'DA']]);
        Entry::sole()->update(['seed' => 2]);

        $this->importFile([['Marko', 'Horvat', 'M', '14.03.2014.', '36', '', 'DA']]);

        $this->assertSame(2, Entry::sole()->seed);
    }

    public function test_a_kata_row_never_changes_a_stored_weight(): void
    {
        $marko = Competitor::factory()->for($this->club)->create(['first_name' => 'Marko', 'last_name' => 'Horvat', 'gender' => 'male', 'birth_date' => '2014-03-14']);
        $kata = Category::where('competition_id', $this->competition->id)->where('name', 'U14 - Mlađi kadeti - Kata')->firstOrFail();
        Entry::factory()->for($kata)->create(['competitor_id' => $marko->id, 'weight' => 33]);

        $preview = $this->importer()->analyze($this->competition, $this->club, $this->xlsx([['Marko', 'Horvat', 'M', '14.03.2014.', '', 'DA', '']]), 'xlsx');

        $this->assertSame('existing', $preview->rows[0]->entries[0]['state']);
    }

    public function test_the_panel_shows_the_date_of_the_saved_import_and_the_later_problems(): void
    {
        $this->importFile([['Marko', 'Horvat', 'M', '14.03.2014.', '33', '', 'DA']]);
        ClubImport::sole()->forceFill(['created_at' => '2026-05-01 10:00:00'])->save();
        $this->importFile([['Ivan', 'Kovač', 'M', 'jučer', '70', '', 'DA']]);

        $page = $this->page();

        $row = $page->get('clubOverview')[0];
        $this->assertSame('sent', $row['state']);
        $this->assertSame('2026-05-01', $row['lastSaved']->created_at->format('Y-m-d'));
        $page->assertSee('01.05.2026. 10:00')->assertSee('1 problems in the last file');
    }

    public function test_a_club_whose_file_was_saved_without_new_entries_counts_as_sent(): void
    {
        $this->importFile([['Marko', 'Horvat', 'M', '14.03.2014.', '33', '', 'DA']]);
        Entry::query()->delete();

        $row = $this->page()->get('clubOverview')[0];

        $this->assertSame('sent', $row['state']);
        $this->assertSame(0, $row['entries']);
    }
}
