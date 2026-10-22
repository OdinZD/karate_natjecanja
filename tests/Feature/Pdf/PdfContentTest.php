<?php

namespace Tests\Feature\Pdf;

use App\Actions\Draw\GenerateDraw;
use App\Actions\Timetable\TimetableBuilder;
use App\Enums\CompetitionStatus;
use App\Enums\Gender;
use App\Models\Category;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Competitor;
use App\Models\Entry;
use App\Models\Tatami;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Draw\InteractsWithDraws;
use Tests\TestCase;

class PdfContentTest extends TestCase
{
    use InteractsWithDraws;
    use RefreshDatabase;

    private Competition $competition;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        $this->competition = Competition::factory()->create([
            'name' => 'Ivanec Open',
            'date' => '2026-12-05',
            'start_time' => '10:00:00',
            'venue' => 'Dvorana Ivanec',
            'status' => CompetitionStatus::InProgress,
        ]);
    }

    private function bracketHtml(Category $category): string
    {
        $category->load(['bouts.aka.competitor.club', 'bouts.ao.competitor.club', 'placements.entry.competitor.club']);

        return view('pdf.bracket', ['competition' => $this->competition, 'category' => $category])->render();
    }

    public function test_a_finished_bracket_of_eight_lists_everyone_the_rounds_the_repechage_and_the_podium(): void
    {
        $category = $this->drawnCategory(8, competition: $this->competition);
        $category->update(['name' => 'Cadets Boys']);
        $this->playAll($category);

        $html = $this->bracketHtml($category);

        foreach ($category->entries()->with('competitor.club')->get() as $entry) {
            $this->assertStringContainsString(e($entry->competitor->last_name).' '.e($entry->competitor->first_name), $html);
            $this->assertStringContainsString(e($entry->competitor->club->name), $html);
        }

        foreach (['Ivanec Open', '05.12.2026.', 'Dvorana Ivanec', 'Cadets Boys', 'Quarterfinals', 'Semifinals', 'Final', 'Repechage', 'Half 1', 'Half 2', '1st place', '2nd place', '3rd place'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }

        // 7 main bouts and 2 repechage bouts, every one of them with an AKA and an AO row and one winner.
        $this->assertSame(9, substr_count($html, '>AKA<'));
        $this->assertSame(9, substr_count($html, '>AO<'));
        $this->assertSame(9, preg_match_all('/<tr class="(aka|ao) winner">/', $html));
        $this->assertStringContainsString('tr.winner td { font-weight: bold; }', $html);

        // The bold class is on the winner's row and not on the loser's.
        $bout = $category->bouts()->where('bracket', 'main')->where('round', 1)->with(['aka.competitor', 'ao.competitor'])->orderBy('position')->firstOrFail();
        [$winner, $loser] = $bout->winner_entry_id === $bout->aka_entry_id ? [$bout->aka, $bout->ao] : [$bout->ao, $bout->aka];
        $row = fn (string $classes, $entry) => '/<tr class="'.$classes.'">\s*<td class="tag">[^<]*<\/td>\s*<td>\s*'.preg_quote(e($entry->competitor->last_name.' '.$entry->competitor->first_name), '/').'/';

        $this->assertSame(1, preg_match($row('(?:aka|ao) winner', $winner), $html), 'winner row is not bold');
        $this->assertSame(0, preg_match($row('(?:aka|ao) winner', $loser), $html), 'loser row is bold');
        $this->assertSame(1, preg_match($row('(?:aka|ao)', $loser), $html), 'loser row is missing');
    }

    public function test_byes_are_shown_as_such(): void
    {
        $category = $this->drawnCategory(5, competition: $this->competition);

        $html = $this->bracketHtml($category);

        $this->assertSame(3, substr_count($html, '>Bye<'));

        // A bye has no score next to it.
        $this->assertSame(3, preg_match_all('/>Bye<\/span>\s*<\/td>\s*<td class="score"><\/td>/', $html));
    }

    public function test_croatian_letters_are_kept_and_markup_in_names_is_escaped(): void
    {
        $category = Category::factory()->for($this->competition)->create();
        $club = Club::factory()->create(['name' => 'KK Šibenik & <i>Čak</i>']);
        $first = Competitor::factory()->for($club)->create(['last_name' => 'Čačić', 'first_name' => 'Đurđica']);
        $second = Competitor::factory()->create(['last_name' => 'Žuža <b>', 'first_name' => 'Ćiro']);
        Entry::factory()->for($category)->for($first)->create(['seed' => 1]);
        Entry::factory()->for($category)->for($second)->create(['seed' => 2]);
        app(GenerateDraw::class)->handle($category);

        $html = $this->bracketHtml($category);

        $this->assertStringContainsString('Čačić Đurđica', $html);
        $this->assertStringContainsString('Žuža &lt;b&gt; Ćiro', $html);
        $this->assertStringContainsString('KK Šibenik &amp; &lt;i&gt;Čak&lt;/i&gt;', $html);
        $this->assertStringNotContainsString('Žuža <b>', $html);
        $this->assertStringContainsString('<meta charset="utf-8">', $html);
    }

    public function test_no_birth_dates_genders_or_operator_details_are_printed(): void
    {
        $tatami = Tatami::factory()->for($this->competition)->create(['number' => 1]);
        $category = Category::factory()->for($this->competition)->create(['gender' => null, 'tatami_id' => $tatami->id, 'sort_order' => 1]);
        $operator = User::factory()->create(['name' => 'Secret Operator Name', 'email' => 'secret.operator@example.com']);
        $tatami->operators()->attach($operator);

        $tester = Competitor::factory()->for(Club::factory())->create([
            'first_name' => 'Privacy', 'last_name' => 'Tester', 'gender' => Gender::Female, 'birth_date' => '2011-03-14',
        ]);
        Entry::factory()->for($category)->for($tester)->create(['seed' => 1]);
        foreach (range(2, 4) as $seed) {
            Entry::factory()->for($category)->create(['seed' => $seed]);
        }
        app(GenerateDraw::class)->handle($category);
        $this->playAll($category);

        $documents = [
            'bracket' => $this->bracketHtml($category),
            'draw' => view('pdf.draw', ['competition' => $this->competition, 'categories' => collect([$category->load(['bouts.aka.competitor.club', 'bouts.ao.competitor.club', 'placements.entry.competitor.club'])])])->render(),
            'timetable' => view('pdf.timetable', ['competition' => $this->competition, 'blocks' => collect(app(TimetableBuilder::class)->build($this->competition))])->render(),
        ];

        foreach ($documents as $name => $html) {
            foreach (['14.03.2011', '2011-03-14', 'Female', 'Secret Operator Name', 'secret.operator@example.com'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $html, "{$forbidden} printed in {$name}");
            }
        }

        $this->assertStringContainsString('Tester Privacy', $documents['bracket']);
    }

    public function test_the_time_table_prints_times_per_tatami_without_operators(): void
    {
        $first = Tatami::factory()->for($this->competition)->create(['number' => 1, 'name' => 'Main mat']);
        $second = Tatami::factory()->for($this->competition)->create(['number' => 2]);
        $kumite = $this->drawnCategory(8, competition: $this->competition);
        $kumite->update(['name' => 'Kumite Cadets', 'tatami_id' => $first->id, 'sort_order' => 1]);
        $kata = $this->drawnCategory(4, competition: $this->competition);
        $kata->update(['name' => 'Kata Cadets', 'tatami_id' => $second->id, 'sort_order' => 1]);

        $blocks = collect(app(TimetableBuilder::class)->build($this->competition));
        $html = view('pdf.timetable', ['competition' => $this->competition, 'blocks' => $blocks])->render();

        $this->assertStringContainsString('10:00–10:27', $html);
        $this->assertStringContainsString('Tatami 1, Main mat', $html);
        $this->assertStringContainsString('Tatami 2', $html);
        $this->assertStringContainsString('Kumite Cadets', $html);
        $this->assertStringContainsString('Kata Cadets', $html);
        $this->assertStringContainsString('Times are estimates based on the number of entries', $html);
        $this->assertSame(1, substr_count($html, 'class="page-break"'));
    }

    public function test_the_combined_draw_has_a_page_break_between_categories_only(): void
    {
        $categories = collect([4, 4, 4])->map(fn (int $entries) => $this->drawnCategory($entries, competition: $this->competition)
            ->load(['bouts.aka.competitor.club', 'bouts.ao.competitor.club', 'placements.entry.competitor.club']));

        $html = view('pdf.draw', ['competition' => $this->competition, 'categories' => $categories])->render();

        $this->assertSame(2, substr_count($html, 'class="page-break"'));
        $this->assertSame(3, substr_count($html, '<h2>'));
    }
}
