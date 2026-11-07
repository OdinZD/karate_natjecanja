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
                <x-arena.section-title class="mb-3">{{ __('Being played now') }}</x-arena.section-title>

                <div class="grid gap-2">
                    @foreach ($live as $item)
                        <a href="{{ route('public.competitions.bracket', [$competition, $item['category']]) }}" wire:navigate class="arena-sweep flex min-h-14 items-center justify-between gap-3 border border-l-4 border-arena-line border-l-arena-ink bg-arena-surface px-3 py-2 dark:border-l-arena-volt" wire:key="live-{{ $item['category']->id }}">
                            <span class="text-[15px] font-bold">{{ $item['category']->name }}</span>
                            <x-public.progress :progress="$item['progress']" />
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($this->categories->isEmpty())
            <x-arena.card variant="dashed" class="p-5">{{ __('Categories have not been published yet.') }}</x-arena.card>
        @else
            <div x-data="{ discipline: '' }">
                <x-arena.section-title class="mb-3">{{ __('All categories') }}</x-arena.section-title>

                <div wire:ignore role="radiogroup" aria-label="{{ __('Discipline') }}" class="mb-3 flex flex-wrap gap-2">
                    @foreach (['' => __('All disciplines'), 'kata' => __('Kata'), 'kumite' => __('Kumite')] as $value => $label)
                        <x-arena.chip name="draw-discipline" :value="$value" :label="$label" x-model="discipline" />
                    @endforeach
                </div>

                <x-arena.card>
                    @foreach ($this->categories as $item)
                        @php($category = $item['category'])

                        <div
                            class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-arena-line px-3 py-2.5 last:border-b-0"
                            data-test="category-row"
                            wire:key="category-{{ $category->id }}"
                            x-show="discipline === '' || discipline === '{{ $category->discipline->value }}'"
                        >
                            <div class="min-w-0 grow basis-52">
                                @if ($item['progress'] !== DrawProgress::NoDraw)
                                    <a href="{{ route('public.competitions.bracket', [$competition, $category]) }}" wire:navigate class="inline-flex min-h-11 items-center text-[15px] font-bold underline-offset-4 hover:underline">{{ $category->name }}</a>
                                @else
                                    <span class="text-[15px] font-bold">{{ $category->name }}</span>
                                @endif
                                <div class="text-xs text-arena-muted">{{ __(Str::headline($category->discipline->value)) }}</div>
                            </div>

                            <div class="flex items-center gap-4 text-sm">
                                <span class="font-mono text-xs tabular-nums">{{ __(':count entries', ['count' => $category->entries_count]) }}</span>
                                <x-public.progress :progress="$item['progress']" />
                            </div>
                        </div>
                    @endforeach
                </x-arena.card>
            </div>
        @endif

        <x-public.updates />
    </div>
</section>
