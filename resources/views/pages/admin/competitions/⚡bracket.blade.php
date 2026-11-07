<?php

use App\Models\Category;
use App\Models\Competition;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Bracket')] class extends Component {
    public Competition $competition;

    public Category $category;

    public function mount(Competition $competition, Category $category): void
    {
        abort_unless($category->competition_id === $competition->id, 404);

        $this->competition = $competition;
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
}; ?>

<section class="w-full min-w-0 space-y-5">
    <div class="space-y-1">
        <a href="{{ route('admin.competitions.draw', $competition) }}" wire:navigate class="inline-flex min-h-11 items-center gap-1.5 text-[13px] text-arena-muted">
            <flux:icon.chevron-left class="size-4" />{{ __('Draw') }}
        </a>
        <h1 class="font-display text-4xl font-black uppercase leading-[.95] tracking-[.01em] text-arena-ink md:text-5xl">{{ $category->name }}</h1>
    </div>

    <x-arena.steps :competition="$competition" current="draw" />

    @if ($this->bouts->isNotEmpty())
        <div>
            <x-arena.button variant="secondary" icon="document-arrow-down" :href="route('admin.competitions.bracket.pdf', [$competition, $category])" target="_blank" data-test="bracket-pdf-link">{{ __('Print PDF') }}<span class="sr-only"> ({{ __('Opens in a new tab') }})</span></x-arena.button>
        </div>
    @endif

    <x-bracket :category="$category" :bouts="$this->bouts" :placements="$this->placements" />
</section>
