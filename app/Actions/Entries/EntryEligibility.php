<?php

namespace App\Actions\Entries;

use App\Models\Category;
use App\Models\Competitor;

class EntryEligibility
{
    /**
     * Reasons why the competitor cannot enter the category; empty when the entry is allowed.
     *
     * @return array<int, string>
     */
    public function violations(Category $category, Competitor $competitor, ?string $weight = null): array
    {
        $violations = [];

        if ($category->gender !== null && $category->gender !== $competitor->gender) {
            $violations[] = __('The competitor\'s gender does not match the category.');
        }

        $birthYear = $competitor->birth_date->year;

        if ($birthYear < $category->birth_year_from || $birthYear > $category->birth_year_to) {
            $violations[] = __('Birth year :year is outside the category range :from–:to.', [
                'year' => $birthYear,
                'from' => $category->birth_year_from,
                'to' => $category->birth_year_to,
            ]);
        }

        if ($weight !== null && $weight !== '') {
            $kilograms = (float) $weight;

            if ($category->weight_min !== null && $kilograms < (float) $category->weight_min) {
                $violations[] = __('Weight :weight kg is below the category minimum of :min kg.', [
                    'weight' => $weight,
                    'min' => (float) $category->weight_min,
                ]);
            }

            if ($category->weight_max !== null && $kilograms > (float) $category->weight_max) {
                $violations[] = __('Weight :weight kg is above the category maximum of :max kg.', [
                    'weight' => $weight,
                    'max' => (float) $category->weight_max,
                ]);
            }
        }

        return $violations;
    }
}
