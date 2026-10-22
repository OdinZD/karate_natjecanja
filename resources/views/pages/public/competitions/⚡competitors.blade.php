<?php

use App\Models\Club;
use App\Models\Competition;
use App\Models\Competitor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::public'), Title('Competitors')] class extends Component {
    use WithPagination;

    public Competition $competition;

    public string $search = '';
    public string $clubFilter = '';

    public function mount(Competition $publicCompetition): void
    {
        $this->competition = $publicCompetition;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedClubFilter(): void
    {
        $this->resetPage();
    }

    /**
     * @return Collection<int, Club>
     */
    #[Computed]
    public function clubs(): Collection
    {
        return Club::query()
            ->whereHas('competitors.entries.category', fn ($query) => $query->where('competition_id', $this->competition->id))
            ->orderBy('name')
            ->get(['clubs.id', 'clubs.name']);
    }

    /**
     * @return LengthAwarePaginator<int, Competitor>
     */
    #[Computed]
    public function competitors(): LengthAwarePaginator
    {
        $competitionId = $this->competition->id;
        $term = trim($this->search);

        return Competitor::query()
            ->whereHas('entries.category', fn ($query) => $query->where('competition_id', $competitionId))
            ->when(mb_strlen($term) >= 2, function ($query) use ($term) {
                // Every word must match the first or the last name, so "Ana Horvat" finds Horvat Ana.
                foreach (preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY) as $word) {
                    $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';

                    $query->where(fn ($inner) => $inner
                        ->whereRaw("first_name like ? escape '!'", [$like])
                        ->orWhereRaw("last_name like ? escape '!'", [$like]));
                }
            })
            ->when($this->clubFilter !== '', fn ($query) => $query->where('club_id', (int) $this->clubFilter))
            ->with([
                'club:id,name',
                'entries' => fn ($query) => $query
                    ->whereHas('category', fn ($inner) => $inner->where('competition_id', $competitionId))
                    ->with('category:id,name,sort_order'),
            ])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(50);
    }
}; ?>

<section class="w-full">
    <x-public.competition-header :competition="$competition" active="competitors" />

    <div class="mb-4 grid gap-3 sm:grid-cols-2">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search by name')" :aria-label="__('Search by name')" />

        <flux:select wire:model.live="clubFilter" :aria-label="__('Club')">
            <flux:select.option value="">{{ __('All clubs') }}</flux:select.option>
            @foreach ($this->clubs as $club)
                <flux:select.option :value="$club->id">{{ $club->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($this->competitors->isEmpty())
        <p class="ps-panel p-5 ps-muted">{{ __('No competitors found.') }}</p>
    @else
        <div class="ps-panel overflow-hidden">
            @foreach ($this->competitors as $competitor)
                <div class="ps-row flex-wrap" data-test="competitor-row" wire:key="competitor-{{ $competitor->id }}">
                    <div class="min-w-0 grow basis-56">
                        <div class="text-lg font-semibold">{{ $competitor->last_name }} {{ $competitor->first_name }}</div>
                        <div class="text-sm ps-muted">{{ $competitor->club->name }}</div>
                    </div>
                    <div class="text-sm ps-muted sm:max-w-xs sm:text-end">
                        {{ $competitor->entries->sortBy([['category.sort_order', 'asc'], ['category.name', 'asc']])->pluck('category.name')->join(', ') }}
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4">{{ $this->competitors->links() }}</div>
    @endif
</section>
