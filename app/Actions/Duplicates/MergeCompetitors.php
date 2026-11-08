<?php

namespace App\Actions\Duplicates;

use App\Models\Bout;
use App\Models\Category;
use App\Models\Competitor;
use App\Models\Entry;
use Illuminate\Support\Facades\DB;

/**
 * Merges a duplicate competitor into the one to keep: its entries move over (bouts and placements hang on the
 * entries, so draws and results stay intact) and the duplicate is deleted. When both are entered in the same
 * category the kept competitor's entry stays and the duplicate's is dropped, which is refused once that category
 * has a draw because the bracket would lose a slot.
 */
class MergeCompetitors
{
    public function handle(Competitor $survivor, Competitor $duplicate): MergeResult
    {
        if ($survivor->is($duplicate)) {
            return new MergeResult(error: __('Choose two different records.'));
        }

        if ($survivor->gender !== $duplicate->gender) {
            return new MergeResult(error: __('The two competitors have a different gender, so they are not merged.'));
        }

        return DB::transaction(function () use ($survivor, $duplicate): MergeResult {
            $kept = $survivor->entries()->pluck('category_id');
            $conflicts = $duplicate->entries()->whereIn('category_id', $kept)->get();

            $drawn = Bout::query()->whereIn('category_id', $conflicts->pluck('category_id'))->distinct()->pluck('category_id');

            if ($drawn->isNotEmpty()) {
                return new MergeResult(error: __('Both are entered in categories that already have a draw (:names), so they cannot be merged.', [
                    'names' => Category::query()->whereIn('id', $drawn)->orderBy('name')->pluck('name')->implode(', '),
                ]));
            }

            // The kept record existed before the import that created it if the duplicate did not come from that import,
            // so undoing the import must not delete the person together with the merged entries.
            if ($survivor->created_by_import_id !== null && $survivor->created_by_import_id !== $duplicate->created_by_import_id) {
                $survivor->update(['created_by_import_id' => null]);
            }

            $dropped = Entry::query()->whereKey($conflicts->pluck('id'))->delete();
            $moved = Entry::query()->where('competitor_id', $duplicate->id)->update(['competitor_id' => $survivor->id]);

            $duplicate->delete();

            return new MergeResult($moved, $dropped);
        });
    }
}
