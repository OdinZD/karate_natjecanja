@props([
    'status',
])

@if ($status)
    <div {{ $attributes->merge(['class' => 'font-medium text-sm text-arena-ok']) }}>
        {{ $status }}
    </div>
@endif
