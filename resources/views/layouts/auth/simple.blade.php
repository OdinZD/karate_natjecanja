<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-arena-bg text-arena-ink antialiased">
        <main class="arena-stripes relative flex min-h-svh flex-col items-center justify-center overflow-hidden p-6 md:p-10">
            <div aria-hidden="true" class="absolute -right-10 top-0 h-full w-[220px] -skew-x-14 bg-arena-aka opacity-90"></div>
            <div aria-hidden="true" class="absolute -right-[170px] top-0 h-full w-[180px] -skew-x-14 bg-arena-ao"></div>

            <div class="relative w-full max-w-[440px] space-y-6">
                <a href="{{ route('home') }}" class="flex min-h-11 justify-center" wire:navigate>
                    <x-arena.logo />
                </a>

                <div class="border border-arena-line bg-arena-surface p-6 sm:p-8">
                    {{ $slot }}
                </div>

                <p class="text-center">
                    <a href="{{ route('home') }}" class="inline-flex min-h-11 items-center text-sm font-semibold underline underline-offset-4" wire:navigate>{{ __('Public results') }}</a>
                </p>
            </div>
        </main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
