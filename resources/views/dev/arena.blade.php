@php
    use App\Enums\BoutDecision;
    use App\Models\Bout;
    use App\Models\Club;
    use App\Models\Competition;
    use App\Models\Competitor;
    use App\Models\Entry;

    $competition = (new Competition)->forceFill(['id' => 1, 'slug' => 'demo-cup']);

    $entry = fn (int $id, string $last, string $first, string $club) => (new Entry)
        ->forceFill(['id' => $id])
        ->setRelation('competitor', (new Competitor)
            ->forceFill(['last_name' => $last, 'first_name' => $first])
            ->setRelation('club', (new Club)->forceFill(['name' => $club])));

    $makeBout = fn (?Entry $aka, ?Entry $ao, ?int $akaScore, ?int $aoScore, ?int $winnerId, ?BoutDecision $decision) => (new Bout)
        ->forceFill([
            'aka_entry_id' => $aka?->id,
            'ao_entry_id' => $ao?->id,
            'aka_score' => $akaScore,
            'ao_score' => $aoScore,
            'winner_entry_id' => $winnerId,
            'decision' => $decision,
        ])
        ->setRelation('aka', $aka)
        ->setRelation('ao', $ao);

    $maric = $entry(1, 'Marić', 'Luka', 'KK Optimist');
    $baric = $entry(2, 'Barić', 'Ivan', 'KK Jadran');
    $cosic = $entry(3, 'Ćosić', 'Filip', 'KK Sokol');
    $grgic = $entry(4, 'Grgić', 'Jakov', 'KK Zrinski');

    $bouts = [
        'played' => $makeBout($maric, $baric, 3, 1, 1, BoutDecision::Points),
        'live' => $makeBout($cosic, $grgic, 2, 2, null, null),
        'pending' => $makeBout(null, null, null, null, null, null),
        'bye' => $makeBout($maric, null, null, null, 1, BoutDecision::Bye),
    ];

    $states = ['live', 'registration', 'drawn', 'waiting', 'done', 'none', 'warn', 'error'];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => 'Arena components'])
    </head>
    <body class="min-h-screen bg-arena-bg font-sans text-arena-ink antialiased">
        <main class="mx-auto flex max-w-5xl flex-col gap-10 px-4 py-8">
            <header class="flex flex-wrap items-center justify-between gap-4">
                <x-arena.logo size="lg" />

                <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
                    <flux:radio value="light" icon="sun">{{ __('Light') }}</flux:radio>
                    <flux:radio value="dark" icon="moon">{{ __('Dark') }}</flux:radio>
                    <flux:radio value="system" icon="computer-desktop">{{ __('System') }}</flux:radio>
                </flux:radio.group>
            </header>

            <section class="flex flex-col gap-3">
                <x-arena.section-title meta="x-arena.status">Status</x-arena.section-title>
                <div class="flex flex-wrap items-center gap-3">
                    @foreach ($states as $state)
                        <x-arena.status :state="$state" />
                    @endforeach
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <x-arena.section-title meta="x-arena.button">Buttons</x-arena.section-title>
                <div class="flex flex-wrap items-center gap-3">
                    <x-arena.button>Zapiši rezultat</x-arena.button>
                    <x-arena.button disabled>Odaberi pobjednika</x-arena.button>
                    <x-arena.button variant="secondary" icon="arrow-down-tray">Predložak</x-arena.button>
                    <x-arena.button variant="ghost" href="#">Tekstualni</x-arena.button>
                    <x-arena.button variant="danger" icon="trash">Obriši</x-arena.button>
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <x-arena.section-title meta="x-arena.tatami · medal">Tatami i medalje</x-arena.section-title>
                <div class="flex flex-wrap items-center gap-4">
                    <x-arena.tatami number="1" size="sm" />
                    <x-arena.tatami number="2" />
                    <x-arena.tatami number="3" size="lg" />
                    <x-arena.medal :place="1" />
                    <x-arena.medal :place="2" />
                    <x-arena.medal :place="3" />
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <x-arena.section-title meta="x-arena.plate">AKA | AO ploča</x-arena.section-title>
                <x-arena.plate :aka="['name' => 'Luka Marić', 'club' => 'KK Optimist']" :ao="['name' => 'Ivan Barić', 'club' => 'KK Jadran']" :aka-score="3" :ao-score="1" show-score />
                <x-arena.plate :aka="['name' => 'Petra Šimić', 'club' => 'KK Sokol']" :ao="['name' => 'Ema Jurić', 'club' => 'KK Optimist']" />
                <x-arena.plate size="sm" :aka="['name' => 'Marić']" :ao="['name' => 'Barić']" :aka-score="3" :ao-score="1" show-score class="max-w-xs" />
            </section>

            <section class="flex flex-col gap-3">
                <x-arena.section-title meta="x-arena.card">Kartice</x-arena.section-title>
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-arena.card class="p-4">default</x-arena.card>
                    <x-arena.card variant="live" class="p-4">live</x-arena.card>
                    <x-arena.card variant="dashed" class="p-4">dashed</x-arena.card>
                    <x-arena.card variant="alert" class="p-4">alert</x-arena.card>
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <x-arena.section-title meta="x-arena.stat">Brojke</x-arena.section-title>
                <div class="grid gap-3 sm:grid-cols-3">
                    <x-arena.stat label="Borbe odigrane" value="214" of="372" :progress="58" />
                    <x-arena.stat label="Prijave" value="386" hint="241 natjecatelj · 18 klubova" />
                    <x-arena.stat label="Kategorije" value="41" of="125" :progress="33" />
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <x-arena.section-title meta="x-arena.tabs">Tabovi</x-arena.section-title>
                <div class="public-site">
                    <x-arena.tabs :competition="$competition" active="timetable" />
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <x-arena.section-title meta="x-arena.steps">Koraci</x-arena.section-title>
                <x-arena.steps :competition="$competition" current="draw" />
            </section>

            <section class="flex flex-col gap-3">
                <x-arena.section-title meta="x-arena.bout">Borbe</x-arena.section-title>
                <div class="grid max-w-md gap-3">
                    <x-arena.bout :bout="$bouts['played']" />
                    <x-arena.bout :bout="$bouts['live']" live />
                    <x-arena.bout :bout="$bouts['pending']" />
                    <x-arena.bout :bout="$bouts['bye']" />
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <x-arena.section-title meta="x-arena.empty">Prazno stanje</x-arena.section-title>
                <x-arena.empty title="Još nema ždrijeba" text="Administrator još nije generirao ždrijeb za ovu kategoriju.">
                    <x-slot:action>
                        <x-arena.button variant="secondary">Osvježi</x-arena.button>
                    </x-slot:action>
                </x-arena.empty>
            </section>

            <section class="flex flex-col gap-3">
                <x-arena.section-title meta="x-arena.ticker">Ticker</x-arena.section-title>
                <x-arena.ticker :items="['U12 Učenice Kata — 1. Mia Horvat, KK Optimist', 'Juniorke Kumite −59 kg — 1. Sara Knežević, KK Sokol', 'Kadeti Kumite −52 kg — 1. Luka Marić, KK Optimist']" />
            </section>
        </main>

        @fluxScripts
    </body>
</html>
