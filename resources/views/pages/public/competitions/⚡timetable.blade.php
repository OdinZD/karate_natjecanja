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

    <div wire:poll.10s.visible data-test="live-region" class="space-y-8">
        @if (! $this->published)
            <x-arena.card variant="dashed" class="p-5">{{ __('The time table has not been published yet.') }}</x-arena.card>
        @else
            @php($visible = collect($this->table)->filter(fn ($block) => $block->rows !== []))
            @php($activeCount = $visible->filter(fn ($block, $index) => $this->nowPlaying[$index]->category !== null)->count())

            <x-arena.section-title :meta="__(':count of :total active', ['count' => $activeCount, 'total' => $visible->count()])">{{ __('Now on the tatamis') }}</x-arena.section-title>

            @foreach ($this->table as $index => $block)
                @continue($block->rows === [])

                @php($playing = $this->nowPlaying[$index])

                <div class="space-y-3" wire:key="tatami-{{ $block->tatami->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-1">
                        <h3 class="flex items-center gap-3">
                            <x-arena.tatami :number="$block->tatami->number" size="lg" />
                            @if ($block->tatami->name)
                                <span class="font-display text-2xl font-extrabold uppercase tracking-[.04em]">{{ $block->tatami->name }}</span>
                            @endif
                        </h3>
                        <span class="font-mono text-[13px] font-bold tabular-nums">{{ __('Estimated end :time', ['time' => $block->end->format('H:i')]) }}</span>
                    </div>

                    @if ($playing->category)
                        @php($queue = $playing->queue)
                        @php($current = $queue?->current)

                        <x-arena.card variant="live" tag="article" data-test="now-playing">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2.5">
                                <x-arena.status state="live" :label="__('Now')" />
                                <div class="flex min-w-0 flex-col">
                                    <span class="text-sm font-bold">{{ $playing->category->name }}</span>
                                    @if ($current)
                                        <span class="text-xs text-arena-muted">
                                            {{ $current->bracket === \App\Enums\BoutBracket::Repechage ? __('Repechage') : __('Round :number', ['number' => $current->round]) }}
                                        </span>
                                    @endif
                                </div>
                            </div>

                            @if ($current)
                                <x-arena.plate
                                    :aka="['name' => $current->aka->competitor->last_name.' '.$current->aka->competitor->first_name, 'club' => $current->aka->competitor->club->name]"
                                    :ao="['name' => $current->ao->competitor->last_name.' '.$current->ao->competitor->first_name, 'club' => $current->ao->competitor->club->name]"
                                />
                            @else
                                <p class="border-t border-arena-line px-3 py-3 text-sm text-arena-muted">{{ __('Waiting for the next bout.') }}</p>
                            @endif

                            @php($next = $queue?->upcoming->take(2) ?? collect())

                            @if ($next->isNotEmpty())
                                <div class="border-t border-arena-line px-3 pb-1 pt-3">
                                    <h4 class="font-display text-lg font-extrabold uppercase tracking-[.04em]">{{ __('Next') }}</h4>

                                    <div data-test="next-bouts">
                                        @foreach ($next as $bout)
                                            <div class="grid min-h-12 grid-cols-2 items-center gap-x-2 gap-y-0.5 border-b border-arena-line py-1.5 text-sm last:border-b-0" wire:key="next-{{ $bout->id }}">
                                                <span class="flex items-center gap-2">
                                                    <span aria-hidden="true" class="h-[22px] w-1 shrink-0 bg-arena-aka-text"></span>
                                                    <span><span class="text-[10px] font-extrabold tracking-[.14em] text-arena-aka-text">AKA</span> {{ $bout->aka ? $bout->aka->competitor->last_name.' '.$bout->aka->competitor->first_name : '—' }}</span>
                                                </span>
                                                <span class="flex items-center gap-2">
                                                    <span aria-hidden="true" class="h-[22px] w-1 shrink-0 bg-arena-ao-text"></span>
                                                    <span><span class="text-[10px] font-extrabold tracking-[.14em] text-arena-ao-text">AO</span> {{ $bout->ao ? $bout->ao->competitor->last_name.' '.$bout->ao->competitor->first_name : '—' }}</span>
                                                </span>
                                                <span class="col-span-2 text-xs text-arena-muted">{{ $bout->bracket === \App\Enums\BoutBracket::Repechage ? __('Repechage') : __('Round :number', ['number' => $bout->round]) }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </x-arena.card>
                    @else
                        <x-arena.card variant="dashed" class="px-3 py-2.5 text-[13px]" data-test="not-playing">{{ __('Nothing is being played on this tatami right now.') }}</x-arena.card>
                    @endif

                    <x-arena.card>
                        @foreach ($block->rows as $row)
                            <div @class([
                                'flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-arena-line px-3 py-2.5 last:border-b-0',
                                'bg-arena-surface-2 shadow-[inset_4px_0_0_var(--arena-focus)]' => $row->progress === DrawProgress::Running,
                            ]) data-test="timetable-row" wire:key="row-{{ $row->category->id }}">
                                <div class="w-32 shrink-0 font-mono text-sm font-bold tabular-nums">{{ $row->start->format('H:i') }}–{{ $row->end->format('H:i') }}</div>

                                <div class="min-w-0 grow basis-44">
                                    @if ($row->progress !== DrawProgress::NoDraw)
                                        <a href="{{ route('public.competitions.bracket', [$competition, $row->category]) }}" wire:navigate class="inline-flex min-h-11 items-center text-[15px] font-bold underline-offset-4 hover:underline">{{ $row->category->name }}</a>
                                    @else
                                        <span class="text-[15px] font-bold">{{ $row->category->name }}</span>
                                    @endif
                                    <div class="text-xs text-arena-muted">{{ __(':count entries', ['count' => $row->entries]) }}</div>
                                </div>

                                <x-public.progress :progress="$row->progress" />
                            </div>
                        @endforeach
                    </x-arena.card>
                </div>
            @endforeach

            <p class="text-sm text-arena-muted">{{ __('Times are estimates based on the number of entries and the bout duration. They do not shift when the programme runs late.') }}</p>
        @endif

        <x-public.updates />
    </div>
</section>
