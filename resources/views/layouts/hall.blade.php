<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        {{-- Always dark, whatever the TV's colour scheme says, so @fluxAppearance is left out. --}}
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />

        <title>{{ filled($title ?? null) ? __($title).' - '.config('app.name', 'Organizacija natjecanja') : config('app.name', 'Organizacija natjecanja') }}</title>

        <link rel="icon" href="/favicon.svg" type="image/svg+xml">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen overflow-hidden bg-arena-bg font-sans text-arena-ink antialiased">
        {{ $slot }}

        <script>
            // The screen runs all day: when the session expires, reload instead of asking someone to click "refresh".
            document.addEventListener('livewire:init', () => {
                Livewire.interceptRequest(({ onError }) => {
                    onError(({ response, preventDefault }) => {
                        if (response.status === 419) {
                            preventDefault()
                            window.location.reload()
                        }
                    })
                })
            })
        </script>
    </body>
</html>
