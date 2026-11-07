@props(['state' => 'none', 'label' => null])

@php
    $text = filled($label) ? $label : ($slot->isNotEmpty() ? $slot : match ($state) {
        'live' => __('Live'),
        'registration' => __('Registration open'),
        'drawn' => __('Draw done'),
        'waiting' => __('Waiting'),
        'done' => __('Finished'),
        'warn' => __('Warning'),
        'error' => __('Error'),
        default => __('No draw'),
    });

    [$chip, $shape] = match ($state) {
        'live' => [
            'arena-skew bg-arena-volt text-(--arena-live-ink)',
            'size-2 rounded-full bg-(--arena-live-ink) animate-arena-pulse',
        ],
        'registration' => [
            'arena-skew border border-arena-ink text-arena-ink dark:border-arena-volt dark:text-arena-volt',
            'size-[9px] rounded-full border-2 border-current',
        ],
        'drawn', 'waiting' => [
            'border border-arena-line-strong text-arena-ink',
            'size-2.5 bg-arena-ao-text [clip-path:polygon(50%_0,100%_50%,50%_100%,0_50%)]',
        ],
        'done' => [
            'border border-arena-line-strong text-arena-ink',
            'size-[9px] bg-arena-muted',
        ],
        'warn' => [
            'border border-arena-warn text-arena-warn',
            'size-2.5 bg-arena-warn [clip-path:polygon(50%_0,100%_100%,0_100%)]',
        ],
        'error' => [
            'border border-arena-aka-text text-arena-aka-text',
            'size-2.5 bg-arena-aka-text [clip-path:polygon(0_0,100%_0,50%_100%)]',
        ],
        default => [
            'border border-arena-line-strong text-arena-muted',
            'size-[9px] rounded-full border-2 border-dashed border-arena-muted',
        ],
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-2 whitespace-nowrap px-3 py-1.5 text-xs font-extrabold uppercase tracking-[.14em]', $chip]) }} data-state="{{ $state }}">
    <span aria-hidden="true" @class(['shrink-0', $shape])></span>{{ $text }}
</span>
