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
            'entries' => $this->competition->entries()->count(),
            'competitors' => $this->competition->entries()->distinct()->count('entries.competitor_id'),
            'clubs' => Competitor::query()
                ->whereHas('entries.category', fn ($query) => $query->where('competition_id', $competitionId))
                ->distinct()
                ->count('club_id'),
            'tatamis' => $this->competition->tatamis()->count(),
        ];
    }
}; ?>

<section class="w-full">
    <x-public.competition-header :competition="$competition" active="show" />

    @if ($competition->status === CompetitionStatus::InProgress)
        <a href="{{ route('public.competitions.timetable', $competition) }}" wire:navigate class="ps-panel mb-8 flex items-center justify-between gap-4 p-5" data-test="follow-live">
            <span>
                <span class="ps-subtitle block">{{ __('The competition is under way') }}</span>
                <span class="ps-muted">{{ __('See what is on each tatami right now.') }}</span>
            </span>
            <x-public.status tone="live">{{ __('Follow live') }}</x-public.status>
        </a>
    @endif

    <div class="grid gap-8 md:grid-cols-2">
        <div>
            <h2 class="ps-subtitle mb-3">{{ __('When and where') }}</h2>

            <dl class="ps-panel overflow-hidden">
                <div class="ps-row justify-between"><dt class="ps-muted">{{ __('Date') }}</dt><dd class="ps-num font-medium">{{ $competition->date->format('d.m.Y.') }}</dd></div>
                <div class="ps-row justify-between"><dt class="ps-muted">{{ __('Start') }}</dt><dd class="ps-num font-medium">{{ substr($competition->start_time, 0, 5) }}</dd></div>
                <div class="ps-row justify-between"><dt class="ps-muted">{{ __('Venue') }}</dt><dd class="font-medium text-end">{{ $competition->venue }}</dd></div>
                @if ($competition->organizer)
                    <div class="ps-row justify-between"><dt class="ps-muted">{{ __('Organizer') }}</dt><dd class="font-medium text-end">{{ $competition->organizer }}</dd></div>
                @endif
                @if ($competition->registration_deadline)
                    <div class="ps-row justify-between"><dt class="ps-muted">{{ __('Registration deadline') }}</dt><dd class="ps-num font-medium">{{ $competition->registration_deadline->format('d.m.Y. H:i') }}</dd></div>
                @endif
            </dl>
        </div>

        <div>
            <h2 class="ps-subtitle mb-3">{{ __('In numbers') }}</h2>

            <dl class="ps-panel overflow-hidden" data-test="stats">
                <div class="ps-row justify-between"><dt class="ps-muted">{{ __('Categories') }}</dt><dd class="ps-num font-medium" data-stat="categories">{{ $this->stats['categories'] }}</dd></div>
                <div class="ps-row justify-between"><dt class="ps-muted">{{ __('Entries') }}</dt><dd class="ps-num font-medium" data-stat="entries">{{ $this->stats['entries'] }}</dd></div>
                <div class="ps-row justify-between"><dt class="ps-muted">{{ __('Competitors') }}</dt><dd class="ps-num font-medium" data-stat="competitors">{{ $this->stats['competitors'] }}</dd></div>
                <div class="ps-row justify-between"><dt class="ps-muted">{{ __('Clubs') }}</dt><dd class="ps-num font-medium" data-stat="clubs">{{ $this->stats['clubs'] }}</dd></div>
                <div class="ps-row justify-between"><dt class="ps-muted">{{ __('Tatamis') }}</dt><dd class="ps-num font-medium" data-stat="tatamis">{{ $this->stats['tatamis'] }}</dd></div>
            </dl>
        </div>
    </div>

    <div class="mt-10 flex flex-wrap gap-x-8 gap-y-3 text-lg">
        <a href="{{ route('public.competitions.draw', $competition) }}" wire:navigate class="ps-link ps-nav-link">{{ __('Draw') }}</a>
        <a href="{{ route('public.competitions.timetable', $competition) }}" wire:navigate class="ps-link ps-nav-link">{{ __('Time table') }}</a>
        <a href="{{ route('public.competitions.results', $competition) }}" wire:navigate class="ps-link ps-nav-link">{{ __('Results') }}</a>
    </div>
</section>
