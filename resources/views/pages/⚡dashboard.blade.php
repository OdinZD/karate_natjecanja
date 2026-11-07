<?php

use App\Actions\Timetable\TimetableBuilder;
use App\Actions\Timetable\TimetableRow;
use App\Actions\Timetable\TimetableTatami;
use App\Enums\CompetitionStatus;
use App\Enums\DrawProgress;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Competitor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /**
     * The competition that is under way. Only administrators see competition data on the dashboard.
     */
    #[Computed]
    public function competition(): ?Competition
    {
        if (! Auth::user()->isAdmin()) {
            return null;
        }

        return Competition::query()
            ->where('status', CompetitionStatus::InProgress->value)
            ->orderBy('date')
            ->first();
    }

    /**
     * @return Collection<int, Competition>
     */
    #[Computed]
    public function upcoming(): Collection
    {
        return Competition::query()
            ->whereNotIn('status', [CompetitionStatus::Finished->value, CompetitionStatus::InProgress->value])
            ->whereDate('date', '>=', today())
            ->orderBy('date')
            ->limit(5)
            ->get();
    }

    /**
     * @return array<int, TimetableTatami>
     */
    #[Computed]
    public function table(): array
    {
        return $this->competition ? app(TimetableBuilder::class)->build($this->competition) : [];
    }

    /**
     * The category being played on each tatami: the first running one, otherwise the first one that is drawn
     * but not started. Same rule as NowPlaying, without loading the bouts.
     *
     * @return array<int, Category|null>
     */
    #[Computed]
    public function activeCategories(): array
    {
        return array_map(function (TimetableTatami $block): ?Category {
            $rows = collect($block->rows);

            return ($rows->first(fn (TimetableRow $row) => $row->progress === DrawProgress::Running)
                ?? $rows->first(fn (TimetableRow $row) => $row->progress === DrawProgress::Pending))?->category;
        }, $this->table);
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        if (! $this->competition) {
            return new Collection;
        }

        return app(TimetableBuilder::class)
            ->withProgressCounts($this->competition->categories())
            ->with('tatami')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array{entries: int, competitors: int, clubs: int, bouts: int, played: int, finished: int}
     */
    #[Computed]
    public function stats(): array
    {
        $competition = $this->competition;
        $builder = app(TimetableBuilder::class);

        return [
            'entries' => $competition->entries()->count(),
            'competitors' => $competition->entries()->distinct()->count('entries.competitor_id'),
            'clubs' => Competitor::query()
                ->whereHas('entries.category', fn ($query) => $query->where('competition_id', $competition->id))
                ->distinct()
                ->count('club_id'),
            'bouts' => (int) $this->categories->sum('played_bouts_count') + (int) $this->categories->sum('open_bouts_count'),
            'played' => (int) $this->categories->sum('played_bouts_count'),
            'finished' => $this->categories->filter(fn (Category $category) => $builder->progress($category) === DrawProgress::Finished)->count(),
        ];
    }

    #[Computed]
    public function estimatedEnd(): ?CarbonImmutable
    {
        return collect($this->table)
            ->filter(fn (TimetableTatami $block) => $block->rows !== [])
            ->sortByDesc(fn (TimetableTatami $block) => $block->end->getTimestamp())
            ->first()?->end;
    }
}; ?>

