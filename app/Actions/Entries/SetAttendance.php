<?php

namespace App\Actions\Entries;

use App\Models\Bout;
use App\Models\Competition;
use App\Models\Competitor;
use App\Models\Entry;

/**
 * Marks a competitor absent from (or present at) a competition. All their entries in it follow, so an absent
 * competitor stays in the database but is left out of the draw.
 */
class SetAttendance
{
    /**
     * @return string|null The reason nothing was changed, or null when it worked.
     */
    public function handle(Competition $competition, Competitor $competitor, bool $absent): ?string
    {
        $entries = Entry::query()
            ->where('competitor_id', $competitor->id)
            ->whereHas('category', fn ($query) => $query->where('competition_id', $competition->id));

        $categoryIds = (clone $entries)->pluck('category_id');

        if ($categoryIds->isEmpty()) {
            return __('The competitor has no entries in this competition.');
        }

        if (Bout::query()->whereIn('category_id', $categoryIds)->exists()) {
            return __('This category already has a draw. Delete the draw first to change its entries.');
        }

        $entries->update(['absent_at' => $absent ? now() : null]);

        return null;
    }
}
