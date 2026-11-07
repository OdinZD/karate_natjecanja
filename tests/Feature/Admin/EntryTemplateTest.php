<?php

namespace Tests\Feature\Admin;

use App\Actions\Categories\ImportOfficialCategories;
use App\Actions\Entries\EntryTemplate;
use App\Actions\Entries\ImportClubEntries;
use App\Enums\CategorySet;
use App\Models\Club;
use App\Models\Competition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class EntryTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Competition $competition;

    /** @var array<int, string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->competition = Competition::factory()->create(['name' => 'Ivanec Open', 'slug' => 'ivanec-open-2026', 'date' => '2026-05-10']);
        app(ImportOfficialCategories::class)->handle($this->competition, CategorySet::Tournament);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function url(): string
    {
        return route('admin.competitions.entries.template', $this->competition);
    }

    /**
     * Sheet name => rows, read back from the XLSX file.
     *
     * @return array<string, array<int, array<int, mixed>>>
     */
    private function sheets(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);

        $sheets = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }

        $reader->close();

        return $sheets;
    }

    public function test_an_admin_downloads_the_template(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->get($this->url());

        $response->assertOk();
        $response->assertDownload('prijave-ivanec-open-2026.xlsx');
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);

        $path = $this->files[] = $response->baseResponse->getFile()->getPathname();
        $sheets = $this->sheets($path);

        $this->assertSame(['Prijave', 'Upute'], array_keys($sheets));
        $this->assertSame([EntryTemplate::HEADER], $sheets['Prijave']);
    }

    public function test_the_template_has_a_header_only_sheet_and_an_instructions_sheet(): void
    {
        $path = $this->files[] = app(EntryTemplate::class)->build($this->competition);

        $sheets = $this->sheets($path);

        $this->assertSame(['Prijave', 'Upute'], array_keys($sheets));
        $this->assertSame([EntryTemplate::HEADER], $sheets['Prijave']);

        $instructions = array_merge(...$sheets['Upute']);
        $this->assertContains('Natjecanje: Ivanec Open', $instructions);
        $this->assertContains('Datum: 10.05.2026.', $instructions);
        $this->assertContains('Kadeti - Kumite -52 kg', $instructions);
        $this->assertContains('2011–2012', $instructions);
    }

    public function test_the_untouched_template_is_accepted_by_the_club_import(): void
    {
        $path = $this->files[] = app(EntryTemplate::class)->build($this->competition);

        $result = app(ImportClubEntries::class)->handle($this->competition, Club::factory()->create(), $path, 'xlsx');

        $this->assertSame([], $result->errors);
        $this->assertSame(0, $result->entriesCreated);
    }

    public function test_a_filled_in_template_round_trips_into_entries(): void
    {
        $path = $this->files[] = sys_get_temp_dir().'/'.uniqid('filled_', true).'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(EntryTemplate::SHEET);
        $writer->addRow(Row::fromValues(EntryTemplate::HEADER));
        $writer->addRow(Row::fromValues(['Marko', 'Horvat', 'M', '14.03.2014.', '33', 'DA', 'DA']));
        $writer->close();

        $club = Club::factory()->create();
        $result = app(ImportClubEntries::class)->handle($this->competition, $club, $path, 'xlsx');

        $this->assertSame([], $result->errors);
        $this->assertSame(2, $result->entriesCreated);
        $this->assertSame(1, $club->competitors()->count());
    }

    public function test_operators_and_guests_cannot_download_it(): void
    {
        $this->get($this->url())->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create());
        $this->get($this->url())->assertForbidden();
    }
}
