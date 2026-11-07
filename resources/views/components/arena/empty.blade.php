@props(['title', 'text' => null])

<div {{ $attributes->class('flex flex-col gap-4 border border-dashed border-arena-line-strong p-5') }}>
    <h2 class="font-display text-5xl font-black uppercase leading-[.9] text-arena-ink">{{ $title }}</h2>

    @if (filled($text))
        <p class="text-[15px] leading-normal text-arena-muted">{{ $text }}</p>
    @endif

    {{ $slot }}

    @isset($action)
        <div>{{ $action }}</div>
    @endisset
</div>
