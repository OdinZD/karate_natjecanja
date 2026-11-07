@props(['competition', 'current' => null])

@php
    $steps = [
        'details' => [__('Details'), route('admin.competitions.show', $competition)],
        'tatami' => [__('Tatamis'), route('admin.competitions.show', $competition).'#tatami'],
        'categories' => [__('Categories'), route('admin.competitions.categories', $competition)],
        'entries' => [__('Entries'), route('admin.competitions.entries', $competition)],
        'draw' => [__('Draw'), route('admin.competitions.draw', $competition)],
        'timetable' => [__('Time table'), route('admin.competitions.timetable', $competition)],
        'day' => [__('Competition day'), route('tatami.index')],
    ];
@endphp

<nav aria-label="{{ __('Competition steps') }}" {{ $attributes->class('grid grid-cols-[repeat(auto-fit,minmax(min(120px,100%),1fr))] gap-1') }}>
    @foreach ($steps as $key => [$label, $url])
        <a
            href="{{ $url }}"
            wire:navigate
            data-test="{{ $key }}-link"
            style="animation-delay: {{ $loop->index * 60 }}ms"
            @class([
                'arena-chevron animate-arena-in relative flex min-h-[76px] flex-col justify-center gap-0.5 py-3 pl-[18px] pr-[22px] focus-visible:[clip-path:none]',
                'bg-arena-volt text-(--arena-live-ink)' => $current === $key,
                'bg-arena-surface-2 text-arena-ink' => $current !== $key,
            ])
            @if ($current === $key) aria-current="step" @endif
        >
            <span class="flex items-center gap-1.5 font-mono text-xs opacity-85">
                <span aria-hidden="true">{{ sprintf('%02d', $loop->iteration) }}</span>
                @if ($current === $key)
                    <span aria-hidden="true">&#9679;</span>
                @endif
            </span>
            <span class="text-[15px] font-bold">{{ $label }}</span>
        </a>
    @endforeach
</nav>
