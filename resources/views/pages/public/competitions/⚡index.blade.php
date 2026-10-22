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
            ->withCount('entries')
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
            ->withCount('entries')
            ->orderByDesc('date')
            ->orderBy('name')
            ->paginate(12, pageName: 'finished');
    }
}; ?>

<section class="w-full">
    <h1 class="ps-title text-5xl md:text-7xl">{{ __('Competitions') }}</h1>
    <p class="mt-3 max-w-prose text-lg ps-muted">{{ __('Draw, schedule and results of local karate competitions.') }}</p>

    <div class="mt-10 space-y-12">
        <div>
            <h2 class="ps-subtitle mb-3">{{ __('Current and upcoming') }}</h2>

            @if ($this->upcoming->isEmpty())
                <p class="ps-panel p-5 ps-muted">{{ $this->finished->total() > 0 ? __('There are no current or upcoming competitions right now.') : __('There are no published competitions right now.') }}</p>
            @else
                <div class="ps-panel overflow-hidden">
                    @foreach ($this->upcoming as $competition)
                        @include('pages.public.competitions.partials.fixture', ['competition' => $competition])
                    @endforeach
                </div>
            @endif
        </div>

        @if ($this->finished->total() > 0)
            <div>
                <h2 class="ps-subtitle mb-3">{{ __('Finished competitions') }}</h2>

                <div class="ps-panel overflow-hidden">
                    @foreach ($this->finished as $competition)
                        @include('pages.public.competitions.partials.fixture', ['competition' => $competition])
                    @endforeach
                </div>

                <div class="mt-4">{{ $this->finished->links() }}</div>
            </div>
        @endif
    </div>
</section>
