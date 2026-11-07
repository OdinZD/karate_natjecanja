<?php

namespace App\Actions\Categories;

use App\Enums\CategorySet;
use App\Models\Competition;
use Illuminate\Support\Facades\DB;

class ImportOfficialCategories
{
    public function __construct(private readonly OfficialCategoryCatalog $catalog) {}

    /**
     * Create the official categories of the set for the competition, skipping names that already exist.
     *
     * @return array{created: int, skipped: int}
     */
    public function handle(Competition $competition, CategorySet $set): array
    {
        $official = $this->catalog->categories($set, $competition->date->year);

        return DB::transaction(function () use ($competition, $official): array {
            $existing = array_flip($competition->categories()->pluck('name')->all());
            $sortOrder = (int) $competition->categories()->max('sort_order');

            $created = 0;
            $skipped = 0;

            foreach ($official as $category) {
                if (isset($existing[$category->name])) {
                    $skipped++;

                    continue;
                }

                $competition->categories()->create([
                    'name' => $category->name,
                    'discipline' => $category->discipline,
                    'gender' => $category->gender,
                    'birth_year_from' => $category->birthYearFrom,
                    'birth_year_to' => $category->birthYearTo,
                    'weight_min' => $category->weightMin,
                    'weight_max' => $category->weightMax,
                    'bout_duration_seconds' => $category->boutDurationSeconds,
                    'sort_order' => ++$sortOrder,
                ]);

                $created++;
            }

            return ['created' => $created, 'skipped' => $skipped];
        });
    }
}
