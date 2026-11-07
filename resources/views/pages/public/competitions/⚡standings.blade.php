<?php

use App\Actions\Results\ClubStandings;
use App\Models\Competition;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::public'), Title('Standings')] class extends Component {
    public Competition $competition;

    public function mount(Competition $publicCompetition): void
    {
        $this->competition = $publicCompetition;
    }

    /**
     * @return Collection<int, \App\Actions\Results\ClubStandingRow>
     */
    #[Computed]
    public function standings(): Collection
    {
        return app(ClubStandings::class)->handle($this->competition);
    }
}; ?>

<section class="w-full">
    <x-public.competition-header :competition="$competition" active="standings" />

    <div wire:poll.10s.visible data-test="live-region" class="space-y-4">
        @if ($this->standings->isEmpty())
            <x-arena.card variant="dashed" class="p-5">{{ __('No medals have been won yet.') }}</x-arena.card>
        @else
            <x-arena.card class="overflow-x-auto">
                <table class="w-full text-start">
                    <thead>
                        <tr class="border-b border-arena-line text-xs uppercase tracking-[.1em] text-arena-muted">
                            <th scope="col" class="w-12 px-0 py-3 text-center font-semibold sm:w-auto sm:px-4 sm:text-start">{{ __('Rank') }}</th>
                            <th scope="col" class="px-2 py-3 text-start font-semibold sm:px-4">{{ __('Club') }}</th>
                            <th scope="col" class="px-1.5 py-3 text-end font-semibold sm:px-4"><x-arena.medal :place="1" aria-hidden="true" class="me-1 size-6! text-xs! align-middle sm:size-8! sm:text-base!" /><span class="sr-only sm:not-sr-only">{{ __('Gold') }}</span></th>
                            <th scope="col" class="px-1.5 py-3 text-end font-semibold sm:px-4"><x-arena.medal :place="2" aria-hidden="true" class="me-1 size-6! text-xs! align-middle sm:size-8! sm:text-base!" /><span class="sr-only sm:not-sr-only">{{ __('Silver') }}</span></th>
                            <th scope="col" class="px-1.5 py-3 text-end font-semibold sm:px-4"><x-arena.medal :place="3" aria-hidden="true" class="me-1 size-6! text-xs! align-middle sm:size-8! sm:text-base!" /><span class="sr-only sm:not-sr-only">{{ __('Bronze') }}</span></th>
                            <th scope="col" class="px-1.5 py-3 text-end text-[10px] font-semibold tracking-normal sm:px-4 sm:text-xs sm:tracking-[.1em]">{{ __('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->standings as $row)
                            <tr class="border-b border-arena-line last:border-0" data-test="standings-row" data-rank="{{ $row->rank }}" wire:key="club-{{ $row->clubId }}">
                                <td @class([
                                    'px-0 py-3 text-center font-display text-2xl font-black tabular-nums sm:px-4',
                                    'bg-arena-volt text-(--arena-live-ink)' => $row->rank === 1,
                                ])>{{ $row->rank }}</td>
                                <td class="px-2 py-3 sm:px-4">
                                    <span class="text-[15px] font-bold">{{ $row->club }}</span>
                                    @if ($row->city)
                                        <span class="block text-xs text-arena-muted">{{ $row->city }}</span>
                                    @endif
                                    @if ($row->total > 0)
                                        <span class="mt-1.5 flex h-1.5 w-full max-w-48 origin-left animate-arena-bar bg-arena-surface-2" aria-hidden="true">
                                            <span class="bg-arena-gold" style="width: {{ $row->gold / $row->total * 100 }}%"></span>
                                            <span class="bg-arena-silver" style="width: {{ $row->silver / $row->total * 100 }}%"></span>
                                            <span class="bg-arena-bronze" style="width: {{ $row->bronze / $row->total * 100 }}%"></span>
                                        </span>
                                    @endif
                                </td>
                                <td class="px-1.5 py-3 text-end font-mono text-base tabular-nums sm:px-4">{{ $row->gold }}</td>
                                <td class="px-1.5 py-3 text-end font-mono text-base tabular-nums sm:px-4">{{ $row->silver }}</td>
                                <td class="px-1.5 py-3 text-end font-mono text-base tabular-nums sm:px-4">{{ $row->bronze }}</td>
                                <td class="px-1.5 py-3 text-end font-mono text-base font-bold tabular-nums sm:px-4">{{ $row->total }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-arena.card>

            <p class="text-sm text-arena-muted">{{ __('Ranked by gold medals, then silver, then bronze.') }}</p>
        @endif

        <x-public.updates />
    </div>
</section>
