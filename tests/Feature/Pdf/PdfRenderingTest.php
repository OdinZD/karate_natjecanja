<?php

namespace Tests\Feature\Pdf;

use App\Actions\Draw\GenerateDraw;
use App\Enums\CompetitionStatus;
use App\Models\Category;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Competitor;
use App\Models\Entry;
use App\Models\Tatami;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Draw\InteractsWithDraws;
use Tests\TestCase;

class PdfRenderingTest extends TestCase
{
    use InteractsWithDraws;
    use RefreshDatabase;

    private Competition $competition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
        $this->competition = Competition::factory()->create(['status' => CompetitionStatus::InProgress]);
    }

    private function pageCount(string $pdf): int
    {
        return (int) preg_match_all('/\/Type\s*\/Page\b(?!s)/', $pdf);
    }

    public function test_the_pdf_embeds_dejavu_so_croatian_letters_are_not_lost(): void
    {
        $category = Category::factory()->for($this->competition)->create();
        $club = Club::factory()->create(['name' => 'KK Šibenik']);
        Entry::factory()->for($category)->for(Competitor::factory()->for($club)->create(['last_name' => 'Čačić', 'first_name' => 'Đurđica']))->create(['seed' => 1]);
        Entry::factory()->for($category)->create(['seed' => 2]);
        app(GenerateDraw::class)->handle($category);

        $pdf = $this->get(route('admin.competitions.bracket.pdf', [$this->competition, $category]))->assertOk()->getContent();

        $this->assertStringContainsString('DejaVu', $pdf);
        $this->assertSame(1, $this->pageCount($pdf));
    }

    public function test_the_combined_draw_has_at_least_one_page_per_category(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->drawnCategory(4, competition: $this->competition);
        }

        $pdf = $this->get(route('admin.competitions.draw.pdf', $this->competition))->assertOk()->getContent();

        $this->assertGreaterThanOrEqual(3, $this->pageCount($pdf));
    }

    public function test_a_bracket_of_thirty_two_fits_one_page_and_the_default_memory_limit(): void
    {
        $category = $this->drawnCategory(32, competition: $this->competition);
        $url = route('admin.competitions.bracket.pdf', [$this->competition, $category]);

        $pdf = $this->get($url)->assertOk()->getContent();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(1, $this->pageCount($pdf), 'a bracket of 32 must stay on one page');

        // Once it is played, the repechage and the results follow on a page of their own.
        $this->playAll($category);

        memory_reset_peak_usage();
        $before = memory_get_usage(true);

        $played = $this->get($url)->assertOk()->getContent();

        $this->assertSame(2, $this->pageCount($played));
        $this->assertLessThan(60 * 1024 * 1024, memory_get_peak_usage(true) - $before, 'one PDF of a bracket of 32 must stay well inside the 128M limit');
    }

    public function test_a_bracket_of_sixty_four_is_generated_even_if_it_needs_more_pages(): void
    {
        $category = $this->drawnCategory(64, competition: $this->competition);

        $pdf = $this->get(route('admin.competitions.bracket.pdf', [$this->competition, $category]))->assertOk()->getContent();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThanOrEqual(1, $this->pageCount($pdf));
    }

    public function test_the_time_table_pdf_has_a_page_per_tatami(): void
    {
        foreach ([1, 2] as $number) {
            $tatami = Tatami::factory()->for($this->competition)->create(['number' => $number]);
            $this->drawnCategory(4, competition: $this->competition)->update(['tatami_id' => $tatami->id, 'sort_order' => 1]);
        }

        $pdf = $this->get(route('admin.competitions.timetable.pdf', $this->competition))->assertOk()->getContent();

        $this->assertSame(2, $this->pageCount($pdf));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function sizesThatMustFitOnePage(): array
    {
        return ['2 entries' => [2], '3 entries' => [3], '5 entries' => [5], '8 entries' => [8], '9 entries' => [9], '13 entries' => [13], '16 entries' => [16], '32 entries' => [32]];
    }

    private function categoryWithLongNames(int $entries, bool $capitals = false): Category
    {
        $category = Category::factory()->for($this->competition)->create();
        $club = Club::factory()->create(['name' => $capitals ? 'KARATE KLUB IVANEC-VARAŽDINSKE TOPLICE' : 'Karate klub Ivanec-Varaždinske Toplice']);

        foreach (range(1, $entries) as $seed) {
            $competitor = Competitor::factory()->for($club)->create($capitals
                ? ['last_name' => 'HORVAT-KOVAČEVIĆ', 'first_name' => 'MARIJA-MAGDALENA']
                : ['last_name' => 'Horvat-Kovačević', 'first_name' => 'Marija-Magdalena']);
            Entry::factory()->for($category)->for($competitor)->create(['seed' => $seed]);
        }

        app(GenerateDraw::class)->handle($category);

        return $category;
    }

    #[DataProvider('sizesThatMustFitOnePage')]
    public function test_an_unplayed_bracket_up_to_thirty_two_fits_one_page_even_with_long_names(int $entries): void
    {
        $category = $this->categoryWithLongNames($entries);

        $pdf = $this->get(route('admin.competitions.bracket.pdf', [$this->competition, $category]))->assertOk()->getContent();

        $this->assertSame(1, $this->pageCount($pdf), "a bracket for {$entries} entries must fit one page");
    }

    #[DataProvider('sizesThatMustFitOnePage')]
    public function test_a_played_bracket_up_to_thirty_two_is_the_bracket_page_plus_one_page_of_repechage_and_results(int $entries): void
    {
        $category = $this->categoryWithLongNames($entries);
        $this->playAll($category);

        $pdf = $this->get(route('admin.competitions.bracket.pdf', [$this->competition, $category]))->assertOk()->getContent();

        $this->assertSame(2, $this->pageCount($pdf), "a played bracket for {$entries} entries must be exactly two pages");
    }

    #[DataProvider('sizesThatMustFitOnePage')]
    public function test_names_in_capital_letters_keep_the_page_count_and_are_truncated(int $entries): void
    {
        $category = $this->categoryWithLongNames($entries, capitals: true);
        $this->playAll($category);

        $pdf = $this->get(route('admin.competitions.bracket.pdf', [$this->competition, $category]))->assertOk()->getContent();

        $this->assertSame(2, $this->pageCount($pdf), "capital-letter names for {$entries} entries must stay on two pages");

        $category->load(['bouts.aka.competitor.club', 'bouts.ao.competitor.club', 'placements.entry.competitor.club']);
        $html = view('pdf.bracket', ['competition' => $this->competition, 'category' => $category])->render();

        if ($entries >= 9) {
            $this->assertStringNotContainsString('HORVAT-KOVAČEVIĆ MARIJA-MAGDALENA', explode('page-break-before', $html)[0]);
        }
    }

    public function test_long_names_are_truncated_so_bold_text_stays_inside_its_column(): void
    {
        $category = $this->categoryWithLongNames(16);
        $this->playAll($category);
        $category->load(['bouts.aka.competitor.club', 'bouts.ao.competitor.club', 'placements.entry.competitor.club']);

        $html = view('pdf.bracket', ['competition' => $this->competition, 'category' => $category])->render();

        $this->assertStringContainsString('…', $html);
        $this->assertStringNotContainsString('Horvat-Kovačević Marija-Magdalena', explode('page-break-before', $html)[0]);
    }

    public function test_the_pdf_actions_raise_a_low_memory_limit(): void
    {
        $original = ini_get('memory_limit');
        ini_set('memory_limit', '256M');

        try {
            $category = $this->drawnCategory(4, competition: $this->competition);

            $this->get(route('admin.competitions.bracket.pdf', [$this->competition, $category]))->assertOk();

            $this->assertSame(512 * 1024 * 1024, ini_parse_quantity((string) ini_get('memory_limit')));
        } finally {
            ini_set('memory_limit', (string) $original);
        }
    }
}
