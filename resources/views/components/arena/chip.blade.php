@props(['value' => '', 'label', 'hint' => null])

<label class="arena-skew relative inline-flex min-h-11 min-w-11 cursor-pointer items-center justify-center border border-arena-line-strong px-4 text-sm font-semibold text-arena-ink has-checked:border-arena-ink has-checked:bg-arena-ink has-checked:text-arena-bg has-focus-visible:outline-[3px] has-focus-visible:outline-offset-2 has-focus-visible:outline-(--arena-focus)">
    <input type="radio" value="{{ $value }}" {{ $attributes->merge(['aria-label' => filled($hint) ? $label.': '.$hint : null]) }} class="sr-only">
    {{ $label }}
</label>
