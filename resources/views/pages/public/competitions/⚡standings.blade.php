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
            <p class="ps-panel p-5 ps-muted">{{ __('No medals have been won yet.') }}</p>
        @else
            <div class="ps-panel overflow-hidden">
                <table class="w-full text-start">
                    <thead>
                        <tr class="border-b border-(--ps-line) text-sm ps-muted">
                            <th scope="col" class="px-2 py-3 sm:px-4 text-start font-medium">{{ __('Rank') }}</th>
                            <th scope="col" class="px-2 py-3 sm:px-4 text-start font-medium">{{ __('Club') }}</th>
                            <th scope="col" class="px-2 py-3 sm:px-4 text-end font-medium"><span class="ps-medal ps-medal-1 me-1 align-middle" aria-hidden="true">1</span><span class="sr-only sm:not-sr-only">{{ __('Gold') }}</span></th>
                            <th scope="col" class="px-2 py-3 sm:px-4 text-end font-medium"><span class="ps-medal ps-medal-2 me-1 align-middle" aria-hidden="true">2</span><span class="sr-only sm:not-sr-only">{{ __('Silver') }}</span></th>
                            <th scope="col" class="px-2 py-3 sm:px-4 text-end font-medium"><span class="ps-medal ps-medal-3 me-1 align-middle" aria-hidden="true">3</span><span class="sr-only sm:not-sr-only">{{ __('Bronze') }}</span></th>
                            <th scope="col" class="px-2 py-3 sm:px-4 text-end font-medium">{{ __('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->standings as $row)
                            <tr class="border-b border-(--ps-line) last:border-0" data-test="standings-row" data-rank="{{ $row->rank }}" wire:key="club-{{ $row->clubId }}">
                                <td class="ps-title ps-num px-2 py-3 sm:px-4 text-2xl">{{ $row->rank }}</td>
                                <td class="px-2 py-3 sm:px-4">
                                    <span class="text-lg font-semibold">{{ $row->club }}</span>
                                    @if ($row->city)
                                        <span class="block text-sm ps-muted">{{ $row->city }}</span>
                                    @endif
                                </td>
                                <td class="ps-num px-2 py-3 sm:px-4 text-end text-lg">{{ $row->gold }}</td>
                                <td class="ps-num px-2 py-3 sm:px-4 text-end text-lg">{{ $row->silver }}</td>
                                <td class="ps-num px-2 py-3 sm:px-4 text-end text-lg">{{ $row->bronze }}</td>
                                <td class="ps-num px-2 py-3 sm:px-4 text-end text-lg font-semibold">{{ $row->total }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="text-sm ps-muted">{{ __('Ranked by gold medals, then silver, then bronze.') }}</p>
        @endif

        <x-public.updates />
    </div>
</section>
