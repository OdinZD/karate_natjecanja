<?php

namespace App\Actions\Timetable;

use App\Models\Category;
use App\Models\Tatami;
use InvalidArgumentException;

class AssignCategoryToTatami
{
    /**
     * Put a category at the end of a tatami's schedule, or take it off the schedule with null.
     */
    public function handle(Category $category, ?Tatami $tatami): void
    {
        if ($tatami === null) {
            $category->update(['tatami_id' => null]);

            return;
        }

        if ($tatami->competition_id !== $category->competition_id) {
            throw new InvalidArgumentException('The tatami belongs to a different competition.');
        }

        if ($category->tatami_id === $tatami->id) {
            return;
        }

        $category->update([
            'tatami_id' => $tatami->id,
            'sort_order' => (int) Category::where('tatami_id', $tatami->id)->max('sort_order') + 1,
        ]);
    }
}
