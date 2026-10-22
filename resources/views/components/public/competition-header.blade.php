@props(['competition', 'active'])

@php
    use App\Enums\CompetitionStatus;

    $tabs = [
        'show' => __('Details'),
        'categories' => __('Categories'),
        'competitors' => __('Competitors'),
        'draw' => __('Draw'),
        'timetable' => __('Time table'),
        'results' => __('Results'),
        'standings' => __('Standings'),
    ];

    [$statusLabel, $statusTone] = match ($competition->status) {
        CompetitionStatus::Registration => [__('Registration open'), 'soon'],
        CompetitionStatus::Drawn => [__('Draw done'), 'soon'],
        CompetitionStatus::InProgress => [__('Live'), 'live'],
        CompetitionStatus::Finished => [__('Finished'), 'done'],
        default => [null, 'neutral'],
    };
@endphp

<header class="mb-8">
    <a href="{{ route('home') }}" wire:navigate class="ps-link ps-nav-link text-sm">{{ __('All competitions') }}</a>

    <h1 class="ps-title mt-2 text-5xl md:text-7xl">{{ $competition->name }}</h1>

    <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-1 text-base ps-muted">
        <span class="ps-num">{{ $competition->date->format('d.m.Y.') }}</span>
        <span>{{ $competition->venue }}</span>
        @if ($competition->organizer)
            <span>{{ $competition->organizer }}</span>
        @endif
        @if ($statusLabel)
            <x-public.status :tone="$statusTone" data-test="status-badge">{{ $statusLabel }}</x-public.status>
        @endif
    </div>

    <nav data-test="competition-tabs" class="ps-tabs mt-6" aria-label="{{ __('Competition sections') }}">
        @foreach ($tabs as $key => $label)
            <a
                href="{{ route('public.competitions.'.$key, $competition) }}"
                wire:navigate
                data-test="tab-{{ $key }}"
                class="ps-tab"
                @if ($active === $key) aria-current="page" @endif
            >{{ $label }}</a>
        @endforeach
    </nav>
</header>
