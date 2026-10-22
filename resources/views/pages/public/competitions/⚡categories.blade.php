<?php

use App\Actions\Timetable\TimetableBuilder;
use App\Enums\DrawProgress;
use App\Models\Category;
use App\Models\Competition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::public'), Title('Categories')] class extends Component {
    public Competition $competition;

    public function mount(Competition $publicCompetition): void
    {
        $this->competition = $publicCompetition;
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return app(TimetableBuilder::class)
            ->withProgressCounts($this->competition->categories())
            ->with('tatami')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }
}; ?>

<section class="w-full">
    <x-public.competition-header :competition="$competition" active="categories" />

    @if ($this->categories->isEmpty())
        <p class="ps-panel p-5 ps-muted">{{ __('Categories have not been published yet.') }}</p>
    @else
        <div class="ps-panel overflow-hidden">
            @foreach ($this->categories as $category)
                @php($progress = app(TimetableBuilder::class)->progress($category))

                <div class="ps-row flex-wrap" data-test="category-row" wire:key="category-{{ $category->id }}">
                    <div class="min-w-0 grow basis-64">
                        @if ($progress !== DrawProgress::NoDraw)
                            <a href="{{ route('public.competitions.bracket', [$competition, $category]) }}" wire:navigate class="ps-link ps-nav-link text-lg">{{ $category->name }}</a>
                        @else
                            <span class="text-lg font-semibold">{{ $category->name }}</span>
                        @endif

                        <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm ps-muted">
                            <span>{{ __(Str::headline($category->discipline->value)) }}</span>
                            <span>{{ $category->gender ? __(Str::headline($category->gender->value)) : __('Mixed') }}</span>
                            <span class="ps-num">{{ __('Born :from to :to', ['from' => $category->birth_year_from, 'to' => $category->birth_year_to]) }}</span>
                            <span class="ps-num">{{ $category->weightLabel() ?? '—' }}</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-5 text-sm">
                        <span class="ps-num" data-stat="entries">{{ __(':count entries', ['count' => $category->entries_count]) }}</span>
                        <span class="ps-muted">{{ $category->tatami ? __('Tatami :number', ['number' => $category->tatami->number]) : '—' }}</span>
                        <x-public.progress :progress="$progress" />
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</section>
