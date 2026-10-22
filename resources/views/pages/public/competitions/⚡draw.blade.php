<?php

use App\Actions\Timetable\TimetableBuilder;
use App\Enums\DrawProgress;
use App\Models\Category;
use App\Models\Competition;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::public'), Title('Draw')] class extends Component {
    public Competition $competition;

    public function mount(Competition $publicCompetition): void
    {
        $this->competition = $publicCompetition;
    }

    /**
     * @return Collection<int, array{category: Category, progress: DrawProgress}>
     */
    #[Computed]
    public function categories(): Collection
    {
        $builder = app(TimetableBuilder::class);

        return $builder->withProgressCounts($this->competition->categories())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category) => ['category' => $category, 'progress' => $builder->progress($category)]);
    }
}; ?>

<section class="w-full">
    <x-public.competition-header :competition="$competition" active="draw" />

    <div wire:poll.10s.visible data-test="live-region" class="space-y-8">
        @php($live = $this->categories->where('progress', DrawProgress::Running))

        @if ($live->isNotEmpty())
            <div data-test="live-categories">
                <h2 class="ps-subtitle mb-3">{{ __('Being played now') }}</h2>

                <div class="ps-panel overflow-hidden">
                    @foreach ($live as $item)
                        <a href="{{ route('public.competitions.bracket', [$competition, $item['category']]) }}" wire:navigate class="ps-row ps-row-live justify-between" wire:key="live-{{ $item['category']->id }}">
                            <span class="text-lg font-semibold">{{ $item['category']->name }}</span>
                            <x-public.progress :progress="$item['progress']" />
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($this->categories->isEmpty())
            <p class="ps-panel p-5 ps-muted">{{ __('Categories have not been published yet.') }}</p>
        @else
            <div>
                <h2 class="ps-subtitle mb-3">{{ __('All categories') }}</h2>

                <div class="ps-panel overflow-hidden">
                    @foreach ($this->categories as $item)
                        @php($category = $item['category'])

                        <div class="ps-row flex-wrap" data-test="category-row" wire:key="category-{{ $category->id }}">
                            <div class="min-w-0 grow basis-56">
                                @if ($item['progress'] !== DrawProgress::NoDraw)
                                    <a href="{{ route('public.competitions.bracket', [$competition, $category]) }}" wire:navigate class="ps-link ps-nav-link text-lg">{{ $category->name }}</a>
                                @else
                                    <span class="text-lg font-semibold">{{ $category->name }}</span>
                                @endif
                                <div class="text-sm ps-muted">{{ __(Str::headline($category->discipline->value)) }}</div>
                            </div>

                            <div class="flex items-center gap-5 text-sm">
                                <span class="ps-num">{{ __(':count entries', ['count' => $category->entries_count]) }}</span>
                                <x-public.progress :progress="$item['progress']" />
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <x-public.updates />
    </div>
</section>
