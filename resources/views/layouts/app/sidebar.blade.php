<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-arena-bg text-arena-ink">
        @php
            $link = 'flex min-h-11 items-center gap-2.5 px-2.5 text-sm font-semibold';
            $linkOn = 'bg-arena-surface-2 text-arena-ink shadow-[inset_4px_0_0_var(--arena-focus)]';
            $linkOff = 'text-arena-muted hover:text-arena-ink';

            $platform = [
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'current' => request()->routeIs('dashboard'), 'icon' => 'home', 'show' => true],
                ['label' => __('Tatami'), 'href' => route('tatami.index'), 'current' => request()->routeIs('tatami.*'), 'icon' => 'computer-desktop', 'show' => auth()->user()->isAdmin() || auth()->user()->tatamis()->exists()],
            ];

            $administration = [
                ['label' => __('Competitions'), 'href' => route('admin.competitions.index'), 'current' => request()->routeIs('admin.competitions.*'), 'icon' => 'trophy'],
                ['label' => __('Clubs'), 'href' => route('admin.clubs.index'), 'current' => request()->routeIs('admin.clubs.*'), 'icon' => 'building-office'],
                ['label' => __('Competitors'), 'href' => route('admin.competitors.index'), 'current' => request()->routeIs('admin.competitors.*'), 'icon' => 'user-group'],
                ['label' => __('Users'), 'href' => route('admin.users.index'), 'current' => request()->routeIs('admin.users.*'), 'icon' => 'users'],
            ];
        @endphp

        <flux:sidebar sticky collapsible="mobile" class="border-e border-arena-line! bg-arena-bg-2!">
            <flux:sidebar.header>
                <a href="{{ route('dashboard') }}" wire:navigate class="flex min-h-11 items-center px-2">
                    <x-arena.logo size="sm" />
                </a>
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <nav aria-label="{{ __('Main menu') }}" class="flex flex-col gap-0.5">
                @foreach ($platform as $item)
                    @if ($item['show'])
                        <a href="{{ $item['href'] }}" wire:navigate @class([$link, $linkOn => $item['current'], $linkOff => ! $item['current']]) @if ($item['current']) aria-current="page" @endif>
                            <flux:icon :icon="$item['icon']" class="size-[18px]" />{{ $item['label'] }}
                        </a>
                    @endif
                @endforeach

                @can('admin')
                    <span class="px-2.5 pb-1.5 pt-3.5 text-[11px] uppercase tracking-[.14em] text-arena-muted">{{ __('Administration') }}</span>

                    @foreach ($administration as $item)
                        <a href="{{ $item['href'] }}" wire:navigate @class([$link, $linkOn => $item['current'], $linkOff => ! $item['current']]) @if ($item['current']) aria-current="page" @endif>
                            <flux:icon :icon="$item['icon']" class="size-[18px]" />{{ $item['label'] }}
                        </a>
                    @endforeach
                @endcan
            </nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="border-b border-arena-line! bg-arena-bg-2! lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <a href="{{ route('dashboard') }}" wire:navigate class="flex min-h-11 items-center px-2">
                <x-arena.logo size="sm" />
            </a>

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
