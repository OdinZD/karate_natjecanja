@props(['place' => 1])

@php
    $label = match ((int) $place) {
        1 => __('1st place'),
        2 => __('2nd place'),
        default => __('3rd place'),
    };

    $look = match ((int) $place) {
        1 => 'bg-arena-gold text-(--arena-live-ink)',
        2 => 'border-[3px] border-arena-silver text-arena-ink',
        default => 'border-[3px] border-dotted border-arena-bronze text-arena-ink',
    };
@endphp

<span {{ $attributes->class(['inline-grid size-8 shrink-0 place-items-center rounded-full font-display text-base font-black', $look]) }} role="img" aria-label="{{ $label }}">
    <span aria-hidden="true">{{ $place }}</span>
</span>
