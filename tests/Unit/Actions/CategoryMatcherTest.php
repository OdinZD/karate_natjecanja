<?php

namespace Tests\Unit\Actions;

use App\Actions\Categories\OfficialCategoryCatalog;
use App\Actions\Entries\CategoryMatcher;
use App\Enums\CategorySet;
use App\Enums\Discipline;
use App\Enums\Gender;
use App\Models\Category;
use Tests\TestCase;

class CategoryMatcherTest extends TestCase
{
    /** @var array<int, Category> */
    private array $tournament = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (app(OfficialCategoryCatalog::class)->categories(CategorySet::Tournament, 2026) as $index => $official) {
            $this->tournament[] = new Category([
                'name' => $official->name,
                'discipline' => $official->discipline,
                'gender' => $official->gender,
                'birth_year_from' => $official->birthYearFrom,
                'birth_year_to' => $official->birthYearTo,
                'weight_min' => $official->weightMin,
                'weight_max' => $official->weightMax,
                'sort_order' => $index,
            ]);
        }
    }

    private function matched(Discipline $discipline, Gender $gender, int $birthYear, ?float $weight = null): ?string
    {
        return app(CategoryMatcher::class)->match($this->tournament, $discipline, $gender, $birthYear, $weight)?->name;
    }

    public function test_a_child_gets_the_youngest_matching_age_group_and_the_lightest_weight_class(): void
    {
        $this->assertSame('U14 - Mlađi kadeti - Kata', $this->matched(Discipline::Kata, Gender::Male, 2014));
        $this->assertSame('U14 - Mlađi kadeti - Kumite -40 kg', $this->matched(Discipline::Kumite, Gender::Male, 2014, 33));
    }

    public function test_the_narrowest_age_group_wins_over_the_open_ended_senior_group(): void
    {
        $this->assertSame('Mlađi seniori - Kumite -75 kg', $this->matched(Discipline::Kumite, Gender::Male, 2007, 70));
        $this->assertSame('Juniorke - Kata', $this->matched(Discipline::Kata, Gender::Female, 2010));
        $this->assertSame('Seniorke - Kata', $this->matched(Discipline::Kata, Gender::Female, 2000));
    }

    public function test_veterans_are_split_by_age_and_use_the_open_weight_class(): void
    {
        $this->assertSame('Veterani 35+ - Kumite +85 kg', $this->matched(Discipline::Kumite, Gender::Male, 1986, 90));
        $this->assertSame('Veterani 50+ - Kumite -75 kg', $this->matched(Discipline::Kumite, Gender::Male, 1970, 74));
        $this->assertSame('Veterani 50+ - Kata', $this->matched(Discipline::Kata, Gender::Male, 1970));
    }

    public function test_the_boundary_weight_belongs_to_the_lighter_class(): void
    {
        $this->assertSame('Kadeti - Kumite -70 kg', $this->matched(Discipline::Kumite, Gender::Male, 2011, 70));
        $this->assertSame('Kadeti - Kumite +70 kg', $this->matched(Discipline::Kumite, Gender::Male, 2011, 70.01));
        $this->assertSame('Kadeti - Kumite +70 kg', $this->matched(Discipline::Kumite, Gender::Male, 2011, 75));
    }

    public function test_gender_decides_between_the_boys_and_girls_classes(): void
    {
        $this->assertSame('Kadetkinje - Kumite -47 kg', $this->matched(Discipline::Kumite, Gender::Female, 2011, 45));
        $this->assertSame('Kadeti - Kumite -52 kg', $this->matched(Discipline::Kumite, Gender::Male, 2011, 45));
    }

    public function test_kata_b_is_never_chosen(): void
    {
        $this->assertSame('U10 - Mlađe učenice - Kata', $this->matched(Discipline::Kata, Gender::Female, 2017));
    }

    public function test_no_category_fits_a_child_outside_every_age_group(): void
    {
        $this->assertNull($this->matched(Discipline::Kumite, Gender::Male, 2021, 20));
        $this->assertNull($this->matched(Discipline::Kata, Gender::Male, 2024));
    }

    public function test_kumite_without_a_weight_matches_nothing(): void
    {
        $this->assertNull($this->matched(Discipline::Kumite, Gender::Male, 2014));
    }

    public function test_mixed_categories_match_both_genders_but_lose_to_a_gendered_one(): void
    {
        $mixed = new Category(['name' => 'Open', 'discipline' => Discipline::Kata, 'gender' => null, 'birth_year_from' => 2010, 'birth_year_to' => 2012, 'sort_order' => 0]);
        $boys = new Category(['name' => 'Boys', 'discipline' => Discipline::Kata, 'gender' => Gender::Male, 'birth_year_from' => 2010, 'birth_year_to' => 2012, 'sort_order' => 1]);
        $matcher = app(CategoryMatcher::class);

        $this->assertSame('Boys', $matcher->match([$mixed, $boys], Discipline::Kata, Gender::Male, 2011)?->name);
        $this->assertSame('Open', $matcher->match([$mixed, $boys], Discipline::Kata, Gender::Female, 2011)?->name);
    }
}
