<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => isset($title) ? __($title) : null])
    </head>
    <body class="public-site min-h-screen antialiased">
        <header class="ps-topbar">
            <div class="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-4 py-3">
                <a href="{{ route('home') }}" wire:navigate class="ps-nav-link gap-2" data-test="brand">
                    <x-app-logo-icon class="size-7 fill-current" />
                    <span class="ps-title text-2xl">{{ config('app.name', 'Laravel') }}</span>
                </a>

                <nav class="flex items-center gap-5 text-sm">
                    <a href="{{ route('home') }}" wire:navigate class="ps-nav-link font-medium">{{ __('Competitions') }}</a>

                    @auth
                        <a href="{{ route('dashboard') }}" wire:navigate class="ps-link ps-nav-link">{{ __('Manage') }}</a>
                    @else
                        <a href="{{ route('login') }}" wire:navigate class="ps-link ps-nav-link">{{ __('Log in') }}</a>
                    @endauth
                </nav>
            </div>
        </header>

        <main class="mx-auto w-full max-w-6xl px-4 py-6 md:py-10">
            {{ $slot }}
        </main>

        <footer class="mx-auto w-full max-w-6xl px-4 pb-10 pt-4 text-sm ps-muted">
            {{ config('app.name', 'Laravel') }}
        </footer>

        @fluxScripts
    </body>
</html>
