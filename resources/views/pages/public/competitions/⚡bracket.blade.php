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

    <div wire:poll.10s.visible data-test="live-region" class="space-y-6">
        <div class="space-y-3">
            <a href="{{ route('public.competitions.draw', $competition) }}" wire:navigate class="inline-flex min-h-11 items-center gap-1.5 text-[13px] text-arena-muted">
                <flux:icon.chevron-left class="size-4" />{{ __('All categories') }}
            </a>

            <h2 class="font-display text-[34px] font-black uppercase leading-[.95] tracking-[.01em] text-arena-ink md:text-5xl">{{ $category->name }}</h2>

            <div class="flex flex-wrap gap-1.5 font-mono text-xs">
                <span class="border border-arena-line-strong px-2.5 py-1">{{ __(Str::headline($category->discipline->value)) }}</span>
                <span class="border border-arena-line-strong px-2.5 py-1">{{ $category->gender ? __(Str::headline($category->gender->value)) : __('Mixed') }}</span>
                <span class="border border-arena-line-strong px-2.5 py-1 tabular-nums">{{ __('Born :from to :to', ['from' => $category->birth_year_from, 'to' => $category->birth_year_to]) }}</span>
                @if ($category->weightLabel())
                    <span class="border border-arena-line-strong px-2.5 py-1 tabular-nums">{{ $category->weightLabel() }}</span>
                @endif
            </div>
        </div>

        <div>
            @if ($this->bouts->isEmpty())
                <x-arena.card variant="dashed" class="p-5">{{ __('The draw has not been published yet.') }}</x-arena.card>
            @else
                <x-bracket :category="$category" :bouts="$this->bouts" :placements="$this->placements" :current-bout-id="$this->currentBoutId" />
            @endif
        </div>

        <x-public.updates />
    </div>
</section>
