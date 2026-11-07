@php
    $link = 'flex min-h-11 items-center px-2.5 text-sm font-semibold';
    $linkOn = 'bg-arena-surface-2 text-arena-ink shadow-[inset_4px_0_0_var(--arena-focus)]';
    $linkOff = 'text-arena-muted hover:text-arena-ink';

    // Inside a Livewire update request the current route is Livewire's own, so compare with the original page path.
    $isCurrent = fn (string $name) => app('livewire')->isLivewireRequest()
        ? str()->is(trim(route($name, absolute: false), '/'), app('livewire')->originalPath())
        : request()->routeIs($name);

    $items = [
        [__('Profile'), route('profile.edit'), $isCurrent('profile.edit')],
        [__('Security'), route('security.edit'), $isCurrent('security.edit')],
        [__('Appearance'), route('appearance.edit'), $isCurrent('appearance.edit')],
    ];
@endphp

<div class="flex items-start max-md:flex-col">
    <nav aria-label="{{ __('Settings') }}" class="me-10 flex w-full flex-col gap-0.5 pb-4 md:w-[220px]">
        @foreach ($items as [$label, $href, $current])
            <a href="{{ $href }}" wire:navigate @class([$link, $linkOn => $current, $linkOff => ! $current]) @if ($current) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    <div class="flex-1 self-stretch max-md:pt-6">
        <div class="font-display text-2xl font-extrabold uppercase tracking-[.04em] text-arena-ink">{{ $heading ?? '' }}</div>
        <p class="text-sm text-arena-muted">{{ $subheading ?? '' }}</p>

        <div class="mt-5 w-full max-w-lg">
            {{ $slot }}
        </div>
    </div>
</div>
