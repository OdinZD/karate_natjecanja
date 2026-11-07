@props(['variant' => 'default', 'tag' => 'div'])

@php
    $look = match ($variant) {
        'live' => 'arena-sweep border-2 border-arena-ink bg-arena-surface dark:border-arena-volt',
        'dashed' => 'border border-dashed border-arena-line-strong text-arena-muted',
        'alert' => 'border border-l-4 border-arena-warn bg-arena-surface',
        default => 'border border-arena-line bg-arena-surface',
    };
@endphp

<{{ $tag }} {{ $attributes->class($look) }}>{{ $slot }}</{{ $tag }}>
