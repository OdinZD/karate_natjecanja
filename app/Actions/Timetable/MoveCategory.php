<?php

namespace App\Actions\Timetable;

use App\Models\Category;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MoveCategory
{
    /**
     * Move a category one place up or down within its tatami's schedule.
     */
    public function handle(Category $category, string $direction): void
    {
        if (! in_array($direction, ['up', 'down'], true)) {
            throw new InvalidArgumentException('The direction must be "up" or "down".');
        }

        if ($category->tatami_id === null) {
            return;
        }

        DB::transaction(function () use ($category, $direction): void {
            $siblings = Category::where('tatami_id', $category->tatami_id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->values();

            // Stored orders may be duplicated (zero by default, copied categories), so renumber first.
            foreach ($siblings as $index => $sibling) {
                $sibling->sort_order = $index + 1;
            }

            $position = $siblings->search(fn (Category $sibling) => $sibling->is($category));

            if ($position === false) {
                return;
            }

            $current = $siblings->get($position);
            $neighbour = $siblings->get($direction === 'up' ? $position - 1 : $position + 1);

            if ($current !== null && $neighbour !== null) {
                [$current->sort_order, $neighbour->sort_order] = [$neighbour->sort_order, $current->sort_order];
            }

            $siblings->each->save();
            $category->sort_order = $current->sort_order;
        });
    }
}
