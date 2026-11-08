@props(['items' => [], 'label' => null, 'size' => 'md'])

@php($hall = $size === 'hall')

@if (count($items) > 0)
    <div role="marquee" {{ $attributes->merge(['aria-label' => __('Latest results')])->class([
        'flex items-center overflow-hidden border-t border-(--arena-live-ink) bg-arena-volt text-(--arena-live-ink)',
        'h-11' => ! $hall,
        'h-[clamp(3rem,5vw,4.5rem)]' => $hall,
    ]) }}>
        <span @class([
            'z-10 flex h-full flex-none items-center bg-(--arena-live-ink) font-display font-black uppercase tracking-[.06em] text-arena-bg dark:text-arena-volt',
            'px-3 text-lg' => ! $hall,
            'px-[2vw] text-[clamp(1.2rem,2.2vw,2.4rem)]' => $hall,
        ])>{{ $label ?? __('Results') }}</span>

        <div @class([
            'flex w-max whitespace-nowrap font-semibold',
            'animate-arena-ticker pl-4 text-[13px]' => ! $hall,
            'animate-arena-ticker-hall pl-[2vw] text-[clamp(1rem,1.7vw,1.9rem)]' => $hall,
        ])>
            @foreach ($items as $item)
                <span class="pe-7">{{ $item }}</span>
            @endforeach
            @foreach ($items as $item)
                <span class="pe-7" aria-hidden="true">{{ $item }}</span>
            @endforeach
        </div>
    </div>
@endif
