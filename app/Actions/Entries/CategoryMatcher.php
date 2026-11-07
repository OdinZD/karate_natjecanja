<?php

namespace App\Actions\Entries;

use App\Enums\Discipline;
use App\Enums\Gender;
use App\Models\Category;

class CategoryMatcher
{
    private const KATA_B_SUFFIX = ' - Kata B';

    /**
     * The category a competitor belongs in, or null when none fits.
     *
     * Of all categories that accept the competitor, the one with the narrowest birth-year range wins,
     * then the lightest weight limit ("-70" before "+70"), then a gendered category over a mixed one.
     * Kata B categories are never chosen. Kumite needs a weight; without one nothing matches.
     *
     * @param  iterable<Category>  $categories
     */
    public function match(iterable $categories, Discipline $discipline, Gender $gender, int $birthYear, ?float $weight = null): ?Category
    {
        if ($discipline === Discipline::Kumite && $weight === null) {
            return null;
        }

        $best = null;
        $bestRank = null;

        foreach ($categories as $category) {
            if (! $this->accepts($category, $discipline, $gender, $birthYear, $weight)) {
                continue;
            }

            $rank = [
                $category->birth_year_to - $category->birth_year_from,
                $category->weight_max !== null ? (float) $category->weight_max : PHP_FLOAT_MAX,
                $category->gender === null ? 1 : 0,
                $category->sort_order,
                $category->id ?? 0,
            ];

            if ($bestRank === null || $rank < $bestRank) {
                $best = $category;
                $bestRank = $rank;
            }
        }

        return $best;
    }

    private function accepts(Category $category, Discipline $discipline, Gender $gender, int $birthYear, ?float $weight): bool
    {
        if ($category->discipline !== $discipline) {
            return false;
        }

        if ($category->gender !== null && $category->gender !== $gender) {
            return false;
        }

        if ($birthYear < $category->birth_year_from || $birthYear > $category->birth_year_to) {
            return false;
        }

        if ($discipline === Discipline::Kata) {
            return ! str_ends_with($category->name, self::KATA_B_SUFFIX);
        }

        if ($category->weight_min !== null && $weight < (float) $category->weight_min) {
            return false;
        }

        return $category->weight_max === null || $weight <= (float) $category->weight_max;
    }
}
