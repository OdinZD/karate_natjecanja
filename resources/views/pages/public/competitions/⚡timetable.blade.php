<?php

use App\Actions\Timetable\NowPlaying;
use App\Actions\Timetable\NowPlayingRow;
use App\Actions\Timetable\TimetableBuilder;
use App\Actions\Timetable\TimetableTatami;
use App\Enums\DrawProgress;
use App\Models\Competition;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::public'), Title('Time table')] class extends Component {
    public Competition $competition;

    public function mount(Competition $publicCompetition): void
    {
        $this->competition = $publicCompetition;
    }

    /**
     * @return array<int, TimetableTatami>
     */
    #[Computed]
    public function table(): array
    {
        return app(TimetableBuilder::class)->build($this->competition);
    }

    /**
     * @return array<int, NowPlayingRow>
     */
    #[Computed]
    public function nowPlaying(): array
    {
        return app(NowPlaying::class)->handle($this->table);
    }

    #[Computed]
    public function published(): bool
    {
        return collect($this->table)->contains(fn (TimetableTatami $block) => $block->rows !== []);
    }
}; ?>

<section class="w-full">
    <x-public.competition-header :competition="$competition" active="timetable" />

    <div wire:poll.10s.visible data-test="live-region" class="space-y-10">
        @if (! $this->published)
            <p class="ps-panel p-5 ps-muted">{{ __('The time table has not been published yet.') }}</p>
        @else
            @foreach ($this->table as $index => $block)
                @continue($block->rows === [])

                @php($playing = $this->nowPlaying[$index])

                <div class="space-y-4" wire:key="tatami-{{ $block->tatami->id }}">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-6">
                        <h2 class="ps-title text-4xl">
                            {{ __('Tatami :number', ['number' => $block->tatami->number]) }}
                            @if ($block->tatami->name)
                                <span class="text-2xl font-semibold ps-muted">{{ $block->tatami->name }}</span>
                            @endif
                        </h2>
                        <span class="text-sm ps-muted ps-num">{{ __('Estimated end :time', ['time' => $block->end->format('H:i')]) }}</span>
                    </div>

                    @if ($playing->category)
                        @php($queue = $playing->queue)
                        @php($current = $queue?->current)

                        <div class="space-y-3" data-test="now-playing">
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                                <x-public.status tone="live">{{ __('Now') }}</x-public.status>
                                <span class="ps-subtitle">{{ $playing->category->name }}</span>
                                @if ($current)
                                    <span class="text-sm ps-muted">
                                        {{ $current->bracket === \App\Enums\BoutBracket::Repechage ? __('Repechage') : __('Round :number', ['number' => $current->round]) }}
                                    </span>
                                @endif
                            </div>

                            @if ($current)
                                <div class="ps-board">
                                    @foreach (['aka' => $current->aka, 'ao' => $current->ao] as $side => $entry)
                                        <div class="ps-side ps-side-{{ $side }}">
                                            <div class="ps-side-label">{{ strtoupper($side) }}</div>
                                            <div class="ps-side-name">{{ $entry->competitor->last_name }} {{ $entry->competitor->first_name }}</div>
                                            <div class="text-sm ps-muted">{{ $entry->competitor->club->name }}</div>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <p class="ps-panel p-4 ps-muted">{{ __('Waiting for the next bout.') }}</p>
                            @endif

                            @php($next = $queue?->upcoming->take(2) ?? collect())

                            @if ($next->isNotEmpty())
                                <h3 class="text-sm font-semibold">{{ __('Next') }}</h3>
                                <div class="ps-panel overflow-hidden" data-test="next-bouts">
                                    @foreach ($next as $bout)
                                        <div class="ps-row justify-between text-base" wire:key="next-{{ $bout->id }}">
                                            <span>
                                                {{ $bout->aka ? $bout->aka->competitor->last_name.' '.$bout->aka->competitor->first_name : '—' }}
                                                <span class="ps-muted">{{ __('versus') }}</span>
                                                {{ $bout->ao ? $bout->ao->competitor->last_name.' '.$bout->ao->competitor->first_name : '—' }}
                                            </span>
                                            <span class="text-sm ps-muted">{{ $bout->bracket === \App\Enums\BoutBracket::Repechage ? __('Repechage') : __('Round :number', ['number' => $bout->round]) }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @else
                        <p class="text-base ps-muted" data-test="not-playing">{{ __('Nothing is being played on this tatami right now.') }}</p>
                    @endif

                    <div class="ps-panel overflow-hidden">
                        @foreach ($block->rows as $row)
                            <div @class(['ps-row flex-wrap', 'ps-row-live' => $row->progress === DrawProgress::Running]) data-test="timetable-row" wire:key="row-{{ $row->category->id }}">
                                <div class="ps-title ps-num w-36 shrink-0 text-2xl">{{ $row->start->format('H:i') }}–{{ $row->end->format('H:i') }}</div>

                                <div class="min-w-0 grow basis-48">
                                    @if ($row->progress !== DrawProgress::NoDraw)
                                        <a href="{{ route('public.competitions.bracket', [$competition, $row->category]) }}" wire:navigate class="ps-link ps-nav-link text-lg">{{ $row->category->name }}</a>
                                    @else
                                        <span class="text-lg font-semibold">{{ $row->category->name }}</span>
                                    @endif
                                    <div class="text-sm ps-muted">{{ __(':count entries', ['count' => $row->entries]) }}</div>
                                </div>

                                <x-public.progress :progress="$row->progress" />
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach

            <p class="text-sm ps-muted">{{ __('Times are estimates based on the number of entries and the bout duration. They do not shift when the programme runs late.') }}</p>
        @endif

        <x-public.updates />
    </div>
</section>
