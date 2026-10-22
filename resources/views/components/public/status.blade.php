@props(['tone' => 'neutral'])

<span {{ $attributes->class('ps-status') }} data-tone="{{ $tone }}">
    <i class="ps-status-dot" aria-hidden="true"></i>{{ $slot }}
</span>
