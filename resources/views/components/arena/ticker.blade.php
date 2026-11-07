@props(['items' => [], 'label' => null])

@if (count($items) > 0)
    <div role="marquee" {{ $attributes->merge(['aria-label' => __('Latest results')])->class('flex h-11 items-center overflow-hidden border-t border-(--arena-live-ink) bg-arena-volt text-(--arena-live-ink)') }}>
        <span class="z-10 flex h-full flex-none items-center bg-(--arena-live-ink) px-3 font-display text-lg font-black uppercase tracking-[.06em] text-arena-bg dark:text-arena-volt">{{ $label ?? __('Results') }}</span>

        <div class="flex w-max animate-arena-ticker whitespace-nowrap pl-4 text-[13px] font-semibold">
            @foreach ($items as $item)
                <span class="pe-7">{{ $item }}</span>
            @endforeach
            @foreach ($items as $item)
                <span class="pe-7" aria-hidden="true">{{ $item }}</span>
            @endforeach
        </div>
    </div>
@endif
