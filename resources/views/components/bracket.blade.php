@props([
    'category',
    'bouts',
    'placements',
    'currentBoutId' => null,
])

@php
    use App\Enums\BoutBracket;

    $main = $bouts->where('bracket', BoutBracket::Main)->groupBy('round')->sortKeys();
    $finalRound = $main->keys()->max() ?? 0;
    $repechage = $bouts->where('bracket', BoutBracket::Repechage)->groupBy('position')->sortKeys();

    $roundName = fn (int $round) => \App\Support\RoundLabel::for($round, $finalRound);

    $label = fn ($entry) => $entry === null ? null : $entry->competitor->last_name.' '.$entry->competitor->first_name;
    $placeName = fn (int $place) => match ($place) {
        1 => __('1st place'),
        2 => __('2nd place'),
        default => __('3rd place'),
    };
@endphp


<div {{ $attributes->class('space-y-10') }} data-test="bracket">
    @if ($bouts->isEmpty())
        <p class="text-sm text-arena-muted">{{ __('No draw has been generated for this category yet.') }}</p>
    @else
        <div class="relative snap-x snap-proximity overflow-x-auto pb-2">
            <div class="flex min-w-max items-stretch gap-6">
                @foreach ($main as $round => $roundBouts)
                    <div class="flex w-60 snap-start scroll-ms-4 flex-col">
                        <div class="mb-3 font-display text-[15px] font-extrabold uppercase tracking-[.1em] text-arena-muted">{{ $roundName($round) }}</div>

                        <div class="flex flex-1 flex-col justify-around gap-3">
                            @foreach ($roundBouts->sortBy('position') as $bout)
                                <x-bracket-bout :bout="$bout" :current="$bout->id === $currentBoutId" />
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        @if ($repechage->isNotEmpty())
            <div class="space-y-4">
                <x-arena.section-title>{{ __('Repechage') }}</x-arena.section-title>

                <div class="grid gap-6 md:grid-cols-2">
                    @foreach ($repechage as $half => $steps)
                        <div class="space-y-3">
                            <p class="text-sm font-medium text-arena-muted">{{ __('Half :number', ['number' => $half]) }}</p>

                            @foreach ($steps->sortBy('round') as $bout)
                                <x-bracket-bout :bout="$bout" :current="$bout->id === $currentBoutId" />
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($placements->isNotEmpty())
            <div class="space-y-3">
                <x-arena.section-title>{{ __('Results') }}</x-arena.section-title>

                <ol class="space-y-1">
                    @foreach ($placements as $placement)
                        <li class="flex items-baseline gap-3">
                            <span class="w-24 text-sm text-arena-muted">{{ $placeName($placement->place) }}</span>
                            <span class="font-medium">{{ $label($placement->entry) }}</span>
                            <span class="text-sm text-arena-muted">{{ $placement->entry->competitor->club->name }}</span>
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif
    @endif
</div>
