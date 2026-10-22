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

<section class="w-full">
    <div class="mb-6">
        <flux:link :href="route('admin.competitions.draw', $competition)" wire:navigate class="text-sm">
            &larr; {{ __('Draw') }}
        </flux:link>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <flux:heading size="xl" level="1">{{ $category->name }}</flux:heading>

            @if ($this->bouts->isNotEmpty())
                <flux:button icon="document-arrow-down" :href="route('admin.competitions.bracket.pdf', [$competition, $category])" target="_blank" data-test="bracket-pdf-link">
                    {{ __('Print PDF') }}
                </flux:button>
            @endif
        </div>
    </div>

    <x-bracket :category="$category" :bouts="$this->bouts" :placements="$this->placements" />
</section>
