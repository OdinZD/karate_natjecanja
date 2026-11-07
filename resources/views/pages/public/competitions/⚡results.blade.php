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
        <div role="radiogroup" aria-label="{{ __('Discipline') }}" class="flex flex-wrap gap-2">
            @foreach (['' => __('All disciplines'), ...collect(Discipline::cases())->mapWithKeys(fn ($case) => [$case->value => __(Str::headline($case->value))])->all()] as $value => $label)
                <x-arena.chip name="discipline" :value="$value" :label="$label" wire:model.live="discipline" />
            @endforeach
        </div>

        @if ($this->results->isEmpty())
            <x-arena.card variant="dashed" class="p-5">{{ __('There are no results yet.') }}</x-arena.card>
        @else
            <div class="grid gap-3 lg:grid-cols-2">
                @foreach ($this->results as $result)
                    <x-arena.card :variant="$result->complete ? 'default' : 'dashed'" data-test="result-card" wire:key="result-{{ $result->category->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-arena-line px-4 py-3">
                            <div>
                                <h2 class="text-sm font-bold text-arena-ink">{{ $result->category->name }}</h2>
                                <div class="text-xs text-arena-muted">{{ __(Str::headline($result->category->discipline->value)) }}</div>
                            </div>
                            <x-arena.status :state="$result->complete ? 'done' : 'live'" :label="$result->complete ? __('Finished') : __('In progress')" />
                        </div>

                        @foreach ([1 => $result->first, 2 => $result->second, 3 => $result->third] as $place => $entries)
                            @continue($entries === [])

                            <div class="flex items-start gap-3 border-b border-arena-line px-4 py-3 text-arena-ink last:border-b-0" data-test="podium-{{ $place }}">
                                <x-arena.medal :place="$place" class="animate-arena-medal" />

                                <div class="space-y-1">
                                    @foreach ($entries as $entry)
                                        <div>
                                            <span class="text-[15px] font-bold">{{ $entry->competitor->last_name }} {{ $entry->competitor->first_name }}</span>
                                            <span class="block text-xs text-arena-muted">{{ $entry->competitor->club->name }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach

                        @if (! $result->complete && $result->first === [])
                            <p class="px-4 py-3 text-sm text-arena-muted">{{ __('The final has not been played yet.') }}</p>
                        @endif
                    </x-arena.card>
                @endforeach
            </div>
        @endif

        <x-public.updates />
    </div>
</section>
