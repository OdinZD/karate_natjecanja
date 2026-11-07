<?php

namespace Tests\Feature\Admin;

use App\Actions\Categories\ImportOfficialCategories;
use App\Enums\CategorySet;
use App\Enums\Discipline;
use App\Enums\Gender;
use App\Models\Category;
use App\Models\Competition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ImportOfficialCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private Competition $competition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
        $this->competition = Competition::factory()->create(['date' => '2026-05-10']);
    }

    private function import(CategorySet $set, ?Competition $competition = null): void
    {
        app(ImportOfficialCategories::class)->handle($competition ?? $this->competition, $set);
    }

    private function category(string $name): Category
    {
        return Category::where('competition_id', $this->competition->id)->where('name', $name)->firstOrFail();
    }

    /**
     * @return array<string, array{CategorySet, int, int}>
     */
    public static function sets(): array
    {
        return [
            'tournament' => [CategorySet::Tournament, 27, 98],
            'national championship' => [CategorySet::NationalChampionship, 27, 130],
            'national cup' => [CategorySet::NationalCup, 24, 91],
        ];
    }

    #[DataProvider('sets')]
    public function test_it_creates_the_official_number_of_categories(CategorySet $set, int $kata, int $kumite): void
    {
        $this->import($set);

        $this->assertSame($kata, $this->competition->categories()->where('discipline', Discipline::Kata)->count());
        $this->assertSame($kumite, $this->competition->categories()->where('discipline', Discipline::Kumite)->count());
    }

    public function test_tournament_cadet_kumite_matches_the_official_table(): void
    {
        $this->import(CategorySet::Tournament);

        $category = $this->category('Kadeti - Kumite -52 kg');

        $this->assertSame(Discipline::Kumite, $category->discipline);
        $this->assertSame(Gender::Male, $category->gender);
        $this->assertSame(2011, $category->birth_year_from);
        $this->assertSame(2012, $category->birth_year_to);
        $this->assertNull($category->weight_min);
        $this->assertSame('52.00', $category->weight_max);
        $this->assertSame(120, $category->bout_duration_seconds);
    }

    public function test_open_ended_weight_and_age_groups(): void
    {
        $this->import(CategorySet::Tournament);

        $category = $this->category('Seniori - Kumite +84 kg');

        $this->assertSame(1900, $category->birth_year_from);
        $this->assertSame(2008, $category->birth_year_to);
        $this->assertSame('84.00', $category->weight_min);
        $this->assertNull($category->weight_max);
        $this->assertSame(180, $category->bout_duration_seconds);
    }

    public function test_female_weights_differ_from_male_weights(): void
    {
        $this->import(CategorySet::Tournament);

        $this->assertSame(Gender::Female, $this->category('Kadetkinje - Kumite -47 kg')->gender);
        $this->assertSame(0, $this->competition->categories()->where('name', 'Kadetkinje - Kumite -52 kg')->count());
    }

    public function test_kata_has_no_weight_and_b_variant_exists_for_children(): void
    {
        $this->import(CategorySet::Tournament);

        $category = $this->category('U10 - Mlađe učenice - Kata B');

        $this->assertSame(Discipline::Kata, $category->discipline);
        $this->assertSame(Gender::Female, $category->gender);
        $this->assertSame(2017, $category->birth_year_from);
        $this->assertSame(2018, $category->birth_year_to);
        $this->assertNull($category->weight_min);
        $this->assertNull($category->weight_max);
        $this->assertSame(0, $this->competition->categories()->where('name', 'Kadeti - Kata B')->count());
    }

    public function test_veterans_over_fifty_kata_is_male_only_but_kumite_has_both_genders(): void
    {
        $this->import(CategorySet::Tournament);

        $this->assertSame(1, $this->competition->categories()->where('name', 'like', '%50+ - Kata')->count());
        $this->assertSame(Gender::Male, $this->category('Veterani 50+ - Kata')->gender);
        $this->assertSame(6, $this->competition->categories()->where('name', 'like', 'Veteran%50+ - Kumite%')->count());
    }

    public function test_cup_children_are_named_and_ranged_by_birth_year(): void
    {
        $this->import(CategorySet::NationalCup);

        $category = $this->category('2016. Dječaci - Kumite -30 kg');

        $this->assertSame(2016, $category->birth_year_from);
        $this->assertSame(2016, $category->birth_year_to);
        $this->assertSame(60, $category->bout_duration_seconds);
        $this->assertSame(0, $this->competition->categories()->where('name', 'like', '2018.%Kumite%')->count());
        $this->assertSame(1, $this->competition->categories()->where('name', '2018. Djevojčice - Kata')->count());
    }

    public function test_birth_years_follow_the_competition_year(): void
    {
        $competition = Competition::factory()->create(['date' => '2027-05-10']);

        $this->import(CategorySet::NationalCup, $competition);

        $this->assertTrue($competition->categories()->where('name', '2017. Dječaci - Kumite -30 kg')->exists());
        $this->assertFalse($competition->categories()->where('name', '2016. Dječaci - Kumite -30 kg')->exists());
    }

    public function test_championship_splits_age_groups_by_single_year(): void
    {
        $this->import(CategorySet::NationalChampionship);

        $category = $this->category('U9 - Djevojčice - Kumite -28 kg');

        $this->assertSame(2018, $category->birth_year_from);
        $this->assertSame(2018, $category->birth_year_to);
        $this->assertSame(60, $category->bout_duration_seconds);
        $this->assertSame(0, $this->competition->categories()->where('name', 'U7 - Dječaci - Kumite -24 kg')->count());
    }

    public function test_importing_twice_skips_everything_and_keeps_manual_categories(): void
    {
        Category::factory()->create(['competition_id' => $this->competition->id, 'name' => 'Kadeti - Kumite -52 kg', 'sort_order' => 7]);

        $this->import(CategorySet::Tournament);
        $total = $this->competition->categories()->count();

        $this->assertSame(125, $total);

        $this->import(CategorySet::Tournament);

        $this->assertSame($total, $this->competition->categories()->count());
        $this->assertSame(7, $this->category('Kadeti - Kumite -52 kg')->sort_order);
    }

    public function test_result_reports_created_and_skipped(): void
    {
        Category::factory()->create(['competition_id' => $this->competition->id, 'name' => 'Kadeti - Kata']);

        $result = app(ImportOfficialCategories::class)->handle($this->competition, CategorySet::Tournament);

        $this->assertSame(['created' => 124, 'skipped' => 1], $result);
    }

    public function test_sort_order_continues_after_existing_categories(): void
    {
        Category::factory()->create(['competition_id' => $this->competition->id, 'name' => 'Manual', 'sort_order' => 40]);

        $this->import(CategorySet::Tournament);

        $this->assertSame(41, (int) $this->competition->categories()->where('name', '!=', 'Manual')->min('sort_order'));
    }

    public function test_other_competitions_are_untouched(): void
    {
        $other = Competition::factory()->create();

        $this->import(CategorySet::Tournament);

        $this->assertSame(0, $other->categories()->count());
    }

    public function test_admin_imports_a_set_from_the_categories_page(): void
    {
        Livewire::test('pages::admin.competitions.categories', ['competition' => $this->competition])
            ->call('openImportOfficial')
            ->set('officialSet', 'tournament')
            ->call('importOfficial')
            ->assertHasNoErrors();

        $this->assertSame(125, $this->competition->categories()->count());
    }

    public function test_page_rejects_missing_and_unknown_sets(): void
    {
        Livewire::test('pages::admin.competitions.categories', ['competition' => $this->competition])
            ->set('officialSet', '')
            ->call('importOfficial')
            ->assertHasErrors(['officialSet' => 'required'])
            ->set('officialSet', 'olympics')
            ->call('importOfficial')
            ->assertHasErrors(['officialSet']);

        $this->assertSame(0, $this->competition->categories()->count());
    }
}
