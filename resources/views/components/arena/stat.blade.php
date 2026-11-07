@props(['label', 'value', 'of' => null, 'progress' => null, 'hint' => null])

<div {{ $attributes->class('flex flex-col gap-2 border border-arena-line bg-arena-surface p-4') }}>
    <span class="text-xs uppercase tracking-[.1em] text-arena-muted">{{ $label }}</span>

    <span class="font-display text-[44px] font-black leading-none tabular-nums text-arena-ink">{{ $value }}@if ($of !== null)<span class="text-2xl text-arena-muted"> / {{ $of }}</span>@endif</span>

    @if ($progress !== null)
        @php($percent = max(0, min(100, (int) round($progress))))

        <div role="progressbar" aria-label="{{ $label }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}" class="h-1.5 bg-arena-surface-2">
            <div class="h-full origin-left animate-arena-bar bg-arena-volt ring-1 ring-inset ring-(--arena-live-ink)" style="width: {{ $percent }}%"></div>
        </div>
    @endif

    @if (filled($hint))
        <span class="text-[13px] text-arena-muted">{{ $hint }}</span>
    @endif
</div>
