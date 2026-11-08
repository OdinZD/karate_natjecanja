<?php

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use App\Models\Competitor;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::public'), Title('Details')] class extends Component {
    public Competition $competition;

    public function mount(Competition $publicCompetition): void
    {
        $this->competition = $publicCompetition;
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function stats(): array
    {
        $competitionId = $this->competition->id;

        return [
            'categories' => $this->competition->categories()->count(),
            'entries' => $this->competition->entries()->present()->count(),
            'competitors' => $this->competition->entries()->present()->distinct()->count('entries.competitor_id'),
            'clubs' => Competitor::query()
                ->whereHas('entries', fn ($entries) => $entries->present()->whereHas('category', fn ($query) => $query->where('competition_id', $competitionId)))
                ->distinct()
                ->count('club_id'),
            'tatamis' => $this->competition->tatamis()->count(),
        ];
    }
}; ?>

<section class="w-full">
    <x-public.competition-header :competition="$competition" active="show" />

    @if ($competition->status === CompetitionStatus::InProgress)
        <a href="{{ route('public.competitions.timetable', $competition) }}" wire:navigate class="mb-8 flex min-h-16 items-center justify-between gap-4 border-2 border-arena-ink bg-arena-volt p-4 text-(--arena-live-ink) dark:border-arena-volt" data-test="follow-live">
            <span>
                <span class="block font-display text-2xl font-black uppercase leading-none tracking-[.02em]">{{ __('The competition is under way') }}</span>
                <span class="mt-1 block text-sm">{{ __('See what is on each tatami right now.') }}</span>
            </span>
            <x-arena.status state="live" :label="__('Follow live')" />
        </a>
    @endif

    <div class="grid gap-8 md:grid-cols-2">
        <div>
            <x-arena.section-title class="mb-3">{{ __('When and where') }}</x-arena.section-title>

            <x-arena.card tag="dl">
                @php
                    $facts = array_filter([
                        __('Date') => ['value' => $competition->date->format('d.m.Y.'), 'mono' => true],
                        __('Start') => ['value' => substr($competition->start_time, 0, 5), 'mono' => true],
                        __('Venue') => ['value' => $competition->venue, 'mono' => false],
                        __('Organizer') => $competition->organizer ? ['value' => $competition->organizer, 'mono' => false] : null,
                        __('Registration deadline') => $competition->registration_deadline ? ['value' => $competition->registration_deadline->format('d.m.Y. H:i'), 'mono' => true] : null,
                    ]);
                @endphp

                @foreach ($facts as $term => $fact)
                    <div class="flex min-h-12 items-center justify-between gap-4 border-b border-arena-line px-4 py-2 last:border-b-0">
                        <dt class="text-sm text-arena-muted">{{ $term }}</dt>
                        <dd @class(['text-end text-sm font-semibold', 'font-mono tabular-nums' => $fact['mono']])>{{ $fact['value'] }}</dd>
                    </div>
                @endforeach
            </x-arena.card>
        </div>

        <div>
            <x-arena.section-title class="mb-3">{{ __('In numbers') }}</x-arena.section-title>

            <dl class="grid grid-cols-2 gap-3" data-test="stats">
                @foreach ([
                    'categories' => __('Categories'),
                    'entries' => __('Entries'),
                    'competitors' => __('Competitors'),
                    'clubs' => __('Clubs'),
                    'tatamis' => __('Tatamis'),
                ] as $stat => $label)
                    <div class="flex flex-col gap-1 border border-arena-line bg-arena-surface p-4">
                        <dt class="text-xs uppercase tracking-[.1em] text-arena-muted">{{ $label }}</dt>
                        <dd class="animate-arena-pop font-display text-[44px] font-black leading-none tabular-nums text-arena-ink" data-stat="{{ $stat }}">{{ $this->stats[$stat] }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </div>

    <div class="mt-10 grid gap-3 sm:grid-cols-3">
        @foreach (['draw' => __('Draw'), 'timetable' => __('Time table'), 'results' => __('Results')] as $key => $label)
            <a href="{{ route('public.competitions.'.$key, $competition) }}" wire:navigate class="flex min-h-16 items-center justify-between gap-3 border border-arena-line bg-arena-surface px-4 font-display text-2xl font-black uppercase tracking-[.03em] text-arena-ink">
                {{ $label }}
                <flux:icon.arrow-right class="size-6" />
            </a>
        @endforeach
    </div>
</section>
