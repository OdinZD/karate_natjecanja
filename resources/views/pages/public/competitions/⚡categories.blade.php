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
        <x-arena.card variant="dashed" class="p-5">{{ __('Categories have not been published yet.') }}</x-arena.card>
    @else
        @php($groups = $this->categories->groupBy(fn ($category) => $category->birth_year_from.'–'.$category->birth_year_to))

        <div
            class="space-y-4"
            x-data="{
                q: '',
                f: '',
                match(el) {
                    const d = el.dataset
                    const q = this.q.trim().toLowerCase()

                    if (q !== '' && ! d.search.includes(q)) return false
                    if (this.f === '') return true
                    if (this.f === 'live') return d.live === '1'
                    if (this.f === 'kata' || this.f === 'kumite') return d.kind === this.f

                    return d.gender === this.f
                },
            }"
        >
            <flux:input x-model="q" icon="magnifying-glass" type="search" :placeholder="__('Search categories')" :aria-label="__('Search categories')" />

            <div role="radiogroup" aria-label="{{ __('Filter categories') }}" class="flex flex-wrap gap-2">
                @foreach (['' => [__('All'), null], 'kata' => [__('Kata'), null], 'kumite' => [__('Kumite'), null], 'female' => [__('F'), __('Girls and women')], 'male' => [__('M'), __('Boys and men')], 'live' => [__('Live'), null]] as $value => [$label, $hint])
                    <x-arena.chip name="category-filter" :value="$value" :label="$label" :hint="$hint" x-model="f" />
                @endforeach
            </div>

            @foreach ($groups as $years => $items)
                @php($first = $items->first())

                <details open class="border border-arena-line bg-arena-surface" x-effect="q; f; $el.hidden = ! [...$el.querySelectorAll('[data-cat]')].some((row) => match(row))">
                    <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 bg-arena-bg-2 px-3 [&::-webkit-details-marker]:hidden">
                        <span class="font-display text-xl font-extrabold uppercase tracking-[.04em]">{{ str_contains($first->name, ' - ') ? \Illuminate\Support\Str::before($first->name, ' - ') : $years }}</span>
                        <span class="font-mono text-xs text-arena-muted tabular-nums">{{ $years }} · {{ $items->count() }}</span>
                    </summary>

                    @foreach ($items as $category)
                        @php($progress = app(TimetableBuilder::class)->progress($category))

                        <div
                            class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-arena-line px-3 py-2.5"
                            data-test="category-row"
                            data-cat
                            data-search="{{ \Illuminate\Support\Str::lower($category->name) }}"
                            data-kind="{{ $category->discipline->value }}"
                            data-gender="{{ $category->gender?->value }}"
                            data-live="{{ $progress === DrawProgress::Running ? '1' : '0' }}"
                            wire:key="category-{{ $category->id }}"
                            x-effect="q; f; $el.hidden = ! match($el)"
                        >
                            <div class="min-w-0 grow basis-64">
                                @if ($progress !== DrawProgress::NoDraw)
                                    <a href="{{ route('public.competitions.bracket', [$competition, $category]) }}" wire:navigate class="inline-flex min-h-11 items-center text-[15px] font-bold underline-offset-4 hover:underline">{{ $category->name }}</a>
                                @else
                                    <span class="text-[15px] font-bold">{{ $category->name }}</span>
                                @endif

                                <div class="mt-0.5 flex flex-wrap gap-x-4 gap-y-1 text-xs text-arena-muted">
                                    <span>{{ __(Str::headline($category->discipline->value)) }}</span>
                                    <span>{{ $category->gender ? __(Str::headline($category->gender->value)) : __('Mixed') }}</span>
                                    <span class="ps-num">{{ __('Born :from to :to', ['from' => $category->birth_year_from, 'to' => $category->birth_year_to]) }}</span>
                                    <span class="ps-num">{{ $category->weightLabel() ?? '—' }}</span>
                                </div>
                            </div>

                            <div class="flex items-center gap-4 text-sm">
                                <span class="font-mono text-xs tabular-nums" data-stat="entries">{{ __(':count entries', ['count' => $category->entries_count]) }}</span>
                                @if ($category->tatami)
                                    <x-arena.tatami :number="$category->tatami->number" size="sm" />
                                @else
                                    <span class="text-arena-muted">—</span>
                                @endif
                                <x-public.progress :progress="$progress" />
                            </div>
                        </div>
                    @endforeach
                </details>
            @endforeach
        </div>
    @endif
</section>
