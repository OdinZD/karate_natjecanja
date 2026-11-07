@props(['bout', 'live' => false, 'winner' => null])

@php
    $makeRow = function ($entry, ?int $entryId, ?int $score, string $side) use ($bout, $winner) {
        $isWinner = $winner !== null
            ? $winner === $side && $entry !== null
            : $entryId !== null && $bout->winner_entry_id === $entryId;

        $hasWinner = $winner !== null || $bout->winner_entry_id !== null;

        return [
            'side' => $side,
            'name' => $entry ? $entry->competitor->last_name.' '.$entry->competitor->first_name : null,
            'club' => $entry?->competitor->club->name,
            'score' => $score,
            'isBye' => $bout->decision === \App\Enums\BoutDecision::Bye && $entry === null,
            'isWinner' => $isWinner,
            'isLoser' => $hasWinner && $entry !== null && ! $isWinner,
        ];
    };

    $rows = [
        $makeRow($bout->aka, $bout->aka_entry_id, $bout->aka_score, 'aka'),
        $makeRow($bout->ao, $bout->ao_entry_id, $bout->ao_score, 'ao'),
    ];

    $pending = $bout->aka === null && $bout->ao === null && $bout->decision !== \App\Enums\BoutDecision::Bye;
@endphp

<div @class([
    'overflow-hidden bg-arena-surface text-[13px]',
    'border border-arena-line' => ! $live && ! $pending,
    'border border-dashed border-arena-line-strong' => $pending,
    'arena-sweep border-2 border-arena-ink dark:border-arena-volt' => $live,
]) data-test="bout">
    @if ($live)
        <div class="flex items-center gap-2 bg-arena-volt px-3 py-1 text-[11px] font-extrabold uppercase tracking-[.1em] text-(--arena-live-ink)" data-test="current-bout">
            <span aria-hidden="true">&#9654;</span>{{ __('Now') }}
        </div>
    @endif

    @foreach ($rows as $index => $line)
        <div @class([
            'flex min-h-9 items-center justify-between gap-2 pe-2.5',
            'border-t border-arena-surface-2' => $index === 1,
            'font-bold text-arena-ink' => $line['isWinner'],
            'text-arena-muted' => $line['isLoser'] || $line['isBye'],
        ])>
            <div class="flex min-w-0 items-center gap-2 self-stretch">
                <span @class([
                    'w-1 shrink-0 self-stretch',
                    'bg-arena-aka' => $line['side'] === 'aka',
                    'bg-arena-ao' => $line['side'] === 'ao',
                ])><span class="sr-only">{{ $line['side'] === 'aka' ? 'AKA' : 'AO' }}</span></span>

                <div class="min-w-0 py-1.5">
                    @if ($line['name'])
                        <div class="truncate">{{ $line['name'] }}</div>
                        <div class="truncate text-[11px] font-normal text-arena-muted">{{ $line['club'] }}</div>
                    @elseif ($line['isBye'])
                        <div class="italic">{{ __('Bye') }}</div>
                    @else
                        <div>—</div>
                    @endif
                </div>
            </div>

            @if ($line['score'] !== null)
                <span class="font-mono tabular-nums">{{ $line['score'] }}</span>
            @endif
        </div>
    @endforeach

    @if ($bout->decision !== null && $bout->decision !== \App\Enums\BoutDecision::Bye)
        <div class="border-t border-arena-surface-2 px-3 py-1 text-xs text-arena-muted">
            {{ __(\Illuminate\Support\Str::headline($bout->decision->value)) }}
        </div>
    @endif
</div>
