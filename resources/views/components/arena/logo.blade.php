@props(['size' => 'md'])

@php
    $mark = match ($size) {
        'sm' => 'size-[26px]',
        'lg' => 'size-16',
        default => 'size-10',
    };

    $name = match ($size) {
        'sm' => 'text-xl',
        'lg' => 'text-5xl',
        default => 'text-3xl',
    };
@endphp

<span {{ $attributes->class('inline-flex items-center gap-2.5') }}>
    <span aria-hidden="true" @class([
        'block shrink-0 bg-linear-135 from-arena-aka from-50% to-arena-ao to-50% [clip-path:polygon(20%_0,100%_0,80%_100%,0_100%)]',
        $mark,
    ])></span>
    <span @class(['font-display font-black uppercase leading-none tracking-[.05em] text-arena-ink', $name])>{{ config('app.name', 'Organizacija natjecanja') }}</span>
</span>
