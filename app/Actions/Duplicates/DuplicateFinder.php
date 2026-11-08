<?php

namespace App\Actions\Duplicates;

use App\Actions\Entries\EntrySheet;
use App\Models\Club;
use App\Models\Competitor;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Finds records that are probably the same person or club entered twice: names that differ only in case,
 * diacritics or spacing, which is how the imports and manual entry produce duplicates.
 */
class DuplicateFinder
{
    public function __construct(private readonly EntrySheet $sheet) {}

    /**
     * Competitors with the same name, birth date and gender, in any club. The first of each group is the one with the
     * most entries (then the oldest), the natural one to keep.
     *
     * @return Collection<string, EloquentCollection<int, Competitor>> Groups of two or more, keyed by a stable hash.
     */
    public function competitors(int $limit = 50): Collection
    {
        $competitors = Competitor::query()
            ->whereRaw('date(birth_date) in (select date(birth_date) from competitors group by date(birth_date) having count(*) > 1)')
            ->orderBy('id')
            ->with('club')
            ->withCount('entries')
            ->get();

        return $this->groups($competitors, fn (Competitor $competitor) => implode('|', [
            $this->sheet->normalizeName($competitor->first_name),
            $this->sheet->normalizeName($competitor->last_name),
            $competitor->birth_date->format('Y-m-d'),
            $competitor->gender->value,
        ]), 'entries_count', $limit);
    }

    /**
     * Clubs with the same name and city.
     *
     * @return Collection<string, EloquentCollection<int, Club>>
     */
    public function clubs(int $limit = 50): Collection
    {
        return $this->groups(
            Club::query()->withCount('competitors')->orderBy('id')->get(),
            fn (Club $club) => $this->sheet->normalizeName($club->name).'|'.$this->sheet->normalizeName((string) $club->city),
            'competitors_count',
            $limit,
        );
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  EloquentCollection<int, TModel>  $models
     * @return Collection<string, EloquentCollection<int, TModel>>
     */
    private function groups(EloquentCollection $models, callable $key, string $countColumn, int $limit): Collection
    {
        return $models
            ->groupBy($key)
            ->filter(fn (EloquentCollection $group) => $group->count() > 1)
            ->map(fn (EloquentCollection $group) => $group->sortBy([[$countColumn, 'desc'], ['id', 'asc']])->values())
            ->mapWithKeys(fn (EloquentCollection $group, string $groupKey) => [substr(md5($groupKey), 0, 12) => $group])
            ->take($limit);
    }
}
