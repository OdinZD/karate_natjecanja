<?php

use App\Enums\CompetitionStatus;
use App\Models\Tatami;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Tatami')] class extends Component {
    /**
     * @return Collection<int, Tatami>
     */
    #[Computed]
    public function tatamis(): Collection
    {
        $user = Auth::user();

        return Tatami::query()
            ->with('competition')
            ->whereHas('competition', fn ($query) => $query->where('status', '!=', CompetitionStatus::Finished->value))
            ->when(! $user->isAdmin(), fn ($query) => $query->whereHas('operators', fn ($operators) => $operators->whereKey($user->id)))
            ->get()
            ->sortBy([
                fn (Tatami $a, Tatami $b) => $b->competition->date <=> $a->competition->date,
                fn (Tatami $a, Tatami $b) => $a->number <=> $b->number,
            ])
            ->values();
    }
}; ?>

<section class="mx-auto w-full max-w-2xl space-y-4">
    <div class="space-y-1.5">
        <h1 class="font-display text-[44px] font-black uppercase leading-[.95] text-arena-ink">{{ __('Tatami') }}</h1>
        <p class="text-sm text-arena-muted">{{ __('Choose the tatami you record results for.') }}</p>
    </div>

    @if ($this->tatamis->isEmpty())
        <x-arena.card variant="dashed" class="p-5">{{ __('You have no tatamis assigned. Ask the administrator to assign you to one.') }}</x-arena.card>
    @else
        <div class="grid gap-3">
            @foreach ($this->tatamis as $tatami)
                @php
                    $live = $tatami->competition->status === CompetitionStatus::InProgress;

                    [$statusState, $statusLabel] = match ($tatami->competition->status) {
                        CompetitionStatus::InProgress => ['live', __('Live')],
                        CompetitionStatus::Registration => ['registration', __('Registration open')],
                        CompetitionStatus::Drawn => ['drawn', __('Draw done')],
                        default => ['none', __('Draft')],
                    };
                @endphp

                <x-arena.card
                    :variant="$live ? 'live' : 'default'"
                    tag="a"
                    :href="route('tatami.console', $tatami)"
                    wire:navigate
                    wire:key="tatami-{{ $tatami->id }}"
                    class="flex flex-col"
                    data-test="tatami-card"
                >
                    <span class="flex gap-3.5 p-3.5">
                        <x-arena.tatami :number="$tatami->number" size="lg" class="self-start" />

                        <span class="flex min-w-0 flex-col gap-1">
                            <x-arena.status :state="$statusState" :label="$statusLabel" class="self-start" />
                            <span class="text-base font-bold text-arena-ink">
                                {{ __('Tatami :number', ['number' => $tatami->number]) }}
                                @if ($tatami->name)
                                    <span class="font-normal text-arena-muted">· {{ $tatami->name }}</span>
                                @endif
                            </span>
                            <span class="text-[13px] text-arena-muted">{{ $tatami->competition->name }} · {{ $tatami->competition->date->format('d.m.Y.') }}</span>
                        </span>
                    </span>

                    <span @class([
                        'flex min-h-12 items-center justify-between px-3.5',
                        'bg-arena-volt font-display text-xl font-black uppercase tracking-[.06em] text-(--arena-live-ink)' => $live,
                        'border-t border-arena-line text-sm font-semibold text-arena-ink' => ! $live,
                    ])>
                        {{ __('Open console') }}
                        <flux:icon.arrow-right class="size-5" />
                    </span>
                </x-arena.card>
            @endforeach
        </div>
    @endif
</section>
