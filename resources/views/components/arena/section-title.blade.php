@props(['meta' => null])

<div {{ $attributes->class('flex items-baseline justify-between gap-3') }}>
    <h2 class="font-display text-2xl font-extrabold uppercase leading-none tracking-[.04em] text-arena-ink md:text-[28px]">{{ $slot }}</h2>

    @if (filled($meta))
        <span class="text-xs text-arena-muted">{{ $meta }}</span>
    @endif
</div>
