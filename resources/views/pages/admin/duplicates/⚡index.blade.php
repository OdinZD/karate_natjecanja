<?php

use App\Actions\Duplicates\DuplicateFinder;
use App\Actions\Duplicates\MergeClubs;
use App\Actions\Duplicates\MergeCompetitors;
use App\Actions\Duplicates\MergeRefused;
use App\Models\Club;
use App\Models\Competitor;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Duplicates')] class extends Component {
    /** @var array<string, string> The record to keep per group, by group key. */
    public array $survivors = [];

    public function mount(): void
    {
        $this->suggestSurvivors();
    }

    /**
     * @return Collection<string, \Illuminate\Database\Eloquent\Collection<int, Competitor>>
     */
    #[Computed]
    public function competitorGroups(): Collection
    {
        return app(DuplicateFinder::class)->competitors();
    }

    /**
     * @return Collection<string, \Illuminate\Database\Eloquent\Collection<int, Club>>
     */
    #[Computed]
    public function clubGroups(): Collection
    {
        return app(DuplicateFinder::class)->clubs();
    }

    /**
     * @param  array<int, int|string>  $ids  The records shown in the group when the admin confirmed.
     */
    public function mergeCompetitors(string $key, array $ids, MergeCompetitors $merge): void
    {
        $group = $this->competitorGroups->get($key);

        if ($group === null || ! $this->sameRecords($group, $ids)) {
            Flux::toast(variant: 'danger', text: __('These records have changed, so nothing was merged.'));
            $this->refresh();

            return;
        }

        $this->mergeGroup($group, $key, fn ($survivor, $duplicate) => $merge->handle($survivor, $duplicate));
    }

    /**
     * @param  array<int, int|string>  $ids  The records shown in the group when the admin confirmed.
     */
    public function mergeClubs(string $key, array $ids, MergeClubs $merge): void
    {
        $group = $this->clubGroups->get($key);

        if ($group === null || ! $this->sameRecords($group, $ids)) {
            Flux::toast(variant: 'danger', text: __('These records have changed, so nothing was merged.'));
            $this->refresh();

            return;
        }

        $this->mergeGroup($group, $key, fn ($survivor, $duplicate) => $merge->handle($survivor, $duplicate));
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model>  $group
     * @param  array<int, int|string>  $ids
     */
    private function sameRecords($group, array $ids): bool
    {
        $shown = array_map('intval', $ids);
        $current = $group->modelKeys();

        sort($shown);
        sort($current);

        return $shown === $current;
    }

    /**
     * Merge every other record of the group into the chosen one, all or nothing.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model>  $group
     */
    private function mergeGroup($group, string $key, callable $merge): void
    {
        $survivor = $group->firstWhere('id', (int) ($this->survivors[$key] ?? 0)) ?? $group->first();
        $others = $group->reject(fn ($record) => $record->is($survivor));

        try {
            DB::transaction(function () use ($others, $survivor, $merge): void {
                foreach ($others as $duplicate) {
                    $result = $merge($survivor, $duplicate);

                    if ($result->failed()) {
                        throw new MergeRefused($result->error);
                    }
                }
            });
        } catch (MergeRefused $refused) {
            Flux::toast(variant: 'danger', text: $refused->getMessage());
            $this->refresh();

            return;
        }

        unset($this->survivors[$key]);

        Flux::toast(variant: 'success', text: trans_choice('{1} :count record merged.|[2,*] :count records merged.', $others->count(), ['count' => $others->count()]));

        $this->refresh();
    }

    private function refresh(): void
    {
        unset($this->competitorGroups, $this->clubGroups);

        $this->suggestSurvivors();
    }

    /**
     * Preselect the record to keep in every group that has no choice yet: the first, i.e. the one with the most entries.
     */
    private function suggestSurvivors(): void
    {
        foreach ([$this->competitorGroups, $this->clubGroups] as $groups) {
            foreach ($groups as $key => $group) {
                if (! $group->contains('id', (int) ($this->survivors[$key] ?? 0))) {
                    $this->survivors[$key] = (string) $group->first()->id;
                }
            }
        }
    }
}; ?>

