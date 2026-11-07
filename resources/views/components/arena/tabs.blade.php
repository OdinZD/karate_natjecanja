@props(['competition', 'active'])

@php
    $tabs = [
        'show' => __('Details'),
        'categories' => __('Categories'),
        'competitors' => __('Competitors'),
        'draw' => __('Draw'),
        'timetable' => __('Time table'),
        'results' => __('Results'),
        'standings' => __('Standings'),
    ];
@endphp

<nav data-test="competition-tabs" x-data x-init="$nextTick(() => $el.querySelector('[aria-current]')?.scrollIntoView({ inline: 'center', block: 'nearest' }))" aria-label="{{ __('Competition sections') }}" {{ $attributes->class('flex gap-1 overflow-x-auto border-b border-arena-line bg-arena-bg-2 px-2 [scrollbar-width:none]') }}>
    @foreach ($tabs as $key => $label)
        <a
            href="{{ route('public.competitions.'.$key, $competition) }}"
            wire:navigate
            data-test="tab-{{ $key }}"
            @class([
                'flex min-h-12 shrink-0 items-center whitespace-nowrap px-3 text-sm focus-visible:-outline-offset-4!',
                'font-bold text-arena-ink shadow-[inset_0_-4px_0_var(--arena-focus)]' => $active === $key,
                'text-arena-muted hover:text-arena-ink' => $active !== $key,
            ])
            @if ($active === $key) aria-current="page" @endif
        >{{ $label }}</a>
    @endforeach
</nav>
