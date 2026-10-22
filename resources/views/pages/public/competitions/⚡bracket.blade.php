<?php

use App\Actions\Timetable\BoutOrder;
use App\Enums\BoutBracket;
use App\Enums\BoutDecision;
use App\Enums\BoutStatus;
use App\Models\Category;
use App\Models\Competition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::public'), Title('Bracket')] class extends Component {
    public Competition $competition;

    public Category $category;

    public function mount(Competition $publicCompetition, Category $category): void
    {
        abort_unless($category->competition_id === $publicCompetition->id, 404);

        $this->competition = $publicCompetition;
        $this->category = $category;
    }

    /**
     * @return Collection<int, \App\Models\Bout>
     */
    #[Computed]
    public function bouts(): Collection
    {
        return $this->category->bouts()
            ->with(['aka.competitor.club', 'ao.competitor.club', 'winner'])
            ->orderBy('round')
            ->orderBy('position')
            ->get();
    }

    /**
     * @return Collection<int, \App\Models\Placement>
     */
    #[Computed]
    public function placements(): Collection
    {
        return $this->category->placements()->with('entry.competitor.club')->orderBy('place')->get();
    }

    #[Computed]
    public function currentBoutId(): ?int
    {
        $bouts = $this->bouts;
        $finalRound = (int) $bouts->where('bracket', BoutBracket::Main)->max('round');

        $ready = $bouts->filter(fn ($bout) => $bout->status === BoutStatus::Ready && $bout->decision !== BoutDecision::Bye);

        return app(BoutOrder::class)->sort($ready, $finalRound)->first()?->id;
    }
}; ?>

<section class="w-full">
    <x-public.competition-header :competition="$competition" active="draw" />

    <div wire:poll.10s.visible data-test="live-region">
        <a href="{{ route('public.competitions.draw', $competition) }}" wire:navigate class="ps-link ps-nav-link text-sm">{{ __('All categories') }}</a>

        <h2 class="ps-title mt-2 text-4xl md:text-5xl">{{ $category->name }}</h2>
        <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-base ps-muted">
            <span>{{ __(Str::headline($category->discipline->value)) }}</span>
            <span>{{ $category->gender ? __(Str::headline($category->gender->value)) : __('Mixed') }}</span>
            <span class="ps-num">{{ __('Born :from to :to', ['from' => $category->birth_year_from, 'to' => $category->birth_year_to]) }}</span>
            @if ($category->weightLabel())
                <span class="ps-num">{{ $category->weightLabel() }}</span>
            @endif
        </div>

        <div class="mt-8">
            @if ($this->bouts->isEmpty())
                <p class="ps-panel p-5 ps-muted">{{ __('The draw has not been published yet.') }}</p>
            @else
                <x-bracket :category="$category" :bouts="$this->bouts" :placements="$this->placements" :current-bout-id="$this->currentBoutId" />
            @endif
        </div>

        <x-public.updates class="mt-8" />
    </div>
</section>