<section class="w-full min-w-0 space-y-6">
    <div class="space-y-1">
        <h1 class="font-display text-4xl font-black uppercase leading-[.95] tracking-[.01em] text-arena-ink md:text-5xl">{{ __('Duplicates') }}</h1>
        <p class="max-w-3xl text-sm text-arena-muted">{{ __('Records that probably are the same person or club entered twice. Choose the one to keep: the others are merged into it and deleted.') }}</p>
    </div>

    <div class="space-y-3" data-test="competitor-duplicates">
        <x-arena.section-title :meta="__(':count groups', ['count' => $this->competitorGroups->count()])">{{ __('Competitors') }}</x-arena.section-title>

        @forelse ($this->competitorGroups as $key => $group)
            <x-arena.card class="space-y-3 p-4" wire:key="competitor-group-{{ $key }}" data-test="duplicate-group">
                <flux:radio.group wire:model="survivors.{{ $key }}" :label="__('Keep this record')">
                    @foreach ($group as $competitor)
                        <flux:radio class="min-h-11 items-center" :value="(string) $competitor->id" :label="$competitor->last_name.' '.$competitor->first_name.' · '.$competitor->birth_date->format('d.m.Y.').' · '.$competitor->club->name.' · '.__(':count entries', ['count' => $competitor->entries_count])" wire:key="competitor-{{ $competitor->id }}" />
                    @endforeach
                </flux:radio.group>

                @if ($group->pluck('club_id')->unique()->count() > 1)
                    <p class="text-sm text-arena-muted">{{ __('They are in different clubs: the entries and medals of the merged records move to the club of the one you keep.') }}</p>
                @endif

                <x-arena.button variant="secondary" icon="arrows-pointing-in" wire:click="mergeCompetitors('{{ $key }}', [{{ implode(',', $group->modelKeys()) }}])" wire:confirm="{{ __('Merge :count records into the selected one? Their entries move to it and the others are deleted.', ['count' => $group->count() - 1]) }}{{ $group->pluck('club_id')->unique()->count() > 1 ? ' '.__('The entries and medals move to the club of the record you keep.') : '' }}" data-test="merge-competitors-button">{{ __('Merge') }}</x-arena.button>
            </x-arena.card>
        @empty
            <x-arena.card class="p-4"><p class="text-sm text-arena-muted">{{ __('No suspected duplicates.') }}</p></x-arena.card>
        @endforelse
    </div>

    <div class="space-y-3" data-test="club-duplicates">
        <x-arena.section-title :meta="__(':count groups', ['count' => $this->clubGroups->count()])">{{ __('Clubs') }}</x-arena.section-title>

        @forelse ($this->clubGroups as $key => $group)
            <x-arena.card class="space-y-3 p-4" wire:key="club-group-{{ $key }}" data-test="duplicate-group">
                <flux:radio.group wire:model="survivors.{{ $key }}" :label="__('Keep this record')">
                    @foreach ($group as $club)
                        <flux:radio class="min-h-11 items-center" :value="(string) $club->id" :label="$club->name.($club->city ? ' ('.$club->city.')' : '').' · '.__(':count competitors', ['count' => $club->competitors_count])" wire:key="club-{{ $club->id }}" />
                    @endforeach
                </flux:radio.group>

                <x-arena.button variant="secondary" icon="arrows-pointing-in" wire:click="mergeClubs('{{ $key }}', [{{ implode(',', $group->modelKeys()) }}])" wire:confirm="{{ __('Merge :count records into the selected one? Their competitors move to it and the others are deleted.', ['count' => $group->count() - 1]) }}" data-test="merge-clubs-button">{{ __('Merge') }}</x-arena.button>
            </x-arena.card>
        @empty
            <x-arena.card class="p-4"><p class="text-sm text-arena-muted">{{ __('No suspected duplicates.') }}</p></x-arena.card>
        @endforelse
    </div>
</section>
