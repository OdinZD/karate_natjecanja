<?php

namespace Tests\Feature\Public;

use App\Actions\Timetable\TatamiBouts;
use App\Enums\CompetitionStatus;
use App\Models\Bout;
use App\Models\Category;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Competitor;
use App\Models\Entry;
use App\Models\Tatami;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Draw\InteractsWithDraws;
use Tests\TestCase;

class HallScreenTest extends TestCase
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
            'slug' => 'ivanec-open-2026',
            'status' => CompetitionStatus::InProgress,
            'date' => '2026-05-10',
            'start_time' => '09:00',
        ]);
    }

    private function url(): string
    {
        return route('public.competitions.hall', $this->competition);
    }

    /**
     * A drawn category on a new tatami of the competition.
     */
    private function playingTatami(int $number, string $category = 'Kadeti - Kumite', int $entries = 4): Category
    {
        $tatami = Tatami::factory()->for($this->competition)->create(['number' => $number, 'name' => null]);
        $drawn = $this->drawnCategory($entries, competition: $this->competition);
        $drawn->update(['name' => $category.' '.$number, 'tatami_id' => $tatami->id, 'sort_order' => $number]);

        return $drawn;
    }

    public function test_the_page_is_public_and_hidden_for_a_draft_or_an_unknown_competition(): void
    {
        $this->playingTatami(1);

        $this->get($this->url())->assertOk();
        $this->get('/competitions/does-not-exist/hall')->assertNotFound();

        $this->competition->update(['status' => CompetitionStatus::Draft]);
        $this->get($this->url())->assertNotFound();
    }

    public function test_the_page_has_no_site_chrome_and_is_always_dark(): void
    {
        $this->playingTatami(1);

        $html = $this->get($this->url())->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<html[^>]*class="dark"/', $html);
        $this->assertStringNotContainsString('data-test="brand"', $html);
        $this->assertStringNotContainsString('<header class="border-b', $html);
        $this->assertStringNotContainsString('Toggle theme', $html);
        $this->assertStringContainsString('Ivanec Open', $html);
        $this->assertStringContainsString('Hall screen - ', $html);
    }

    public function test_the_page_polls_exactly_once_and_reloads_on_an_expired_session(): void
    {
        $this->playingTatami(1);

        $html = $this->get($this->url())->getContent();

        $this->assertSame(1, substr_count($html, 'wire:poll'));
        $this->assertMatchesRegularExpression('/<div[^>]*wire:poll\.10s\.visible[^>]*data-test="live-region"/', $html);
        $this->assertStringContainsString('interceptRequest', $html);
        $this->assertStringContainsString('419', $html);
        $this->assertStringContainsString('window.location.reload()', $html);
    }

    public function test_a_tatami_shows_the_category_the_current_bout_and_the_next_ones(): void
    {
        $category = $this->playingTatami(1, 'Kadeti - Kumite');

        $html = Livewire::test('pages::public.competitions.hall', ['publicCompetition' => $this->competition])->html();

        $current = Bout::query()->where('category_id', $category->id)->where('status', 'ready')->orderBy('id')->firstOrFail()->load('aka.competitor.club', 'ao.competitor.club');

        $this->assertStringContainsString('Kadeti - Kumite 1', $html);
        $this->assertStringContainsString('data-test="hall-tatami"', $html);
        $this->assertStringContainsString('T1', $html);
        $this->assertStringContainsString($current->aka->competitor->last_name.' '.$current->aka->competitor->first_name, $html);
        $this->assertStringContainsString($current->ao->competitor->club->name, $html);
        $this->assertStringContainsString('AKA', $html);
        $this->assertStringContainsString('AO', $html);

        $upcoming = app(TatamiBouts::class)->handle($category)->upcoming->first(fn (Bout $bout) => $bout->aka !== null);

        $this->assertNotNull($upcoming);
        $this->assertStringContainsString('data-test="hall-next"', $html);
        $this->assertStringContainsString($upcoming->aka->competitor->last_name.' '.$upcoming->aka->competitor->first_name, $html);
        $this->assertLessThanOrEqual(2, substr_count($html, 'hall-next-'));
    }

    public function test_a_tatami_without_a_running_category_says_nothing_is_fought_and_what_comes_next(): void
    {
        $tatami = Tatami::factory()->for($this->competition)->create(['number' => 2, 'name' => null]);
        $category = Category::factory()->for($this->competition)->create(['name' => 'Juniori - Kata', 'tatami_id' => $tatami->id, 'sort_order' => 1]);
        Entry::factory()->count(3)->for($category)->create();

        $html = $this->get($this->url())->assertOk()->getContent();

        $this->assertStringContainsString('data-test="hall-idle"', $html);
        $this->assertStringContainsString('Nothing is being fought here right now.', $html);
        $this->assertStringContainsString('Getting ready', $html);
        $this->assertStringContainsString('Juniori - Kata', $html);
        $this->assertStringContainsString('09:00', $html);
    }

    public function test_an_unpublished_timetable_shows_a_message(): void
    {
        $this->get($this->url())->assertOk()->assertSee('The time table has not been published yet.');
    }

    public function test_the_ticker_shows_the_played_bouts_of_the_competition(): void
    {
        $category = $this->playingTatami(1);
        $this->playAll($category);

        $this->assertGreaterThan(0, Bout::where('status', 'finished')->count());

        $html = $this->get($this->url())->getContent();

        $this->assertStringContainsString('role="marquee"', $html);
        $this->assertStringContainsString('Kadeti - Kumite 1:', $html);
        $this->assertStringContainsString(' beat ', $html);
    }

    public function test_the_ticker_is_in_play_order_with_the_newest_bout_first(): void
    {
        $category = $this->playingTatami(1);
        $club = Club::factory()->create(['name' => 'KK Pobjednik']);
        $older = Bout::query()->where('category_id', $category->id)->orderBy('id')->first();
        $newer = Bout::query()->where('category_id', $category->id)->orderByDesc('id')->first();

        foreach ([[$older, 'Prvi', '2026-05-10 10:00:00'], [$newer, 'Drugi', '2026-05-10 11:00:00']] as [$bout, $last, $at]) {
            $winner = Entry::factory()->for($category)->create(['competitor_id' => Competitor::factory()->for($club)->create(['last_name' => $last, 'first_name' => 'Pobjednik'])->id]);
            $loser = Entry::factory()->for($category)->create(['competitor_id' => Competitor::factory()->create(['last_name' => 'Gubitnik'.$last])->id]);
            $bout->forceFill([
                'aka_entry_id' => $winner->id,
                'ao_entry_id' => $loser->id,
                'winner_entry_id' => $winner->id,
                'status' => 'finished',
                'decision' => 'points',
                'finished_at' => $at,
            ])->save();
        }

        $html = $this->get($this->url())->getContent();

        $this->assertLessThan(strpos($html, 'Prvi Pobjednik'), strpos($html, 'Drugi Pobjednik'));
        $this->assertStringContainsString('Drugi Pobjednik (KK Pobjednik) beat GubitnikDrugi', $html);
    }

    public function test_the_ticker_holds_at_most_ten_results(): void
    {
        $category = $this->drawnCategory(16, competition: $this->competition);
        $tatami = Tatami::factory()->for($this->competition)->create(['number' => 1]);
        $category->update(['tatami_id' => $tatami->id, 'sort_order' => 1]);
        $this->playAll($category);

        $this->assertGreaterThan(10, Bout::where('status', 'finished')->where('decision', '!=', 'bye')->count());

        $ticker = Livewire::test('pages::public.competitions.hall', ['publicCompetition' => $this->competition])->get('results');

        $this->assertCount(10, $ticker);
    }

    public function test_a_finished_bout_without_a_recorded_winner_is_left_out_of_the_ticker(): void
    {
        $category = $this->playingTatami(1);
        $bout = Bout::query()->where('category_id', $category->id)->orderBy('id')->firstOrFail();
        $bout->forceFill(['status' => 'finished', 'decision' => 'points', 'winner_entry_id' => null, 'finished_at' => now()])->save();

        $this->assertSame([], Livewire::test('pages::public.competitions.hall', ['publicCompetition' => $this->competition])->get('results'));
    }

    public function test_there_is_no_ticker_without_results(): void
    {
        $this->playingTatami(1);

        $this->assertStringNotContainsString('role="marquee"', $this->get($this->url())->getContent());
    }

    public function test_no_private_data_is_shown(): void
    {
        $category = $this->playingTatami(1);
        $this->playAll($category);
        Competitor::query()->update(['birth_date' => '2011-03-14', 'gender' => 'female']);
        $operator = User::factory()->create(['name' => 'Operater Tajni', 'email' => 'tajni@example.test']);
        Tatami::query()->first()->operators()->attach($operator->id);

        $html = $this->get($this->url())->getContent();

        foreach (['14.03.2011', '2011-03-14', 'Female', 'Operater Tajni', 'tajni@example.test'] as $private) {
            $this->assertStringNotContainsString($private, $html);
        }
    }

    public function test_the_number_of_queries_is_bounded_per_tatami_not_per_bout(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get($this->url())->assertOk();

            return count(DB::getQueryLog());
        };

        // Drawn but not played, so every tatami has a running category and a current bout to look up.
        $this->playingTatami(1, entries: 4);
        $one = $count();

        foreach ([2, 3, 4] as $number) {
            $this->playingTatami($number, entries: 16);
        }

        $four = $count();

        $this->assertLessThanOrEqual($one + 3 * 8, $four);
    }

    public function test_the_admin_menu_links_to_the_hall_screen_unless_the_competition_is_a_draft(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $html = Livewire::test('pages::admin.competitions.show', ['competition' => $this->competition])->html();
        $this->assertStringContainsString('data-test="open-hall-screen"', $html);
        $this->assertStringContainsString('/hall', $html);

        $this->competition->update(['status' => CompetitionStatus::Draft]);
        $draft = Livewire::test('pages::admin.competitions.show', ['competition' => $this->competition->refresh()])->html();
        $this->assertStringNotContainsString('data-test="open-hall-screen"', $draft);
    }
}
