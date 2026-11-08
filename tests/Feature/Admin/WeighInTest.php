<?php

namespace Tests\Feature\Admin;

use App\Actions\Categories\ImportOfficialCategories;
use App\Actions\Draw\DrawException;
use App\Actions\Draw\GenerateDraw;
use App\Actions\Entries\ImportClubEntries;
use App\Actions\Entries\RecordWeighIn;
use App\Actions\Entries\SetAttendance;
use App\Actions\Entries\WeighInResult;
use App\Actions\Timetable\TimetableBuilder;
use App\Enums\CategorySet;
use App\Enums\Gender;
use App\Models\Category;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Competitor;
use App\Models\Entry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class WeighInTest extends TestCase
{
    use RefreshDatabase;

    private Competition $competition;

    private Club $club;

    /** @var array<int, string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        $this->competition = Competition::factory()->create(['date' => '2026-05-10']);
        $this->club = Club::factory()->create(['name' => 'KK Sokol']);

        app(ImportOfficialCategories::class)->handle($this->competition, CategorySet::Tournament);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function category(string $name): Category
    {
        return Category::where('competition_id', $this->competition->id)->where('name', $name)->firstOrFail();
    }

    private function child(string $last, string $first, string $birth, Gender $gender = Gender::Male): Competitor
    {
        return Competitor::factory()->for($this->club)->create(['last_name' => $last, 'first_name' => $first, 'gender' => $gender, 'birth_date' => $birth]);
    }

    private function enter(Competitor $competitor, string|Category $category, ?string $weight = null): Entry
    {
        $category = $category instanceof Category ? $category : $this->category($category);

        return Entry::factory()->for($category)->create(['competitor_id' => $competitor->id, 'weight' => $weight]);
    }

    private function marko(?string $declared = '33'): Entry
    {
        return $this->enter($this->child('Horvat', 'Marko', '2014-03-14'), 'U14 - Mlađi kadeti - Kumite -40 kg', $declared);
    }

    private function weigh(Entry $entry, string $weight): WeighInResult
    {
        return app(RecordWeighIn::class)->handle($entry->fresh(), $weight);
    }

    private function draw(Category $category): void
    {
        Entry::factory()->for($category)->create();
        Entry::factory()->for($category)->create();
        app(GenerateDraw::class)->handle($category);
    }

    public function test_a_weight_in_the_same_class_is_only_recorded(): void
    {
        $entry = $this->marko();

        $result = $this->weigh($entry, '38.5');

        $this->assertSame(WeighInResult::FITS, $result->outcome);
        $entry->refresh();
        $this->assertSame('38.50', $entry->weighed_weight);
        $this->assertNotNull($entry->weighed_at);
        $this->assertSame('U14 - Mlađi kadeti - Kumite -40 kg', $entry->category->name);
        $this->assertSame('33.00', $entry->weight);
        $this->assertNull($entry->moved_from_category_id);
    }

    public function test_a_weight_in_another_class_moves_the_entry(): void
    {
        $entry = $this->marko();
        $entry->update(['seed' => 2]);
        $from = $entry->category_id;

        $result = $this->weigh($entry, '44');

        $this->assertSame(WeighInResult::MOVED, $result->outcome);
        $entry->refresh();
        $this->assertSame('U14 - Mlađi kadeti - Kumite -45 kg', $entry->category->name);
        $this->assertSame($from, $entry->moved_from_category_id);
        $this->assertNull($entry->seed);
        $this->assertSame('33.00', $entry->weight);
        $this->assertSame(1, Entry::count());
    }

    public function test_the_boundary_weight_belongs_to_the_lighter_class(): void
    {
        $heavy = $this->enter($this->child('Jurić', 'Luka', '2011-11-11'), 'Kadeti - Kumite +70 kg', '71');

        $this->assertSame(WeighInResult::MOVED, $this->weigh($heavy, '70')->outcome);
        $this->assertSame('Kadeti - Kumite -70 kg', $heavy->fresh()->category->name);

        $this->assertSame(WeighInResult::MOVED, $this->weigh($heavy, '70.01')->outcome);
        $this->assertSame('Kadeti - Kumite +70 kg', $heavy->fresh()->category->name);
        $this->assertNull($heavy->fresh()->moved_from_category_id);
    }

    public function test_a_weight_no_category_takes_leaves_the_entry_where_it_is(): void
    {
        $entry = $this->enter($this->child('Mališan', 'Mali', '2021-01-01'), 'U14 - Mlađi kadeti - Kumite -40 kg', '20');

        $result = $this->weigh($entry, '20');

        $this->assertSame(WeighInResult::NO_CATEGORY, $result->outcome);
        $this->assertSame('U14 - Mlađi kadeti - Kumite -40 kg', $entry->fresh()->category->name);
        $this->assertSame('20.00', $entry->fresh()->weighed_weight);
    }

    public function test_a_competitor_whose_weight_fits_but_birth_year_does_not_is_a_problem_row(): void
    {
        $this->enter($this->child('Mališan', 'Mali', '2021-01-01'), 'U14 - Mlađi kadeti - Kumite -40 kg', '20');
        $entry = Entry::query()->sole();
        $this->weigh($entry, '20');

        $row = $this->page()->set('statusFilter', 'problem')->get('competitors');

        $this->assertSame(['Mališan'], $row->pluck('last_name')->all());
    }

    public function test_a_competitor_of_the_wrong_gender_for_the_category_is_a_problem_row(): void
    {
        $entry = $this->enter($this->child('Pogrešna', 'Pia', '2014-03-14', Gender::Female), 'U14 - Mlađi kadeti - Kumite -40 kg', '33');
        $this->weigh($entry, '33');

        $names = $this->page()->set('statusFilter', 'problem')->get('competitors')->pluck('last_name')->all();

        $this->assertSame(['Pogrešna'], $names);
    }

    public function test_a_conflict_towards_the_lighter_class_is_a_problem_row(): void
    {
        $child = $this->child('Dvostruki', 'Darko', '2014-03-14');
        $this->enter($child, 'U14 - Mlađi kadeti - Kumite -40 kg', '33');
        $heavier = $this->enter($child, 'U14 - Mlađi kadeti - Kumite -45 kg', '44');

        $this->assertSame(WeighInResult::CONFLICT, $this->weigh($heavier, '38')->outcome);

        $page = $this->page();

        $this->assertSame(['Dvostruki'], $page->set('statusFilter', 'problem')->get('competitors')->pluck('last_name')->all());
        $page->assertSee('does not fit this category');
    }

    public function test_a_conflict_exactly_on_the_boundary_is_a_problem_row(): void
    {
        $luka = $this->child('Granični', 'Gordan', '2011-11-11');
        $this->enter($luka, 'Kadeti - Kumite -70 kg', '65');
        $heavy = $this->enter($luka, 'Kadeti - Kumite +70 kg', '71');

        $this->assertSame(WeighInResult::CONFLICT, $this->weigh($heavy, '70')->outcome);

        $this->assertSame(['Granični'], $this->page()->set('statusFilter', 'problem')->get('competitors')->pluck('last_name')->all());
    }

    public function test_a_correctly_placed_weighed_entry_is_not_a_problem(): void
    {
        $this->weigh($this->marko(), '38');

        $this->assertSame([], $this->page()->set('statusFilter', 'problem')->get('competitors')->pluck('last_name')->all());
    }

    public function test_weighing_never_moves_an_entry_to_another_age_group(): void
    {
        $young = $this->enter($this->child('Mladi', 'Marin', '2007-01-01'), 'Seniori - Kumite -67 kg', '66');
        $veteran = $this->enter($this->child('Stari', 'Stipe', '1980-01-01'), 'Seniori - Kumite -67 kg', '66');

        $this->assertSame(WeighInResult::FITS, $this->weigh($young, '65')->outcome);
        $this->assertSame(WeighInResult::FITS, $this->weigh($veteran, '65')->outcome);
        $this->assertSame('Seniori - Kumite -67 kg', $young->fresh()->category->name);
        $this->assertSame('Seniori - Kumite -67 kg', $veteran->fresh()->category->name);

        $this->assertSame(WeighInResult::MOVED, $this->weigh($young, '75')->outcome);
        $this->assertStringStartsWith('Seniori - Kumite', $young->fresh()->category->name);
    }

    public function test_a_competitor_already_entered_in_the_target_class_is_not_moved(): void
    {
        $luka = $this->child('Jurić', 'Luka', '2011-11-11');
        $this->enter($luka, 'Kadeti - Kumite -70 kg', '65');
        $heavy = $this->enter($luka, 'Kadeti - Kumite +70 kg', '71');

        $result = $this->weigh($heavy, '69');

        $this->assertSame(WeighInResult::CONFLICT, $result->outcome);
        $this->assertSame('Kadeti - Kumite +70 kg', $heavy->fresh()->category->name);
        $this->assertSame('69.00', $heavy->fresh()->weighed_weight);
        $this->assertTrue(app(RecordWeighIn::class)->hasProblem($heavy->fresh()->load(['category', 'competitor']), $this->competition->categories()->get()));
    }

    public function test_kata_entries_and_absent_competitors_cannot_be_weighed(): void
    {
        $kata = $this->enter($this->child('Babić', 'Ana', '2010-06-21', Gender::Female), 'Juniorke - Kata');
        $this->assertSame(WeighInResult::REFUSED, $this->weigh($kata, '40')->outcome);

        $entry = $this->marko();
        app(SetAttendance::class)->handle($this->competition, $entry->competitor, true);

        $this->assertSame(WeighInResult::REFUSED, $this->weigh($entry, '33')->outcome);
        $this->assertNull($entry->fresh()->weighed_at);
    }

    public function test_nothing_changes_when_the_source_or_target_category_has_a_draw(): void
    {
        $source = $this->marko();
        $this->draw($source->category);

        $result = $this->weigh($source, '44');

        $this->assertSame(WeighInResult::LOCKED, $result->outcome);
        $this->assertNull($source->fresh()->weighed_at);

        $other = $this->enter($this->child('Perić', 'Pero', '2014-05-05'), 'U14 - Mlađi kadeti - Kumite -50 kg', '48');
        $this->draw($this->category('U14 - Mlađi kadeti - Kumite -45 kg'));

        $moved = $this->weigh($other, '44');

        $this->assertSame(WeighInResult::LOCKED, $moved->outcome);
        $this->assertSame('U14 - Mlađi kadeti - Kumite -50 kg', $other->fresh()->category->name);
        $this->assertNull($other->fresh()->weighed_at);
    }

    public function test_reverting_returns_the_entry_and_clears_the_weigh_in(): void
    {
        $entry = $this->marko();
        $this->weigh($entry, '44');

        $result = app(RecordWeighIn::class)->revert($entry->fresh());

        $this->assertSame(WeighInResult::MOVED, $result->outcome);
        $entry->refresh();
        $this->assertSame('U14 - Mlađi kadeti - Kumite -40 kg', $entry->category->name);
        $this->assertNull($entry->weighed_weight);
        $this->assertNull($entry->weighed_at);
        $this->assertNull($entry->moved_from_category_id);
    }

    public function test_weighing_back_at_the_original_weight_clears_the_move(): void
    {
        $entry = $this->marko();
        $this->weigh($entry, '44');

        $this->weigh($entry, '35');

        $entry->refresh();
        $this->assertSame('U14 - Mlađi kadeti - Kumite -40 kg', $entry->category->name);
        $this->assertNull($entry->moved_from_category_id);
    }

    public function test_an_entry_that_was_not_weighed_cannot_be_reverted(): void
    {
        $this->assertSame(WeighInResult::REFUSED, app(RecordWeighIn::class)->revert($this->marko())->outcome);
    }

    public function test_attendance_follows_all_entries_of_the_competitor_in_this_competition_only(): void
    {
        $ana = $this->child('Babić', 'Ana', '2010-06-21', Gender::Female);
        $kata = $this->enter($ana, 'Juniorke - Kata');
        $kumite = $this->enter($ana, 'Juniorke - Kumite -66 kg', '60');
        $other = Entry::factory()->for(Category::factory()->for(Competition::factory()->create())->create())->create(['competitor_id' => $ana->id]);

        $this->assertNull(app(SetAttendance::class)->handle($this->competition, $ana, true));

        $this->assertNotNull($kata->fresh()->absent_at);
        $this->assertNotNull($kumite->fresh()->absent_at);
        $this->assertNull($other->fresh()->absent_at);

        $this->assertNull(app(SetAttendance::class)->handle($this->competition, $ana, false));
        $this->assertNull($kata->fresh()->absent_at);
    }

    public function test_attendance_cannot_change_once_a_draw_exists(): void
    {
        $entry = $this->marko();
        $this->draw($entry->category);

        $error = app(SetAttendance::class)->handle($this->competition, $entry->competitor, true);

        $this->assertNotNull($error);
        $this->assertNull($entry->fresh()->absent_at);
    }

    public function test_absent_entries_are_left_out_of_the_draw(): void
    {
        $category = Category::factory()->for($this->competition)->create();
        $entries = Entry::factory()->count(3)->for($category)->create();
        $entries[2]->update(['absent_at' => now()]);

        app(GenerateDraw::class)->handle($category);

        $this->assertSame(1, $category->bouts()->count());
        $this->assertSame(0, $category->bouts()->where(fn ($query) => $query->where('aka_entry_id', $entries[2]->id)->orWhere('ao_entry_id', $entries[2]->id))->count());
    }

    public function test_a_category_with_fewer_than_two_present_entries_cannot_be_drawn(): void
    {
        $category = Category::factory()->for($this->competition)->create();
        Entry::factory()->for($category)->create();
        Entry::factory()->for($category)->create(['absent_at' => now()]);

        $this->expectException(DrawException::class);

        app(GenerateDraw::class)->handle($category);
    }

    public function test_the_counts_leave_out_absent_entries(): void
    {
        $category = Category::factory()->for($this->competition)->create();
        Entry::factory()->count(3)->for($category)->create();
        Entry::query()->first()->update(['absent_at' => now()]);

        $counted = app(TimetableBuilder::class)->withProgressCounts($this->competition->categories())->where('id', $category->id)->first();

        $this->assertSame(2, $counted->entries_count);
        $this->assertSame(2, $this->competition->entries()->present()->count());
        $this->assertSame(3, $this->competition->entries()->count());
    }

    public function test_the_public_competitors_page_hides_absent_children_and_the_admin_entries_page_does_not(): void
    {
        $present = $this->marko();
        $absent = $this->enter($this->child('Odsutan', 'Otto', '2014-04-04'), 'U14 - Mlađi kadeti - Kumite -40 kg', '30');
        app(SetAttendance::class)->handle($this->competition, $absent->competitor, true);
        $this->competition->update(['status' => 'registration']);

        $this->get(route('public.competitions.competitors', $this->competition))
            ->assertOk()
            ->assertSee('Horvat')
            ->assertDontSee('Odsutan');

        $this->actingAs(User::factory()->admin()->create());
        Livewire::test('pages::admin.competitions.entries', ['competition' => $this->competition])
            ->assertSee('Odsutan')
            ->assertSee('Horvat');

        $this->assertNotNull($present);
    }

    public function test_a_later_import_does_not_undo_a_weigh_in(): void
    {
        $entry = $this->marko();
        $this->weigh($entry, '44');
        $entry->refresh();
        $this->club->update(['name' => 'KK Sokol']);

        $path = $this->files[] = sys_get_temp_dir().'/'.uniqid('weighed_', true).'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Ime', 'Prezime', 'Spol', 'Datum rođenja', 'Težina', 'Kata', 'Kumite']));
        $writer->addRow(Row::fromValues(['Marko', 'Horvat', 'M', '14.03.2014.', '33', '', 'DA']));
        $writer->close();

        $result = app(ImportClubEntries::class)->handle($this->competition, $this->club, $path, 'xlsx');

        $this->assertSame([], $result->errors);
        $this->assertSame(0, $result->entriesReplaced);
        $this->assertSame(0, $result->entriesCreated);
        $this->assertSame('U14 - Mlađi kadeti - Kumite -45 kg', $entry->fresh()->category->name);
        $this->assertSame('44.00', $entry->fresh()->weighed_weight);
    }

    public function test_a_later_import_does_not_change_the_weight_of_a_weighed_entry_in_the_same_class(): void
    {
        $entry = $this->marko();
        $this->weigh($entry, '38');

        $path = $this->files[] = sys_get_temp_dir().'/'.uniqid('weighed_', true).'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Ime', 'Prezime', 'Spol', 'Datum rođenja', 'Težina', 'Kata', 'Kumite']));
        $writer->addRow(Row::fromValues(['Marko', 'Horvat', 'M', '14.03.2014.', '36', '', 'DA']));
        $writer->close();

        $result = app(ImportClubEntries::class)->handle($this->competition, $this->club, $path, 'xlsx');

        $this->assertSame(0, $result->entriesReplaced);
        $this->assertSame('33.00', $entry->fresh()->weight);
    }

    public function test_the_page_is_for_admins_only(): void
    {
        $url = route('admin.competitions.weigh-in', $this->competition);

        $this->get($url)->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create());
        $this->get($url)->assertForbidden();

        $this->actingAs(User::factory()->admin()->create());
        $this->get($url)->assertOk()->assertSee('Weigh-in');
    }

    public function test_the_steps_list_the_weigh_in_between_entries_and_the_draw(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $html = $this->get(route('admin.competitions.entries', $this->competition))->getContent();

        $this->assertLessThan(strpos($html, 'data-test="draw-link"'), strpos($html, 'data-test="weigh-in-link"'));
        $this->assertLessThan(strpos($html, 'data-test="weigh-in-link"'), strpos($html, 'data-test="entries-link"'));
    }

    private function page(): Testable
    {
        $this->actingAs(User::factory()->admin()->create());

        return Livewire::test('pages::admin.competitions.weigh-in', ['competition' => $this->competition]);
    }

    public function test_the_page_weighs_an_entry_and_accepts_a_comma(): void
    {
        $entry = $this->marko();

        $page = $this->page()
            ->set('weights.'.$entry->id, '44,5')
            ->call('weigh', $entry->id)
            ->assertHasNoErrors();

        $this->assertSame('44.50', $entry->fresh()->weighed_weight);
        $this->assertSame('U14 - Mlađi kadeti - Kumite -45 kg', $entry->fresh()->category->name);
        $this->assertSame(1, $page->get('summary')['weighed']);
        $this->assertSame(1, $page->get('summary')['moved']);
    }

    public function test_the_page_rejects_a_weight_that_is_not_a_number(): void
    {
        $entry = $this->marko();

        $this->page()
            ->set('weights.'.$entry->id, 'abc')
            ->call('weigh', $entry->id)
            ->assertHasErrors(['weights.'.$entry->id]);

        $this->assertNull($entry->fresh()->weighed_at);
    }

    public function test_an_entry_of_another_competition_cannot_be_weighed_from_the_page(): void
    {
        $foreign = Entry::factory()->for(Category::factory()->kumite()->for(Competition::factory()->create())->create())->create();

        $this->page()->set('weights.'.$foreign->id, '40')->call('weigh', $foreign->id)->assertStatus(404);
    }

    public function test_the_page_marks_a_competitor_absent_and_present(): void
    {
        $entry = $this->marko();

        $page = $this->page()->call('setAbsent', $entry->competitor_id, true);

        $this->assertNotNull($entry->fresh()->absent_at);
        $this->assertSame(1, $page->get('summary')['absent']);
        $page->assertSeeHtml('data-test="weigh-in-present"');

        $page->call('setAbsent', $entry->competitor_id, false);
        $this->assertNull($entry->fresh()->absent_at);
    }

    public function test_the_page_reverts_a_weigh_in(): void
    {
        $entry = $this->marko();
        $this->weigh($entry, '44');

        $this->page()->call('revert', $entry->id);

        $this->assertNull($entry->fresh()->weighed_at);
        $this->assertSame('U14 - Mlađi kadeti - Kumite -40 kg', $entry->fresh()->category->name);
    }

    public function test_the_status_filter_and_search_narrow_the_list(): void
    {
        $waiting = $this->marko();
        $weighed = $this->enter($this->child('Jurić', 'Luka', '2011-11-11'), 'Kadeti - Kumite -70 kg', '65');
        $this->weigh($weighed, '66');
        $absent = $this->enter($this->child('Odsutan', 'Otto', '2014-04-04'), 'U14 - Mlađi kadeti - Kumite -40 kg', '30');
        app(SetAttendance::class)->handle($this->competition, $absent->competitor, true);
        $broken = $this->enter($this->child('Kriv', 'Kristijan', '2011-02-02'), 'Kadeti - Kumite +70 kg', '71');
        $light = $this->enter($broken->competitor, 'Kadeti - Kumite -70 kg', '65');
        $this->weigh($light, '66');
        $this->weigh($broken, '69');

        $names = fn (string $status, string $search = '') => $this->page()
            ->set('statusFilter', $status)
            ->set('search', $search)
            ->get('competitors')
            ->pluck('last_name')
            ->all();

        $this->assertSame(['Horvat'], $names('waiting'));
        $this->assertSame(['Jurić'], $names('weighed'));
        $this->assertSame(['Odsutan'], $names('absent'));
        $this->assertSame(['Kriv'], $names('problem'));
        $this->assertSame(['Horvat', 'Jurić', 'Kriv', 'Odsutan'], $names(''));
        $this->assertSame(['Jurić'], $names('', 'luka'));
        $this->assertNotNull($waiting);
    }

    public function test_the_moved_filter_lists_moved_competitors(): void
    {
        $entry = $this->marko();
        $this->weigh($entry, '44');
        $this->enter($this->child('Mirni', 'Marin', '2014-06-06'), 'U14 - Mlađi kadeti - Kumite -40 kg', '30');

        $names = $this->page()->set('statusFilter', 'moved')->get('competitors')->pluck('last_name')->all();

        $this->assertSame(['Horvat'], $names);
    }

    public function test_a_row_in_a_category_with_a_draw_is_locked(): void
    {
        $entry = $this->marko();
        $this->draw($entry->category);

        $page = $this->page();

        $page->assertSee('A draw already exists for this category.')
            ->assertDontSeeHtml('data-test="weigh-in-input"')
            ->assertDontSeeHtml('data-test="weigh-in-absent"');
    }

    public function test_the_admin_entries_page_labels_absent_entries(): void
    {
        $entry = $this->marko();
        $this->enter($this->child('Prisutan', 'Pero', '2014-07-07'), 'U14 - Mlađi kadeti - Kumite -40 kg', '30');
        app(SetAttendance::class)->handle($this->competition, $entry->competitor, true);

        $this->actingAs(User::factory()->admin()->create());

        $html = Livewire::test('pages::admin.competitions.entries', ['competition' => $this->competition])->html();

        $this->assertSame(1, substr_count($html, 'data-test="entry-absent"'));
        $this->assertStringContainsString('Absent', $html);
    }

    public function test_the_draw_page_counts_and_seeds_only_present_entries(): void
    {
        $category = Category::factory()->kumite()->for($this->competition)->create();
        $entries = Entry::factory()->count(3)->for($category)->create();
        $entries[2]->update(['absent_at' => now()]);

        $this->actingAs(User::factory()->admin()->create());
        $page = Livewire::test('pages::admin.competitions.draw', ['competition' => $this->competition]);

        $this->assertSame(2, $page->get('categories')->firstWhere('id', $category->id)->entries_count);

        $page->call('openSeeds', $category->id);
        $this->assertSame([$entries[0]->id, $entries[1]->id], array_map('intval', array_keys($page->get('seeds'))));

        $page->set('seeds.'.$entries[0]->id, '3')->call('saveSeeds')->assertHasErrors();
    }

    public function test_the_public_counts_leave_out_absent_entries(): void
    {
        $this->competition->update(['status' => 'registration', 'name' => 'Ivanec Open']);
        $category = Category::factory()->kumite()->for($this->competition)->create();
        $entries = Entry::factory()->count(3)->for($category)->create();
        $entries[2]->update(['absent_at' => now()]);

        $show = $this->get(route('public.competitions.show', $this->competition))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-stat="entries"[^>]*>\s*2\s*</', $show);
        $this->assertMatchesRegularExpression('/data-stat="competitors"[^>]*>\s*2\s*</', $show);

        $this->get(route('home'))->assertOk()->assertSee('2 entries')->assertDontSee('3 entries');
    }

    public function test_the_public_club_filter_drops_a_club_whose_children_are_all_absent(): void
    {
        $this->competition->update(['status' => 'registration']);
        $entry = $this->marko();
        app(SetAttendance::class)->handle($this->competition, $entry->competitor, true);

        $this->get(route('public.competitions.competitors', $this->competition))
            ->assertOk()
            ->assertDontSee('KK Sokol');

        app(SetAttendance::class)->handle($this->competition, $entry->competitor, false);

        $this->get(route('public.competitions.competitors', $this->competition))->assertSee('KK Sokol');
    }
}
