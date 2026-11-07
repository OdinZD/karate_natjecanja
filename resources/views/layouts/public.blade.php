<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => isset($title) ? __($title) : null])
    </head>
    <body class="public-site min-h-screen bg-arena-bg font-sans text-arena-ink antialiased">
        <header class="border-b border-arena-line">
            <div class="mx-auto flex h-14 w-full max-w-6xl items-center justify-between gap-3 px-4">
                <a href="{{ route('home') }}" wire:navigate class="flex min-h-11 items-center" data-test="brand">
                    <x-arena.logo size="sm" />
                </a>

                <nav class="flex items-center gap-1 text-sm font-semibold">
                    <a href="{{ route('home') }}" wire:navigate class="hidden min-h-11 items-center px-3 sm:inline-flex">{{ __('Competitions') }}</a>

                    @auth
                        <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex min-h-11 items-center px-3">{{ __('Manage') }}</a>
                    @else
                        <a href="{{ route('login') }}" wire:navigate class="inline-flex min-h-11 items-center px-3">{{ __('Log in') }}</a>
                    @endauth

                    <button type="button" x-data x-on:click="$flux.dark = ! $flux.dark" x-bind:aria-pressed="$flux.dark.toString()" aria-label="{{ __('Toggle theme') }}" class="grid size-11 cursor-pointer place-items-center border border-arena-line bg-arena-surface">
                        <flux:icon.moon class="size-5 dark:hidden" />
                        <flux:icon.sun class="hidden size-5 dark:block" />
                    </button>
                </nav>
            </div>
        </header>

        <main class="mx-auto w-full max-w-6xl px-4 pb-8 pt-0 md:pb-12">
            {{ $slot }}
        </main>

        <footer class="mx-auto w-full max-w-6xl px-4 pb-10 pt-4 text-sm text-arena-muted">
            {{ config('app.name', 'Organizacija natjecanja') }}
        </footer>

        @fluxScripts
    </body>
</html>
