@php
    use App\Enums\CompetitionStatus;

    [$statusLabel, $statusTone] = match ($competition->status) {
        CompetitionStatus::Registration => [__('Registration open'), 'soon'],
        CompetitionStatus::Drawn => [__('Draw done'), 'soon'],
        CompetitionStatus::InProgress => [__('Live'), 'live'],
        default => [__('Finished'), 'done'],
    };
@endphp

<div class="ps-fixture" data-test="competition-card" wire:key="competition-{{ $competition->id }}">
    <div class="ps-date" aria-hidden="true">
        <span class="ps-date-day">{{ $competition->date->format('d') }}</span>
        <span class="ps-date-month">{{ $competition->date->translatedFormat('M') }}</span>
    </div>

    <div class="min-w-0">
        <a href="{{ route('public.competitions.show', $competition) }}" wire:navigate class="ps-fixture-name after:absolute after:inset-0 after:content-['']">{{ $competition->name }}</a>

        <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm ps-muted">
            <span class="ps-num sr-only">{{ $competition->date->format('d.m.Y.') }}</span>
            <span>{{ $competition->venue }}</span>
            @if ($competition->organizer)
                <span>{{ $competition->organizer }}</span>
            @endif
            <span class="ps-num">{{ __(':count entries', ['count' => $competition->entries_count]) }}</span>

            @if ($competition->status === CompetitionStatus::InProgress)
                <a href="{{ route('public.competitions.timetable', $competition) }}" wire:navigate class="ps-nav-link relative z-10" data-test="live-badge">
                    <x-public.status tone="live">{{ __('Live') }}: {{ __('Time table') }}</x-public.status>
                </a>
            @else
                <x-public.status :tone="$statusTone" data-test="status-badge">{{ $statusLabel }}</x-public.status>
            @endif
        </div>
    </div>
</div>
