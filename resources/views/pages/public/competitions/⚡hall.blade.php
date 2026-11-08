<?php

use App\Actions\Timetable\NowPlaying;
use App\Actions\Timetable\NowPlayingRow;
use App\Actions\Timetable\TimetableBuilder;
use App\Actions\Timetable\TimetableRow;
use App\Actions\Timetable\TimetableTatami;
use App\Enums\BoutBracket;
use App\Enums\BoutDecision;
use App\Enums\BoutStatus;
use App\Enums\CompetitionStatus;
use App\Enums\DrawProgress;
use App\Models\Bout;
use App\Models\Competition;
use App\Support\RoundLabel;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::hall'), Title('Hall screen')] class extends Component {
    private const TICKER_ITEMS = 10;

    public Competition $competition;

    public function mount(Competition $publicCompetition): void
    {
        $this->competition = $publicCompetition;
    }

    /**
     * Only the tatamis that have a time table.
     *
     * @return array<int, TimetableTatami>
     */
    #[Computed]
    public function table(): array
    {
        return array_values(array_filter(
            app(TimetableBuilder::class)->build($this->competition),
            fn (TimetableTatami $block) => $block->rows !== [],
        ));
    }

    /**
     * @return array<int, NowPlayingRow>
     */
    #[Computed]
    public function nowPlaying(): array
    {
        return app(NowPlaying::class)->handle($this->table);
    }

    /**
     * The latest played bouts, newest first, as one line each for the ticker.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function results(): array
    {
        return Bout::query()
            ->whereHas('category', fn ($query) => $query->where('competition_id', $this->competition->id))
            ->where('status', BoutStatus::Finished)
            ->where('decision', '!=', BoutDecision::Bye)
            ->whereNotNull('winner_entry_id')
            ->whereNotNull('aka_entry_id')
            ->whereNotNull('ao_entry_id')
            ->with(['category', 'aka.competitor.club', 'ao.competitor.club'])
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->limit(self::TICKER_ITEMS)
            ->get()
            ->map(function (Bout $bout): string {
                $akaWon = $bout->winner_entry_id === $bout->aka_entry_id;
                $winner = $akaWon ? $bout->aka : $bout->ao;
                $loser = $akaWon ? $bout->ao : $bout->aka;

                return __(':category: :winner beat :loser', [
                    'category' => $bout->category->name,
                    'winner' => $winner->competitor->last_name.' '.$winner->competitor->first_name.' ('.$winner->competitor->club->name.')',
                    'loser' => $loser->competitor->last_name.' '.$loser->competitor->first_name,
                ]);
            })
            ->all();
    }

    private function name(?object $entry): string
    {
        return $entry ? $entry->competitor->last_name.' '.$entry->competitor->first_name : '—';
    }
}; ?>

<main class="flex h-screen flex-col" data-test="hall-screen">
    <header class="arena-stripes flex flex-none items-center justify-between gap-[2vw] border-b border-arena-line px-[2.5vw] py-[1.2vw]">
        <h1 class="min-w-0 truncate font-display font-black uppercase leading-none tracking-[.01em] text-[clamp(1.8rem,3.6vw,4.2rem)]">{{ $competition->name }}</h1>

        <div class="flex flex-none items-center gap-[2vw]">
            @if ($competition->status === CompetitionStatus::InProgress)
                <x-arena.status state="live" :label="__('Live')" class="!text-[clamp(.9rem,1.5vw,1.8rem)]" />
            @endif

            <div wire:ignore x-data="{ now: new Date() }" x-init="setInterval(() => now = new Date(), 1000)" class="font-mono font-bold tabular-nums text-[clamp(1.6rem,3.4vw,4rem)]" aria-hidden="true">
                <span x-text="now.toLocaleTimeString('hr-HR', { hour: '2-digit', minute: '2-digit' })">{{ now()->format('H:i') }}</span>
            </div>
        </div>
    </header>

    <div wire:poll.10s.visible data-test="live-region" class="flex min-h-0 flex-1 flex-col">
        @php($count = count($this->table))

        @if ($count === 0)
            <div class="grid flex-1 place-items-center p-[3vw]">
                <p class="font-display font-black uppercase text-arena-muted text-[clamp(1.6rem,3vw,3.6rem)]">{{ __('The time table has not been published yet.') }}</p>
            </div>
        @else
            <div @class([
                'grid min-h-0 flex-1 auto-rows-fr gap-[1.5vw] p-[1.5vw]',
                'grid-cols-1' => $count === 1,
                'grid-cols-2' => $count >= 2 && $count <= 4,
                'grid-cols-3' => $count >= 5,
            ])>
                @foreach ($this->table as $index => $block)
                    @php($playing = $this->nowPlaying[$index])
                    @php($queue = $playing->queue)
                    @php($current = $queue?->current)

                    <x-arena.card :variant="$current ? 'live' : 'default'" tag="article" class="min-h-0 overflow-hidden [container-type:size]" wire:key="hall-tatami-{{ $block->tatami->id }}" data-test="hall-tatami">
                        {{-- --u is the unit everything in the tile is sized in: the tile's width or, in a short tile, its height --}}
                        <div class="flex h-full min-h-0 flex-col gap-[calc(var(--u,.52vw)*1.6)] overflow-hidden p-[calc(var(--u,.52vw)*2)]" style="--u: min(1cqw, 1.6cqh)">
                        <div class="flex flex-wrap items-center gap-x-[calc(var(--u,.52vw)*2.5)] gap-y-1">
                            <x-arena.tatami :number="$block->tatami->number" size="hall" />

                            @if ($playing->category)
                                <div class="flex min-w-0 flex-col">
                                    <span class="truncate font-bold text-[calc(var(--u,.52vw)*3.8)]">{{ $playing->category->name }}</span>
                                    @if ($current)
                                        <span class="text-arena-muted text-[calc(var(--u,.52vw)*2.8)]">
                                            {{ $current->bracket === BoutBracket::Repechage ? __('Repechage') : RoundLabel::for($current->round, $playing->category->finalRound()) }}
                                        </span>
                                    @endif
                                </div>
                            @endif
                        </div>

                        @if ($current)
                            <x-arena.plate
                                size="hall"
                                :aka="['name' => $this->name($current->aka), 'club' => $current->aka->competitor->club->name]"
                                :ao="['name' => $this->name($current->ao), 'club' => $current->ao->competitor->club->name]"
                            />

                            @php($next = $queue->upcoming->take(2))

                            @if ($next->isNotEmpty())
                                <div data-test="hall-next">
                                    <h2 class="font-mono font-bold uppercase tracking-[.14em] text-arena-volt text-[calc(var(--u,.52vw)*2.6)]">{{ __('Next') }}</h2>

                                    @foreach ($next as $bout)
                                        <p class="truncate text-[calc(var(--u,.52vw)*3.6)]" wire:key="hall-next-{{ $bout->id }}">
                                            <span class="font-extrabold text-arena-aka-text">AKA</span> {{ $this->name($bout->aka) }}
                                            <span class="px-[calc(var(--u,.52vw)*1)] text-arena-muted">·</span>
                                            <span class="font-extrabold text-arena-ao-text">AO</span> {{ $this->name($bout->ao) }}
                                        </p>
                                    @endforeach
                                </div>
                            @endif
                        @elseif ($playing->category)
                            <p class="text-arena-muted text-[calc(var(--u,.52vw)*3.8)]">{{ __('Waiting for the next bout.') }}</p>
                        @else
                            @php($upcoming = collect($block->rows)->first(fn (TimetableRow $row) => $row->progress !== DrawProgress::Finished))

                            <p class="font-display font-black uppercase text-arena-muted text-[calc(var(--u,.52vw)*5)]" data-test="hall-idle">{{ __('Nothing is being fought here right now.') }}</p>

                            @if ($upcoming)
                                <p class="text-[calc(var(--u,.52vw)*3.8)]">
                                    <span class="font-mono font-bold uppercase tracking-[.14em] text-arena-volt text-[calc(var(--u,.52vw)*2.6)]">{{ __('Getting ready') }}</span>
                                    {{ $upcoming->category->name }}
                                    <span class="font-mono tabular-nums text-arena-muted">· {{ $upcoming->start->format('H:i') }}</span>
                                </p>
                            @endif
                        @endif
                        </div>
                    </x-arena.card>
                @endforeach
            </div>

            <x-arena.ticker :items="$this->results" size="hall" />
        @endif
    </div>
</main>
