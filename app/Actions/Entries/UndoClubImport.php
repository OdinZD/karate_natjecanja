<?php

namespace App\Actions\Entries;

use App\Models\Bout;
use App\Models\Category;
use App\Models\ClubImport;
use App\Models\Competitor;
use App\Models\Entry;
use Illuminate\Support\Facades\DB;

/**
 * Takes back what a club import created: its entries and the competitors it added that have no other entries.
 * Entries it only changed (a new weight or category) are not restored.
 */
class UndoClubImport
{
    public function handle(ClubImport $import): UndoClubImportResult
    {
        if (! $import->isActive()) {
            return new UndoClubImportResult(error: __('This import cannot be undone.'));
        }

        return DB::transaction(function () use ($import): UndoClubImportResult {
            $categoryIds = Entry::query()->where('club_import_id', $import->id)->distinct()->pluck('category_id');

            $drawn = Bout::query()->whereIn('category_id', $categoryIds)->distinct()->pluck('category_id');

            if ($drawn->isNotEmpty()) {
                return new UndoClubImportResult(error: __('Some of its entries are in categories with a draw (:names), so the import cannot be undone.', [
                    'names' => Category::query()->whereIn('id', $drawn)->orderBy('name')->pluck('name')->implode(', '),
                ]));
            }

            $entriesRemoved = Entry::query()->where('club_import_id', $import->id)->delete();

            $competitorsRemoved = Competitor::query()
                ->where('created_by_import_id', $import->id)
                ->whereDoesntHave('entries')
                ->delete();

            $import->forceFill(['undone_at' => now()])->save();

            return new UndoClubImportResult($entriesRemoved, $competitorsRemoved);
        });
    }
}
