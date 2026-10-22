<?php

namespace App\Actions\Draw;

use App\Models\Category;
use Illuminate\Support\Facades\DB;

class DeleteDraw
{
    /**
     * Remove the draw of a category as long as no bout has been played.
     *
     * @throws DrawException
     */
    public function handle(Category $category): void
    {
        DB::transaction(function () use ($category): void {
            if ($category->hasPlayedBouts()) {
                throw new DrawException(__('The draw cannot be changed because bouts have already been played.'));
            }

            $category->bouts()->delete();
            $category->placements()->delete();
        });
    }
}
