<?php

namespace Tests\Feature\Admin;

use App\Actions\Results\ResultsExport;
use App\Models\Category;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Competitor;
use App\Models\Entry;
use App\Models\Placement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Feature\Draw\InteractsWithDraws;
use Tests\TestCase;

class ResultsExportTest extends TestCase
{
    use InteractsWithDraws;
    use RefreshDatabase;

    private Competition $competition;

    /** @var array<int, string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        $this->competition = Competition::factory()->create(['name' => 'Ivanec Open', 'slug' => 'ivanec-open-2026', 'date' => '2026-05-10', 'venue' => 'SD Ivanec']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function url(): string
    {
        return route('admin.competitions.results.export', $this->competition);
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

    /**
     * @return array<string, array<int, array<int, mixed>>>
     */
    private function export(): array
    {
        return $this->sheets($this->files[] = app(ResultsExport::class)->build($this->competition));
    }

    private function medal(Category $category, int $place, string $last, string $first, Club $club): void
    {
        $competitor = Competitor::factory()->for($club)->create(['last_name' => $last, 'first_name' => $first]);

        Placement::factory()->create([
            'category_id' => $category->id,
            'entry_id' => Entry::factory()->for($category)->create(['competitor_id' => $competitor->id])->id,
            'place' => $place,
        ]);
    }

    public function test_guests_are_sent_to_the_login_page_and_operators_are_forbidden(): void
    {
        $this->get($this->url())->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create());
        $this->get($this->url())->assertForbidden();
    }

    public function test_nothing_to_export_gives_a_not_found(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get($this->url())->assertNotFound();

        $category = $this->drawnCategory(4, competition: $this->competition);
        $this->get($this->url())->assertNotFound();
        $this->assertNotNull($category);
    }

    public function test_an_admin_downloads_the_workbook(): void
    {
        $category = $this->drawnCategory(4, competition: $this->competition);
        $this->playAll($category);
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->get($this->url());

        $response->assertOk();
        $response->assertDownload('rezultati-ivanec-open-2026.xlsx');
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $this->files[] = $response->baseResponse->getFile()->getPathname();

        $this->assertSame(['Rezultati', 'Poredak klubova', 'Info'], array_keys($this->sheets($response->baseResponse->getFile()->getPathname())));
    }

    public function test_the_results_sheet_lists_both_bronze_medallists_as_places_in_whole_numbers(): void
    {
        $category = $this->drawnCategory(8, competition: $this->competition);
        $category->update(['name' => 'Kadeti - Kumite -52 kg']);
        $this->playAll($category);

        $rows = $this->export()['Rezultati'];

        $this->assertSame(['Kategorija', 'Disciplina', 'Mjesto', 'Prezime', 'Ime', 'Klub', 'Stanje'], $rows[0]);
        $this->assertCount(5, $rows);
        $this->assertSame([1, 2, 3, 3], array_column(array_slice($rows, 1), 2));
        $this->assertSame('Kadeti - Kumite -52 kg', $rows[1][0]);
        $this->assertSame('Kumite', $rows[1][1]);
        $this->assertSame('Završeno', $rows[1][6]);
    }

    public function test_an_unfinished_category_is_marked_in_progress(): void
    {
        $category = Category::factory()->for($this->competition)->create();
        $this->medal($category, 1, 'Čolić', 'Ana', Club::factory()->create());

        $this->assertSame('U tijeku', $this->export()['Rezultati'][1][6]);
    }

    public function test_medallists_of_one_place_are_in_croatian_order_and_categories_in_their_order(): void
    {
        $club = Club::factory()->create();
        $second = Category::factory()->for($this->competition)->create(['name' => 'B kategorija', 'sort_order' => 2]);
        $first = Category::factory()->for($this->competition)->create(['name' => 'A kategorija', 'sort_order' => 1]);
        $this->medal($second, 1, 'Zec', 'Zoran', $club);
        $this->medal($first, 3, 'Sabo', 'Sara', $club);
        $this->medal($first, 3, 'Čolić', 'Ana', $club);

        $rows = array_slice($this->export()['Rezultati'], 1);

        $this->assertSame(['A kategorija', 'A kategorija', 'B kategorija'], array_column($rows, 0));
        $this->assertSame(['Čolić', 'Sabo', 'Zec'], array_column($rows, 3));
    }

    public function test_the_club_sheet_has_the_medal_table_with_whole_numbers(): void
    {
        $category = Category::factory()->for($this->competition)->create();
        $sokol = Club::factory()->create(['name' => 'KK Sokol', 'city' => 'Ivanec']);
        $other = Club::factory()->create(['name' => 'KK Drugi', 'city' => null]);
        $this->medal($category, 1, 'Horvat', 'Marko', $sokol);
        $this->medal($category, 2, 'Babić', 'Ana', $sokol);
        $this->medal($category, 3, 'Kovač', 'Ivan', $other);

        $rows = $this->export()['Poredak klubova'];

        $this->assertSame(['Mjesto', 'Klub', 'Grad', 'Zlato', 'Srebro', 'Bronca', 'Ukupno'], $rows[0]);
        $this->assertSame([1, 'KK Sokol', 'Ivanec', 1, 1, 0, 2], $rows[1]);
        $this->assertSame([2, 'KK Drugi', '', 0, 0, 1, 1], $rows[2]);
    }

    public function test_the_info_sheet_says_what_and_when(): void
    {
        $this->medal(Category::factory()->for($this->competition)->create(), 1, 'Horvat', 'Marko', Club::factory()->create());
        Carbon::setTestNow(Carbon::parse('2026-05-10 10:00', 'UTC'));

        $info = array_merge(...$this->export()['Info']);

        $this->assertContains('Ivanec Open', $info);
        $this->assertContains('10.05.2026.', $info);
        $this->assertContains('SD Ivanec', $info);
        // 10:00 UTC is 12:00 in Zagreb in May.
        $this->assertSame('10.05.2026. 12:00', $info[array_search('Generirano', $info, true) + 1]);
    }

    public function test_no_birth_dates_genders_or_weights_are_exported(): void
    {
        $category = Category::factory()->for($this->competition)->create();
        $competitor = Competitor::factory()->for(Club::factory()->create())->create(['birth_date' => '2011-03-14', 'gender' => 'female']);
        $entry = Entry::factory()->for($category)->create(['competitor_id' => $competitor->id, 'weight' => 41.5, 'weighed_weight' => 42.5]);
        Placement::factory()->create(['category_id' => $category->id, 'entry_id' => $entry->id, 'place' => 1]);

        $text = json_encode($this->export());

        foreach (['14.03.2011', '2011-03-14', 'Female', 'female', '41.5', '42.5'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text);
        }
    }

    public function test_the_competition_menu_offers_the_export_only_when_there_are_results(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $without = Livewire::test('pages::admin.competitions.show', ['competition' => $this->competition])->html();
        $this->assertStringNotContainsString('data-test="download-results-button"', $without);

        $this->medal(Category::factory()->for($this->competition)->create(), 1, 'Horvat', 'Marko', Club::factory()->create());

        $with = Livewire::test('pages::admin.competitions.show', ['competition' => $this->competition])->html();
        $this->assertStringContainsString('data-test="download-results-button"', $with);
        $this->assertStringContainsString('results.xlsx', $with);
    }
}
