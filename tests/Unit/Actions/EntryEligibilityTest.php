<?php

namespace Tests\Unit\Actions;

use App\Actions\Entries\EntryEligibility;
use App\Enums\Gender;
use App\Models\Category;
use App\Models\Competitor;
use Tests\TestCase;

class EntryEligibilityTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function category(array $attributes = []): Category
    {
        return new Category([
            'gender' => Gender::Male,
            'birth_year_from' => 2010,
            'birth_year_to' => 2012,
            'weight_min' => null,
            'weight_max' => null,
            ...$attributes,
        ]);
    }

    private function competitor(string $birthDate = '2011-06-15', Gender $gender = Gender::Male): Competitor
    {
        return new Competitor(['birth_date' => $birthDate, 'gender' => $gender]);
    }

    public function test_a_matching_competitor_has_no_violations(): void
    {
        $this->assertSame([], (new EntryEligibility)->violations($this->category(), $this->competitor()));
    }

    public function test_gender_must_match_unless_the_category_is_mixed(): void
    {
        $eligibility = new EntryEligibility;
        $girl = $this->competitor(gender: Gender::Female);

        $this->assertCount(1, $eligibility->violations($this->category(), $girl));
        $this->assertSame([], $eligibility->violations($this->category(['gender' => null]), $girl));
        $this->assertSame([], $eligibility->violations($this->category(['gender' => Gender::Female]), $girl));
    }

    public function test_birth_year_range_is_inclusive(): void
    {
        $eligibility = new EntryEligibility;
        $category = $this->category();

        $this->assertSame([], $eligibility->violations($category, $this->competitor('2010-01-01')));
        $this->assertSame([], $eligibility->violations($category, $this->competitor('2012-12-31')));
        $this->assertCount(1, $eligibility->violations($category, $this->competitor('2009-12-31')));
        $this->assertCount(1, $eligibility->violations($category, $this->competitor('2013-01-01')));
    }

    public function test_weight_is_checked_only_when_provided(): void
    {
        $eligibility = new EntryEligibility;
        $category = $this->category(['weight_min' => '30', 'weight_max' => '40']);
        $competitor = $this->competitor();

        $this->assertSame([], $eligibility->violations($category, $competitor));
        $this->assertSame([], $eligibility->violations($category, $competitor, ''));
        $this->assertSame([], $eligibility->violations($category, $competitor, '30'));
        $this->assertSame([], $eligibility->violations($category, $competitor, '40.00'));
        $this->assertCount(1, $eligibility->violations($category, $competitor, '29.9'));
        $this->assertCount(1, $eligibility->violations($category, $competitor, '40.1'));
    }

    public function test_open_ended_weight_ranges_and_categories_without_weights(): void
    {
        $eligibility = new EntryEligibility;
        $competitor = $this->competitor();

        $this->assertSame([], $eligibility->violations($this->category(), $competitor, '80'));
        $this->assertSame([], $eligibility->violations($this->category(['weight_min' => '60']), $competitor, '80'));
        $this->assertCount(1, $eligibility->violations($this->category(['weight_max' => '60']), $competitor, '80'));
    }

    public function test_all_violations_are_reported_together(): void
    {
        $violations = (new EntryEligibility)->violations(
            $this->category(['weight_max' => '40']),
            $this->competitor('2005-01-01', Gender::Female),
            '70',
        );

        $this->assertCount(3, $violations);
    }
}