<section class="w-full min-w-0 space-y-5">
    @if (! auth()->user()->isAdmin())
        <h1 class="font-display text-4xl font-black uppercase leading-[.95] tracking-[.01em] text-arena-ink md:text-5xl">{{ __('Dashboard') }}</h1>

        @if (auth()->user()->tatamis()->exists())
            <x-arena.card class="flex flex-wrap items-center justify-between gap-4 p-5">
                <div class="font-display text-2xl font-black uppercase tracking-[.04em]">{{ __('Tatami') }}</div>
                <x-arena.button :href="route('tatami.index')" wire:navigate>{{ __('Open console') }}</x-arena.button>
            </x-arena.card>
        @else
            <x-arena.card variant="dashed" class="p-5">{{ __('You have no tatamis assigned. Ask the administrator to assign you to one.') }}</x-arena.card>
        @endif
    @elseif ($this->competition)
        @php($competition = $this->competition)
        @php($stats = $this->stats)
        @php($hasDraws = $this->categories->contains(fn ($category) => $category->any_bouts_count > 0))
        @php($hasTimetable = collect($this->table)->contains(fn ($block) => $block->rows !== []))

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0 space-y-2">
                <x-arena.status state="live" :label="__('In progress')" />

                <h1 class="break-words font-display text-4xl font-black uppercase leading-[.95] tracking-[.01em] text-arena-ink md:text-5xl">
                    <a href="{{ route('admin.competitions.show', $competition) }}" wire:navigate>{{ $competition->name }}</a>
                </h1>

                <p class="text-sm text-arena-muted">{{ $competition->date->format('d.m.Y.') }} · {{ $competition->venue }}</p>
            </div>

            <div class="flex items-center gap-2">
                <x-arena.button variant="secondary" icon="arrow-up-right" :href="route('public.competitions.show', $competition)" target="_blank">{{ __('Public page') }}<span class="sr-only"> ({{ __('Opens in a new tab') }})</span></x-arena.button>

                @if ($hasDraws || $hasTimetable)
                    <flux:dropdown align="end">
                        <x-arena.button variant="secondary" icon="document-arrow-down">{{ __('Print PDF') }}</x-arena.button>

                        <flux:menu>
                            @if ($hasDraws)
                                <flux:menu.item icon="document-arrow-down" :href="route('admin.competitions.draw.pdf', $competition)" target="_blank">{{ __('All draws (PDF)') }}<span class="sr-only"> ({{ __('Opens in a new tab') }})</span></flux:menu.item>
                            @endif
                            @if ($hasTimetable)
                                <flux:menu.item icon="document-arrow-down" :href="route('admin.competitions.timetable.pdf', $competition)" target="_blank">{{ __('Time table (PDF)') }}<span class="sr-only"> ({{ __('Opens in a new tab') }})</span></flux:menu.item>
                            @endif
                        </flux:menu>
                    </flux:dropdown>
                @endif
            </div>
        </div>

        <x-arena.steps :competition="$competition" current="day" />

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-arena.stat :label="__('Bouts played')" :value="$stats['played']" :of="$stats['bouts']" :progress="$stats['bouts'] > 0 ? $stats['played'] / $stats['bouts'] * 100 : 0" />
            <x-arena.stat :label="__('Categories finished')" :value="$stats['finished']" :of="$this->categories->count()" :progress="$this->categories->count() > 0 ? $stats['finished'] / $this->categories->count() * 100 : 0" />
            <x-arena.stat :label="__('Estimated end')" :value="$this->estimatedEnd?->format('H:i') ?? '—'" />
            <x-arena.stat :label="__('Entries')" :value="$stats['entries']" :hint="__(':competitors competitors · :clubs clubs', ['competitors' => $stats['competitors'], 'clubs' => $stats['clubs']])" />
        </div>

        <x-arena.card tag="section" class="space-y-3 p-4">
            <x-arena.section-title>{{ __('Tatamis live') }}</x-arena.section-title>

            @if (count($this->table) === 0)
                <p class="text-sm text-arena-muted">{{ __('This competition has no tatamis yet.') }}</p>
            @endif

            <div @class(['border border-arena-line', 'hidden' => count($this->table) === 0])>
                @foreach ($this->table as $index => $block)
                    @php($category = $this->activeCategories[$index])
                    @php($percent = $category && ($category->played_bouts_count + $category->open_bouts_count) > 0 ? (int) round($category->played_bouts_count / ($category->played_bouts_count + $category->open_bouts_count) * 100) : 0)

                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-arena-line px-3 py-2.5 last:border-b-0" wire:key="live-tatami-{{ $block->tatami->id }}">
                        <x-arena.tatami :number="$block->tatami->number" />

                        <div class="min-w-0 grow basis-52 space-y-1.5">
                            @if ($category)
                                <div class="truncate text-sm font-bold">{{ $category->name }}</div>
                                <div class="h-1 bg-arena-surface-2" role="progressbar" aria-label="{{ $category->name }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}">
                                    <div class="h-full origin-left animate-arena-bar bg-arena-ao-text" style="width: {{ $percent }}%"></div>
                                </div>
                            @else
                                <div class="text-sm text-arena-muted">{{ __('Nothing is being played on this tatami right now.') }}</div>
                            @endif

                            @if ($block->tatami->operators->isEmpty())
                                <x-arena.status state="warn" :label="__('No operators')" />
                            @endif
                        </div>

                        @if ($category)
                            <x-arena.status state="live" :label="__('In progress')" />
                        @else
                            <x-arena.status state="none" :label="__('Break')" />
                        @endif

                        <x-arena.button variant="secondary" :href="route('tatami.console', $block->tatami)" wire:navigate>{{ __('Open console') }}</x-arena.button>
                    </div>
                @endforeach
            </div>
        </x-arena.card>

        <section
            class="space-y-3"
            x-data="{
                q: '',
                f: '',
                match(el) {
                    const d = el.dataset
                    const q = this.q.trim().toLowerCase()

                    if (q !== '' && ! d.search.includes(q)) return false

                    return this.f === '' || d.kind === this.f
                },
            }"
        >
            <x-arena.section-title :meta="$this->categories->count()">{{ __('Categories') }}</x-arena.section-title>

            <flux:input x-model="q" icon="magnifying-glass" type="search" :placeholder="__('Search categories')" :aria-label="__('Search categories')" />

            <div role="radiogroup" aria-label="{{ __('Filter categories') }}" class="flex flex-wrap gap-2">
                @foreach (['' => __('All'), 'kata' => __('Kata'), 'kumite' => __('Kumite')] as $value => $label)
                    <x-arena.chip name="dashboard-category-filter" :value="$value" :label="$label" x-model="f" />
                @endforeach
            </div>

            <x-arena.card class="max-h-[28rem] overflow-auto" tabindex="0" role="region" aria-label="{{ __('Categories') }}">
                <table class="w-full text-start text-sm">
                    <thead>
                        <tr class="border-b border-arena-line text-xs uppercase tracking-[.1em] text-arena-muted">
                            <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Category') }}</th>
                            <th scope="col" class="px-4 py-3 text-end font-semibold">{{ __('Entries') }}</th>
                            <th scope="col" class="px-4 py-3 font-semibold">{{ __('Tatami') }}</th>
                            <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Draw') }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($this->categories as $category)
                            @php($progress = app(TimetableBuilder::class)->progress($category))

                            <tr
                                class="border-b border-arena-line last:border-b-0"
                                data-search="{{ Str::lower($category->name) }}"
                                data-kind="{{ $category->discipline->value }}"
                                wire:key="dashboard-category-{{ $category->id }}"
                                x-bind:hidden="! match($el)"
                            >
                                <td class="px-4 py-2 font-bold">{{ $category->name }}</td>
                                <td @class(['px-4 py-2 text-end font-mono tabular-nums', 'font-bold text-arena-warn' => $category->entries_count === 0])>{{ $category->entries_count }}</td>
                                <td class="px-4 py-2">
                                    @if ($category->tatami)
                                        <x-arena.tatami :number="$category->tatami->number" size="sm" />
                                    @else
                                        <span class="text-arena-muted">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2"><x-public.progress :progress="$progress" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-arena.card>
        </section>
    @else
        <h1 class="font-display text-4xl font-black uppercase leading-[.95] tracking-[.01em] text-arena-ink md:text-5xl">{{ __('Dashboard') }}</h1>

        <x-arena.empty :title="__('No active competition')" :text="__('A competition becomes active when its status is In progress.')">
            <x-slot:action>
                <x-arena.button icon="plus" :href="route('admin.competitions.index')" wire:navigate>{{ __('New competition') }}</x-arena.button>
            </x-slot:action>
        </x-arena.empty>

        @if ($this->upcoming->isNotEmpty())
            <div class="space-y-2">
                <x-arena.section-title>{{ __('Upcoming competitions') }}</x-arena.section-title>

                <x-arena.card>
                    @foreach ($this->upcoming as $upcoming)
                        <a href="{{ route('admin.competitions.show', $upcoming) }}" wire:navigate class="flex min-h-14 flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b border-arena-line px-4 py-2 last:border-b-0" wire:key="upcoming-{{ $upcoming->id }}">
                            <span class="font-bold">{{ $upcoming->name }}</span>
                            <span class="font-mono text-sm tabular-nums text-arena-muted">{{ $upcoming->date->format('d.m.Y.') }}</span>
                        </a>
                    @endforeach
                </x-arena.card>
            </div>
        @endif
    @endif
</section>
