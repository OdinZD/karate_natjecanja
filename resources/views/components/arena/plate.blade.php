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

    $akaName = data_get($aka, 'name');
    $akaClub = data_get($aka, 'club');
    $aoName = data_get($ao, 'name');
    $aoClub = data_get($ao, 'club');

    $height = $compact ? 'h-14' : 'h-[84px]';
    $label = $compact ? 'text-[10px]' : 'text-[11px]';
    $name = $compact ? 'text-sm' : 'text-[15px]';
    $score = $compact ? 'text-[34px]' : 'text-[46px]';
@endphp

<div {{ $attributes->class(['grid grid-cols-2', $height]) }}>
    <div @class([
        'arena-plate-aka flex items-center justify-between gap-1.5 bg-arena-aka text-white',
        'pl-2.5 pr-[18px]' => $compact,
        'pl-3 pr-[22px]' => ! $compact,
    ])>
        <div class="flex min-w-0 flex-col">
            <span @class([$label, 'font-extrabold uppercase tracking-[.14em]'])>AKA</span>
            <span @class([$name, 'truncate font-bold leading-tight'])>{{ $akaName ?? '—' }}</span>
            @if (! $compact && filled($akaClub))
                <span class="truncate text-xs">{{ $akaClub }}</span>
            @endif
        </div>

        @if ($showScore)
            <span wire:key="aka-score-{{ $akaScore }}" @class([$score, 'animate-arena-pop font-display font-black leading-none tabular-nums'])>{{ $akaScore ?? 0 }}</span>
        @endif
    </div>

    <div @class([
        'arena-plate-ao flex flex-row-reverse items-center justify-between gap-1.5 bg-arena-ao text-right text-white',
        'pl-[18px] pr-2.5' => $compact,
        'pl-[22px] pr-3' => ! $compact,
    ])>
        <div class="flex min-w-0 flex-col items-end">
            <span @class([$label, 'font-extrabold uppercase tracking-[.14em]'])>AO</span>
            <span @class([$name, 'max-w-full truncate font-bold leading-tight'])>{{ $aoName ?? '—' }}</span>
            @if (! $compact && filled($aoClub))
                <span class="max-w-full truncate text-xs">{{ $aoClub }}</span>
            @endif
        </div>

        @if ($showScore)
            <span wire:key="ao-score-{{ $aoScore }}" @class([$score, 'animate-arena-pop font-display font-black leading-none tabular-nums'])>{{ $aoScore ?? 0 }}</span>
        @endif
    </div>
</div>
