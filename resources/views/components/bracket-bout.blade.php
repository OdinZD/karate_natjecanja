@props(['bout', 'current' => false])

@php
    $makeRow = function ($entry, ?int $entryId, ?int $score) use ($bout) {
        return [
            'name' => $entry ? $entry->competitor->last_name.' '.$entry->competitor->first_name : null,
            'club' => $entry?->competitor->club->name,
            'score' => $score,
            'isBye' => $bout->decision === \App\Enums\BoutDecision::Bye && $entry === null,
            'isWinner' => $entryId !== null && $bout->winner_entry_id === $entryId,
            'isLoser' => $bout->winner_entry_id !== null && $entryId !== null && $bout->winner_entry_id !== $entryId,
        ];
    };

    $rows = [
        $makeRow($bout->aka, $bout->aka_entry_id, $bout->aka_score),
        $makeRow($bout->ao, $bout->ao_entry_id, $bout->ao_score),
    ];
@endphp

<div @class([
    'overflow-hidden rounded-lg border bg-white text-sm dark:bg-zinc-900',
    'border-zinc-200 dark:border-zinc-700' => ! $current,
    'border-emerald-600 ring-2 ring-emerald-600 dark:border-emerald-400 dark:ring-emerald-400' => $current,
]) data-test="bout">
    @if ($current)
        <div class="flex items-center gap-2 bg-emerald-700 px-3 py-1 text-xs font-semibold text-white dark:bg-emerald-400 dark:text-zinc-900" data-test="current-bout">
            <span aria-hidden="true">&#9654;</span>{{ __('Now') }}
        </div>
    @endif

    @foreach ($rows as $index => $line)
        <div @class([
            'flex items-center justify-between gap-2 px-3 py-2',
            'border-t border-zinc-200 dark:border-zinc-700' => $index === 1,
            'bg-green-50 font-semibold dark:bg-green-950/40' => $line['isWinner'],
            'text-zinc-400 dark:text-zinc-500' => $line['isLoser'] || $line['isBye'],
        ])>
            <div class="flex min-w-0 items-center gap-2">
                <span @class([
                    'size-2 shrink-0 rounded-full',
                    'bg-red-500' => $index === 0,
                    'bg-blue-500' => $index === 1,
                ]) title="{{ $index === 0 ? 'AKA' : 'AO' }}"></span>

                <div class="min-w-0">
                    @if ($line['name'])
                        <div class="truncate">{{ $line['name'] }}</div>
                        <div class="truncate text-xs font-normal text-zinc-500">{{ $line['club'] }}</div>
                    @elseif ($line['isBye'])
                        <div class="italic">{{ __('Bye') }}</div>
                    @else
                        <div>—</div>
                    @endif
                </div>
            </div>

            @if ($line['score'] !== null)
                <span class="tabular-nums">{{ $line['score'] }}</span>
            @endif
        </div>
    @endforeach

    @if ($bout->decision !== null && $bout->decision !== \App\Enums\BoutDecision::Bye)
        <div class="border-t border-zinc-200 px-3 py-1 text-xs text-zinc-500 dark:border-zinc-700">
            {{ __(\Illuminate\Support\Str::headline($bout->decision->value)) }}
        </div>
    @endif
</div>
