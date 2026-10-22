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

<section class="w-full">
    <flux:heading size="xl" level="1" class="mb-6">{{ __('Tatami') }}</flux:heading>

    @if ($this->tatamis->isEmpty())
        <flux:text>{{ __('You have no tatamis assigned. Ask the administrator to assign you to one.') }}</flux:text>
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->tatamis as $tatami)
                <a
                    href="{{ route('tatami.console', $tatami) }}"
                    wire:navigate
                    wire:key="tatami-{{ $tatami->id }}"
                    class="block rounded-xl border border-zinc-200 p-5 transition hover:border-zinc-400 dark:border-zinc-700 dark:hover:border-zinc-500"
                    data-test="tatami-card"
                >
                    <div class="text-sm text-zinc-500">{{ $tatami->competition->name }} · {{ $tatami->competition->date->format('d.m.Y.') }}</div>
                    <div class="mt-1 text-xl font-semibold">
                        {{ __('Tatami :number', ['number' => $tatami->number]) }}
                        @if ($tatami->name)
                            <span class="font-normal text-zinc-500">· {{ $tatami->name }}</span>
                        @endif
                    </div>
                    <div class="mt-3 text-sm text-zinc-600 dark:text-zinc-400">{{ __('Open console') }} &rarr;</div>
                </a>
            @endforeach
        </div>
    @endif
</section>
