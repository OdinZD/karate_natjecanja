<?php

namespace App\Actions\Duplicates;

use App\Models\Club;
use App\Models\ClubImport;
use App\Models\Competitor;
use Illuminate\Support\Facades\DB;

/**
 * Merges a duplicate club into the one to keep: competitors, import history and competition links move over and the
 * duplicate is deleted. Competitors that are duplicates of each other afterwards show up on the duplicates page.
 */
class MergeClubs
{
    public function handle(Club $survivor, Club $duplicate): MergeResult
    {
        if ($survivor->is($duplicate)) {
            return new MergeResult(error: __('Choose two different records.'));
        }

        return DB::transaction(function () use ($survivor, $duplicate): MergeResult {
            $moved = Competitor::query()->where('club_id', $duplicate->id)->update(['club_id' => $survivor->id]);
            ClubImport::query()->where('club_id', $duplicate->id)->update(['club_id' => $survivor->id]);

            $linked = DB::table('competition_club')->where('club_id', $survivor->id)->pluck('competition_id');

            DB::table('competition_club')
                ->where('club_id', $duplicate->id)
                ->whereNotIn('competition_id', $linked)
                ->update(['club_id' => $survivor->id]);

            $duplicate->delete();

            return new MergeResult($moved);
        });
    }
}
