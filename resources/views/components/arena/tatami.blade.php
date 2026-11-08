@props(['number', 'size' => 'md'])

@php
    $sizing = match ($size) {
        'sm' => 'px-1.5 text-[22px]',
        'lg' => 'px-3 text-5xl',
        'hall' => 'px-[calc(var(--u,.52vw)*1.6)] text-[calc(var(--u,.52vw)*7)]',
        default => 'px-2.5 text-[30px]',
    };
@endphp

<span {{ $attributes->class(['inline-block arena-skew bg-arena-ink py-0.5 font-display font-black leading-none tabular-nums text-arena-bg', $sizing]) }}>
    <span aria-hidden="true">T{{ $number }}</span>
    <span class="sr-only">{{ __('Tatami :number', ['number' => $number]) }}</span>
</span>
