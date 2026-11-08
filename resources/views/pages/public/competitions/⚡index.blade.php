<?php

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::public'), Title('Competitions')] class extends Component {
    use WithPagination;

    /**
     * @return Collection<int, Competition>
     */
    #[Computed]
    public function upcoming(): Collection
    {
        return Competition::query()
            ->visible()
            ->where('status', '!=', CompetitionStatus::Finished->value)
            ->withCount(['entries' => fn ($entries) => $entries->present()])
            ->orderBy('date')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return LengthAwarePaginator<int, Competition>
     */
    #[Computed]
    public function finished(): LengthAwarePaginator
    {
        return Competition::query()
            ->where('status', CompetitionStatus::Finished->value)
            ->withCount(['entries' => fn ($entries) => $entries->present()])
            ->orderByDesc('date')
            ->orderBy('name')
            ->paginate(12, pageName: 'finished');
    }
}; ?>

<section class="w-full">
    <div class="arena-stripes relative -mx-4 overflow-hidden px-4 pb-6 pt-7 md:mx-0 md:px-8 md:py-12">
        <div aria-hidden="true" class="absolute -right-[30px] top-0 h-full w-[110px] -skew-x-14 bg-arena-aka"></div>
        <div aria-hidden="true" class="absolute -right-[100px] top-0 h-full w-[100px] -skew-x-14 bg-arena-ao"></div>

        <div class="relative flex max-w-[290px] flex-col gap-2.5 md:max-w-xl">
            <h1 class="animate-arena-in break-words font-display text-[64px] font-black uppercase leading-[.85] text-arena-ink md:text-7xl">{{ __('Competitions') }}</h1>
            <p class="text-[15px] leading-snug text-arena-muted">{{ __('Draw, schedule and results of local karate competitions.') }}</p>
        </div>
    </div>

    <div class="mt-6 space-y-10">
        <div>
            <x-arena.section-title class="mb-3">{{ __('Current and upcoming') }}</x-arena.section-title>

            @if ($this->upcoming->isEmpty())
                <x-arena.card variant="dashed" class="p-5">{{ $this->finished->total() > 0 ? __('There are no current or upcoming competitions right now.') : __('There are no published competitions right now.') }}</x-arena.card>
            @else
                <div class="grid gap-3">
                    @foreach ($this->upcoming as $competition)
                        @include('pages.public.competitions.partials.fixture', ['competition' => $competition])
                    @endforeach
                </div>
            @endif
        </div>

        @if ($this->finished->total() > 0)
            <div>
                <x-arena.section-title class="mb-3">{{ __('Finished competitions') }}</x-arena.section-title>

                <div class="grid gap-3">
                    @foreach ($this->finished as $competition)
                        @include('pages.public.competitions.partials.fixture', ['competition' => $competition])
                    @endforeach
                </div>

                <div class="mt-4">{{ $this->finished->links() }}</div>
            </div>
        @endif
    </div>
</section>
