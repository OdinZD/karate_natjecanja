@props([
    'aka' => null,
    'ao' => null,
    'akaScore' => null,
    'aoScore' => null,
    'showScore' => false,
    'size' => 'md',
])

@php
    $compact = $size === 'sm';
    $hall = $size === 'hall';

    $akaName = data_get($aka, 'name');
    $akaClub = data_get($aka, 'club');
    $aoName = data_get($ao, 'name');
    $aoClub = data_get($ao, 'club');

    $height = match (true) {
        $compact => 'h-14',
        $hall => 'h-[calc(var(--u,.52vw)*19)]',
        default => 'h-[84px]',
    };
    $label = match (true) {
        $compact => 'text-[10px]',
        $hall => 'text-[calc(var(--u,.52vw)*2)]',
        default => 'text-[11px]',
    };
    $name = match (true) {
        $compact => 'text-sm',
        $hall => 'font-display text-[calc(var(--u,.52vw)*6)] uppercase',
        default => 'text-[15px]',
    };
    $clubSize = $hall ? 'text-[calc(var(--u,.52vw)*2.6)]' : 'text-xs';
    $score = $compact ? 'text-[34px]' : 'text-[46px]';
@endphp

<div {{ $attributes->class(['grid grid-cols-2', $height]) }}>
    <div @class([
        'flex items-center justify-between gap-1.5 bg-arena-aka text-white',
        'arena-plate-aka' => ! $hall,
        'arena-plate-aka-hall' => $hall,
        'pl-2.5 pr-[18px]' => $compact,
        'pl-3 pr-[22px]' => ! $compact && ! $hall,
        'pl-[calc(var(--u,.52vw)*3)] pr-[calc(var(--u,.52vw)*5)]' => $hall,
    ])>
        <div class="flex min-w-0 flex-col">
            <span @class([$label, 'font-extrabold uppercase tracking-[.14em]'])>AKA</span>
            <span @class([$name, 'truncate font-bold leading-tight'])>{{ $akaName ?? '—' }}</span>
            @if (! $compact && filled($akaClub))
                <span @class([$clubSize, 'truncate'])>{{ $akaClub }}</span>
            @endif
        </div>

        @if ($showScore)
            <span wire:key="aka-score-{{ $akaScore }}" @class([$score, 'animate-arena-pop font-display font-black leading-none tabular-nums'])>{{ $akaScore ?? 0 }}</span>
        @endif
    </div>

    <div @class([
        'flex flex-row-reverse items-center justify-between gap-1.5 bg-arena-ao text-right text-white',
        'arena-plate-ao' => ! $hall,
        'arena-plate-ao-hall' => $hall,
        'pl-[18px] pr-2.5' => $compact,
        'pl-[22px] pr-3' => ! $compact && ! $hall,
        'pl-[calc(var(--u,.52vw)*5)] pr-[calc(var(--u,.52vw)*3)]' => $hall,
    ])>
        <div class="flex min-w-0 flex-col items-end">
            <span @class([$label, 'font-extrabold uppercase tracking-[.14em]'])>AO</span>
            <span @class([$name, 'max-w-full truncate font-bold leading-tight'])>{{ $aoName ?? '—' }}</span>
            @if (! $compact && filled($aoClub))
                <span @class([$clubSize, 'max-w-full truncate'])>{{ $aoClub }}</span>
            @endif
        </div>

        @if ($showScore)
            <span wire:key="ao-score-{{ $aoScore }}" @class([$score, 'animate-arena-pop font-display font-black leading-none tabular-nums'])>{{ $aoScore ?? 0 }}</span>
        @endif
    </div>
</div>
