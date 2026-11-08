<?php

namespace App\Actions\Entries;

use App\Enums\Discipline;
use App\Models\Category;
use App\Models\Entry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Records the weight measured at the weigh-in and moves the entry to the weight category it belongs in.
 * The weight the club entered stays on the entry; the measured one is kept next to it.
 */
class RecordWeighIn
{
    public function __construct(private readonly CategoryMatcher $matcher) {}

    public function handle(Entry $entry, string $weight): WeighInResult
    {
        $entry->loadMissing('category', 'competitor');
        $current = $entry->category;

        if ($current->discipline !== Discipline::Kumite) {
            return new WeighInResult(WeighInResult::REFUSED, message: __('Only Kumite entries are weighed.'));
        }

        if ($entry->absent_at !== null) {
            return new WeighInResult(WeighInResult::REFUSED, message: __('The competitor is marked absent.'));
        }

        if ($current->hasDraw()) {
            return $this->locked($current);
        }

        $competitor = $entry->competitor;
        $measured = number_format((float) $weight, 2, '.', '');

        $target = $this->matcher->match(
            $this->ladder($current, Category::query()->where('competition_id', $current->competition_id)->where('discipline', Discipline::Kumite->value)->get()),
            Discipline::Kumite,
            $competitor->gender,
            $competitor->birth_date->year,
            (float) $measured,
        );

        return DB::transaction(function () use ($entry, $current, $target, $measured): WeighInResult {
            $weighed = ['weighed_weight' => $measured, 'weighed_at' => now()];

            if ($target === null) {
                $entry->update($weighed);

                return new WeighInResult(WeighInResult::NO_CATEGORY, $current);
            }

            if ($target->id === $current->id) {
                $entry->update($weighed);

                return new WeighInResult(WeighInResult::FITS, $current, $current);
            }

            if ($target->hasDraw()) {
                return $this->locked($target);
            }

            if (Entry::query()->where('category_id', $target->id)->where('competitor_id', $entry->competitor_id)->exists()) {
                $entry->update($weighed);

                return new WeighInResult(WeighInResult::CONFLICT, $current, $target);
            }

            $origin = $entry->moved_from_category_id ?? $current->id;

            try {
                $entry->update([
                    ...$weighed,
                    'category_id' => $target->id,
                    'moved_from_category_id' => $origin === $target->id ? null : $origin,
                    'seed' => null,
                ]);
            } catch (UniqueConstraintViolationException) {
                // The competitor was entered in the target class between the check above and the update.
                $entry->refresh()->update($weighed);

                return new WeighInResult(WeighInResult::CONFLICT, $current, $target);
            }

            return new WeighInResult(WeighInResult::MOVED, $current, $target);
        });
    }

    /**
     * Whether a weighed entry is in the wrong place: the weight class the matcher would choose for the measured
     * weight is not the one the entry is in (or there is none), as after a conflict or for a competitor whose age
     * or gender the category does not accept.
     *
     * @param  iterable<Category>  $kumiteCategories  The Kumite categories of the competition.
     */
    public function hasProblem(Entry $entry, iterable $kumiteCategories): bool
    {
        if ($entry->weighed_weight === null) {
            return false;
        }

        $competitor = $entry->competitor;

        $match = $this->matcher->match(
            $this->ladder($entry->category, $kumiteCategories),
            Discipline::Kumite,
            $competitor->gender,
            $competitor->birth_date->year,
            (float) $entry->weighed_weight,
        );

        return $match === null || $match->id !== $entry->category_id;
    }

    /**
     * The weight classes of the same age group and gender as the category: the only candidates when weighing, so a
     * competitor moves between weight classes and never to another age group that happens to overlap.
     *
     * @param  iterable<Category>  $kumiteCategories
     * @return array<int, Category>
     */
    private function ladder(Category $current, iterable $kumiteCategories): array
    {
        $ladder = [];

        foreach ($kumiteCategories as $category) {
            if ($category->birth_year_from === $current->birth_year_from
                && $category->birth_year_to === $current->birth_year_to
                && $category->gender === $current->gender) {
                $ladder[] = $category;
            }
        }

        return $ladder;
    }

    /**
     * Take the weigh-in back: the entry returns to the category it was moved from and loses the measured weight.
     */
    public function revert(Entry $entry): WeighInResult
    {
        $entry->loadMissing('category');
        $current = $entry->category;

        if ($entry->weighed_at === null) {
            return new WeighInResult(WeighInResult::REFUSED, message: __('This entry has not been weighed.'));
        }

        if ($current->hasDraw()) {
            return $this->locked($current);
        }

        $cleared = ['weighed_weight' => null, 'weighed_at' => null, 'moved_from_category_id' => null];

        if ($entry->moved_from_category_id === null) {
            $entry->update($cleared);

            return new WeighInResult(WeighInResult::FITS, $current, $current);
        }

        $origin = Category::query()->find($entry->moved_from_category_id);

        if ($origin === null) {
            $entry->update($cleared);

            return new WeighInResult(WeighInResult::FITS, $current, $current);
        }

        if ($origin->hasDraw()) {
            return $this->locked($origin);
        }

        if (Entry::query()->where('category_id', $origin->id)->where('competitor_id', $entry->competitor_id)->exists()) {
            return new WeighInResult(WeighInResult::CONFLICT, $current, $origin);
        }

        $entry->update([...$cleared, 'category_id' => $origin->id, 'seed' => null]);

        return new WeighInResult(WeighInResult::MOVED, $current, $origin);
    }

    private function locked(Category $category): WeighInResult
    {
        return new WeighInResult(
            WeighInResult::LOCKED,
            $category,
            message: __('Category ":value" already has a draw. Delete the draw first to change its entries.', ['value' => $category->name]),
        );
    }
}
