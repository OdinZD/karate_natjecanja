<?php

namespace Tests\Feature\Admin;

use App\Actions\Draw\GenerateDraw;
use App\Actions\Duplicates\DuplicateFinder;
use App\Actions\Duplicates\MergeClubs;
use App\Actions\Duplicates\MergeCompetitors;
use App\Actions\Entries\UndoClubImport;
use App\Enums\Gender;
use App\Models\Bout;
use App\Models\Category;
use App\Models\Club;
use App\Models\ClubImport;
use App\Models\Competition;
use App\Models\Competitor;
use App\Models\Entry;
use App\Models\Placement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class DuplicatesTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private Competition $competition;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        $this->club = Club::factory()->create(['name' => 'KK Sokol', 'city' => 'Ivanec']);
        $this->competition = Competition::factory()->create();
    }

    private function child(string $last, string $first, string $birth = '2011-03-14', ?Club $club = null, Gender $gender = Gender::Female): Competitor
    {
        return Competitor::factory()->for($club ?? $this->club)->create(['last_name' => $last, 'first_name' => $first, 'birth_date' => $birth, 'gender' => $gender]);
    }

    private function category(string $name = 'Kadeti - Kata'): Category
    {
        return Category::factory()->for($this->competition)->create(['name' => $name]);
    }

    private function enter(Competitor $competitor, Category $category): Entry
    {
        return Entry::factory()->for($category)->create(['competitor_id' => $competitor->id]);
    }

    public function test_competitors_that_differ_in_case_diacritics_or_spacing_are_found(): void
    {
        $this->child('Babić', 'Ana');
        $this->child(' babic ', 'ANA');
        $this->child('Unique', 'Una');

        $groups = app(DuplicateFinder::class)->competitors();

        $this->assertCount(1, $groups);
        $this->assertSame(2, $groups->first()->count());
    }

    public function test_a_duplicate_in_another_club_is_found(): void
    {
        $other = Club::factory()->create();
        $this->child('Babić', 'Ana');
        $this->child('Babic', 'Ana', club: $other);

        $this->assertCount(1, app(DuplicateFinder::class)->competitors());
    }

    public function test_twins_of_different_gender_or_other_birth_dates_are_not_duplicates(): void
    {
        $this->child('Babić', 'Ana', gender: Gender::Female);
        $this->child('Babić', 'Ana', gender: Gender::Male);
        $this->child('Babić', 'Ana', '2012-03-14');

        $this->assertCount(0, app(DuplicateFinder::class)->competitors());
    }

    public function test_the_competitor_with_the_most_entries_comes_first(): void
    {
        $first = $this->child('Babić', 'Ana');
        $busy = $this->child('Babic', 'Ana');
        $this->enter($busy, $this->category());

        $group = app(DuplicateFinder::class)->competitors()->first();

        $this->assertTrue($group->first()->is($busy));
        $this->assertTrue($group->last()->is($first));
    }

    public function test_clubs_with_the_same_name_and_city_are_found(): void
    {
        Club::factory()->create(['name' => 'kk sokol', 'city' => 'ivanec']);
        Club::factory()->create(['name' => 'KK Drugi', 'city' => 'Zadar']);
        Club::factory()->create(['name' => 'KK Drugi', 'city' => null]);

        $groups = app(DuplicateFinder::class)->clubs();

        $this->assertCount(1, $groups);
        $this->assertSame(['KK Sokol', 'kk sokol'], $groups->first()->pluck('name')->sort()->values()->all());
    }

    public function test_merging_moves_the_entries_and_deletes_the_duplicate(): void
    {
        $keep = $this->child('Babić', 'Ana');
        $duplicate = $this->child('Babic', 'Ana');
        $kata = $this->enter($duplicate, $this->category('Kadeti - Kata'));
        $this->enter($keep, $this->category('Kadeti - Kumite'));

        $result = app(MergeCompetitors::class)->handle($keep, $duplicate);

        $this->assertFalse($result->failed());
        $this->assertSame([1, 0], [$result->moved, $result->dropped]);
        $this->assertNull(Competitor::find($duplicate->id));
        $this->assertSame($keep->id, $kata->fresh()->competitor_id);
        $this->assertSame(2, $keep->entries()->count());
    }

    public function test_a_clash_in_the_same_category_keeps_the_survivors_entry(): void
    {
        $keep = $this->child('Babić', 'Ana');
        $duplicate = $this->child('Babic', 'Ana');
        $category = $this->category();
        $own = $this->enter($keep, $category);
        $this->enter($duplicate, $category);

        $result = app(MergeCompetitors::class)->handle($keep, $duplicate);

        $this->assertSame([0, 1], [$result->moved, $result->dropped]);
        $this->assertSame([$own->id], $keep->entries()->pluck('id')->all());
        $this->assertSame(1, Entry::count());
    }

    public function test_a_clash_in_a_category_with_a_draw_refuses_the_merge(): void
    {
        $keep = $this->child('Babić', 'Ana');
        $duplicate = $this->child('Babic', 'Ana');
        $category = $this->category('Kadeti - Kumite');
        $this->enter($keep, $category);
        $dropped = $this->enter($duplicate, $category);
        Entry::factory()->for($category)->create();
        app(GenerateDraw::class)->handle($category);

        $result = app(MergeCompetitors::class)->handle($keep, $duplicate);

        $this->assertTrue($result->failed());
        $this->assertStringContainsString('Kadeti - Kumite', $result->error);
        $this->assertNotNull(Competitor::find($duplicate->id));
        $this->assertNotNull($dropped->fresh());
    }

    public function test_bouts_and_placements_survive_a_merge_of_a_moved_entry(): void
    {
        $keep = $this->child('Babić', 'Ana');
        $duplicate = $this->child('Babic', 'Ana');
        $category = $this->category('Kadeti - Kumite');
        $entry = $this->enter($duplicate, $category);
        Entry::factory()->for($category)->create();
        app(GenerateDraw::class)->handle($category);
        Placement::factory()->create(['category_id' => $category->id, 'entry_id' => $entry->id, 'place' => 1]);
        $bouts = Bout::count();

        $result = app(MergeCompetitors::class)->handle($keep, $duplicate);

        $this->assertFalse($result->failed());
        $this->assertSame($bouts, Bout::count());
        $this->assertSame(1, Placement::where('entry_id', $entry->id)->count());
        $this->assertSame($keep->id, $entry->fresh()->competitor_id);
    }

    public function test_a_different_gender_or_the_same_record_is_refused(): void
    {
        $girl = $this->child('Babić', 'Ana');
        $boy = $this->child('Babić', 'Ana', gender: Gender::Male);

        $this->assertTrue(app(MergeCompetitors::class)->handle($girl, $boy)->failed());
        $this->assertTrue(app(MergeCompetitors::class)->handle($girl, $girl)->failed());
        $this->assertNotNull($boy->fresh());
    }

    public function test_merging_clubs_moves_competitors_imports_and_competition_links(): void
    {
        $keep = $this->club;
        $duplicate = Club::factory()->create(['name' => 'kk sokol', 'city' => 'ivanec']);
        $child = $this->child('Babić', 'Ana', club: $duplicate);
        $import = ClubImport::factory()->create(['club_id' => $duplicate->id, 'competition_id' => $this->competition->id]);
        $shared = Competition::factory()->create();
        $only = Competition::factory()->create();
        $shared->clubs()->attach([$keep->id, $duplicate->id]);
        $only->clubs()->attach($duplicate->id);

        $result = app(MergeClubs::class)->handle($keep, $duplicate);

        $this->assertFalse($result->failed());
        $this->assertSame(1, $result->moved);
        $this->assertNull(Club::find($duplicate->id));
        $this->assertSame($keep->id, $child->fresh()->club_id);
        $this->assertSame($keep->id, $import->fresh()->club_id);
        $this->assertEqualsCanonicalizing([$shared->id, $only->id], DB::table('competition_club')->where('club_id', $keep->id)->pluck('competition_id')->all());
    }

    public function test_the_same_club_cannot_be_merged_into_itself(): void
    {
        $this->assertTrue(app(MergeClubs::class)->handle($this->club, $this->club)->failed());
    }

    private function page(): Testable
    {
        $this->actingAs(User::factory()->admin()->create());

        return Livewire::test('pages::admin.duplicates.index');
    }

    public function test_the_page_is_for_admins_only(): void
    {
        $this->get(route('admin.duplicates'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create());
        $this->get(route('admin.duplicates'))->assertForbidden();

        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('admin.duplicates'))->assertOk()->assertSee('Duplicates')->assertSee('No suspected duplicates.');
    }

    public function test_the_page_merges_a_group_into_the_selected_record(): void
    {
        $first = $this->child('Babić', 'Ana');
        $second = $this->child('Babic', 'Ana');
        $third = $this->child('BABIC', 'ana');
        $this->enter($second, $this->category());

        $page = $this->page();
        $key = $page->get('competitorGroups')->keys()->first();

        $page->assertSeeHtml('data-test="duplicate-group"');
        $page->set('survivors.'.$key, (string) $third->id)->call('mergeCompetitors', $key, $page->get('competitorGroups')[$key]->modelKeys());

        $this->assertSame([$third->id], Competitor::pluck('id')->all());
        $this->assertSame($third->id, Entry::sole()->competitor_id);
        $this->assertCount(0, $page->get('competitorGroups'));
        $this->assertNull(Competitor::find($first->id));
    }

    public function test_the_page_keeps_the_suggested_record_when_none_is_chosen(): void
    {
        $keep = $this->child('Babić', 'Ana');
        $this->child('Babic', 'Ana');
        $this->enter($keep, $this->category());

        $page = $this->page();
        $key = $page->get('competitorGroups')->keys()->first();
        $page->call('mergeCompetitors', $key, $page->get('competitorGroups')[$key]->modelKeys());

        $this->assertSame([$keep->id], Competitor::pluck('id')->all());
    }

    public function test_the_page_merges_clubs(): void
    {
        $duplicate = Club::factory()->create(['name' => 'kk sokol', 'city' => 'ivanec']);
        $child = $this->child('Babić', 'Ana', club: $duplicate);

        $page = $this->page();
        $key = $page->get('clubGroups')->keys()->first();
        $page->set('survivors.'.$key, (string) $this->club->id)->call('mergeClubs', $key, $page->get('clubGroups')[$key]->modelKeys());

        $this->assertSame([$this->club->id], Club::pluck('id')->all());
        $this->assertSame($this->club->id, $child->fresh()->club_id);
    }

    public function test_a_stale_group_key_merges_nothing(): void
    {
        $this->child('Babić', 'Ana');
        $this->child('Babic', 'Ana');

        $this->page()->call('mergeCompetitors', 'doesnotexist', [1, 2]);

        $this->assertSame(2, Competitor::count());
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_groups(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(DuplicateFinder::class)->competitors();
            app(DuplicateFinder::class)->clubs();

            return count(DB::getQueryLog());
        };

        $this->child('Babić', 'Ana');
        $this->child('Babic', 'Ana');
        $small = $count();

        foreach (range(1, 20) as $n) {
            $this->child('Prezime'.$n, 'Ime', '2010-01-'.str_pad((string) (($n % 27) + 1), 2, '0', STR_PAD_LEFT));
            $this->child('prezime'.$n, 'ime', '2010-01-'.str_pad((string) (($n % 27) + 1), 2, '0', STR_PAD_LEFT));
        }

        $this->assertSame($small, $count());
    }

    public function test_the_suggested_record_is_preselected(): void
    {
        $keep = $this->child('Babić', 'Ana');
        $this->child('Babic', 'Ana');
        $this->enter($keep, $this->category());

        $page = $this->page();
        $key = $page->get('competitorGroups')->keys()->first();

        $this->assertSame((string) $keep->id, $page->get('survivors')[$key]);
    }

    public function test_undoing_an_import_after_a_merge_does_not_delete_the_person(): void
    {
        $import = ClubImport::factory()->create(['club_id' => $this->club->id, 'competition_id' => $this->competition->id]);
        $manual = $this->child('Babić', 'Ana');
        $imported = $this->child('Babic', 'Ana');
        $imported->forceFill(['created_by_import_id' => $import->id])->save();
        $this->enter($imported, $this->category());
        Entry::query()->update(['club_import_id' => $import->id]);

        $this->assertFalse(app(MergeCompetitors::class)->handle($imported, $manual)->failed());
        app(UndoClubImport::class)->handle($import);

        $this->assertSame([$imported->id], Competitor::pluck('id')->all());
        $this->assertNull($imported->fresh()->created_by_import_id);
    }

    public function test_a_merge_with_the_same_import_keeps_the_link(): void
    {
        $import = ClubImport::factory()->create(['club_id' => $this->club->id, 'competition_id' => $this->competition->id]);
        $keep = $this->child('Babić', 'Ana');
        $duplicate = $this->child('Babic', 'Ana');
        $keep->forceFill(['created_by_import_id' => $import->id])->save();
        $duplicate->forceFill(['created_by_import_id' => $import->id])->save();

        app(MergeCompetitors::class)->handle($keep, $duplicate);

        $this->assertSame($import->id, $keep->fresh()->created_by_import_id);
    }

    public function test_a_group_that_gained_a_member_since_it_was_shown_is_not_merged(): void
    {
        $this->child('Babić', 'Ana');
        $this->child('Babic', 'Ana');

        $page = $this->page();
        $key = $page->get('competitorGroups')->keys()->first();
        $shown = $page->get('competitorGroups')[$key]->modelKeys();

        $this->child('BABIC', 'ana');
        $page->call('mergeCompetitors', $key, $shown);

        $this->assertSame(3, Competitor::count());
    }

    public function test_birth_dates_stored_with_a_time_part_still_group(): void
    {
        $first = $this->child('Babić', 'Ana');
        $second = $this->child('Babic', 'Ana');
        Competitor::query()->whereKey($first->id)->toBase()->update(['birth_date' => '2011-03-14 10:00:00']);
        Competitor::query()->whereKey($second->id)->toBase()->update(['birth_date' => '2011-03-14 00:00:00']);

        $this->assertCount(1, app(DuplicateFinder::class)->competitors());
    }

    public function test_a_group_of_three_is_merged_all_or_nothing(): void
    {
        $keep = $this->child('Babić', 'Ana');
        $easy = $this->child('Babic', 'Ana');
        $blocked = $this->child('BABIC', 'ana');
        $category = $this->category('Kadeti - Kumite');
        $this->enter($keep, $category);
        $this->enter($blocked, $category);
        $this->enter($easy, $this->category('Kadeti - Kata'));
        Entry::factory()->for($category)->create();
        app(GenerateDraw::class)->handle($category);

        $page = $this->page();
        $key = $page->get('competitorGroups')->keys()->first();
        $page->set('survivors.'.$key, (string) $keep->id)->call('mergeCompetitors', $key, $page->get('competitorGroups')[$key]->modelKeys());

        $this->assertSame(3, Competitor::whereIn('id', [$keep->id, $easy->id, $blocked->id])->count());
        $this->assertSame($easy->id, Entry::whereHas('category', fn ($query) => $query->where('name', 'Kadeti - Kata'))->sole()->competitor_id);
    }

    public function test_a_stale_survivor_choice_is_replaced_by_the_suggestion(): void
    {
        $keep = $this->child('Babić', 'Ana');
        $this->child('Babic', 'Ana');
        $this->enter($keep, $this->category());

        $page = $this->page();
        $key = $page->get('competitorGroups')->keys()->first();
        $page->set('survivors.'.$key, '999999')->call('mergeCompetitors', $key, $page->get('competitorGroups')[$key]->modelKeys());

        $this->assertSame([$keep->id], Competitor::pluck('id')->all());
    }

    public function test_the_confirmation_counts_only_the_records_that_are_merged_away_and_mentions_other_clubs(): void
    {
        $other = Club::factory()->create();
        $this->child('Babić', 'Ana');
        $this->child('Babic', 'Ana', club: $other);

        $html = $this->page()->html();

        $this->assertStringContainsString('Merge 1 records into the selected one?', $html);
        $this->assertStringContainsString('The entries and medals move to the club of the record you keep.', $html);
    }

    public function test_the_merge_buttons_send_the_key_and_the_shown_ids_as_a_javascript_array(): void
    {
        $first = $this->child('Babić', 'Ana');
        $second = $this->child('Babic', 'Ana');
        $club = Club::factory()->create(['name' => 'kk sokol', 'city' => 'ivanec']);

        $page = $this->page();
        $competitorKey = $page->get('competitorGroups')->keys()->first();
        $clubKey = $page->get('clubGroups')->keys()->first();
        $html = $page->html();

        $this->assertStringContainsString("wire:click=\"mergeCompetitors('{$competitorKey}', [{$first->id},{$second->id}])\"", $html);
        $this->assertStringContainsString("wire:click=\"mergeClubs('{$clubKey}', [{$this->club->id},{$club->id}])\"", $html);
        $this->assertStringNotContainsString('@js', $html);
    }
}
