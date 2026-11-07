@props(['variant' => 'primary', 'href' => null, 'icon' => null])

@php
    $look = match ($variant) {
        'secondary' => 'min-h-11 border border-arena-line-strong bg-transparent px-4 text-sm font-semibold text-arena-ink disabled:opacity-50',
        'ghost' => 'min-h-11 bg-transparent px-3 text-sm font-semibold text-arena-ink disabled:opacity-50 dark:text-arena-volt',
        'danger' => 'min-h-11 border border-arena-aka-text bg-transparent px-4 text-sm font-semibold text-arena-aka-text disabled:opacity-50',
        default => 'arena-cut min-h-12 bg-arena-volt px-5 font-display text-lg font-black uppercase tracking-[.06em] text-(--arena-live-ink) focus-visible:[clip-path:none] disabled:bg-arena-surface-2 disabled:text-arena-muted',
    };
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class(['inline-flex items-center justify-center gap-2 whitespace-nowrap', $look]) }}>
        @if ($icon)
            <flux:icon :icon="$icon" class="size-4" />
        @endif
        {{ $slot }}
    </a>
@else
    <button {{ $attributes->merge(['type' => 'button'])->class(['inline-flex cursor-pointer items-center justify-center gap-2 whitespace-nowrap disabled:cursor-not-allowed', $look]) }}>
        @if ($icon)
            <flux:icon :icon="$icon" class="size-4" />
        @endif
        {{ $slot }}
    </button>
@endif
