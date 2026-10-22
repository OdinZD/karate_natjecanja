<?php

use App\Actions\Results\CategoryResults;
use App\Enums\Discipline;
use App\Models\Competition;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::public'), Title('Results')] class extends Component {
    public Competition $competition;

    public string $discipline = '';

    public function mount(Competition $publicCompetition): void
    {
        $this->competition = $publicCompetition;
    }

    /**
     * @return Collection<int, \App\Actions\Results\CategoryResultRow>
     */
    #[Computed]
    public function results(): Collection
    {
        return app(CategoryResults::class)->handle($this->competition, Discipline::tryFrom($this->discipline));
    }
}; ?>

<section class="w-full">
    <x-public.competition-header :competition="$competition" active="results" />

    <div wire:poll.10s.visible data-test="live-region" class="space-y-6">
        <div class="max-w-xs">
            <flux:select wire:model.live="discipline" :aria-label="__('Discipline')">
                <flux:select.option value="">{{ __('All disciplines') }}</flux:select.option>
                @foreach (Discipline::cases() as $case)
                    <flux:select.option :value="$case->value">{{ __(Str::headline($case->value)) }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @if ($this->results->isEmpty())
            <p class="ps-panel p-5 ps-muted">{{ __('There are no results yet.') }}</p>
        @else
            <div class="grid gap-6 lg:grid-cols-2">
                @foreach ($this->results as $result)
                    <div class="ps-panel overflow-hidden" data-test="result-card" wire:key="result-{{ $result->category->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-(--ps-line) px-4 py-3">
                            <div>
                                <h2 class="ps-subtitle">{{ $result->category->name }}</h2>
                                <div class="text-sm ps-muted">{{ __(Str::headline($result->category->discipline->value)) }}</div>
                            </div>
                            <x-public.status :tone="$result->complete ? 'done' : 'live'">{{ $result->complete ? __('Finished') : __('In progress') }}</x-public.status>
                        </div>

                        @foreach ([1 => $result->first, 2 => $result->second, 3 => $result->third] as $place => $entries)
                            @continue($entries === [])

                            <div class="ps-row items-start" data-test="podium-{{ $place }}">
                                <span class="ps-medal ps-medal-{{ $place }}" aria-hidden="true">{{ $place }}</span>
                                <span class="sr-only">{{ match ($place) { 1 => __('1st place'), 2 => __('2nd place'), default => __('3rd place') } }}</span>

                                <div class="space-y-1">
                                    @foreach ($entries as $entry)
                                        <div>
                                            <span class="text-lg font-semibold">{{ $entry->competitor->last_name }} {{ $entry->competitor->first_name }}</span>
                                            <span class="block text-sm ps-muted">{{ $entry->competitor->club->name }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach

                        @if (! $result->complete && $result->first === [])
                            <p class="px-4 py-3 text-sm ps-muted">{{ __('The final has not been played yet.') }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        <x-public.updates />
    </div>
</section>
