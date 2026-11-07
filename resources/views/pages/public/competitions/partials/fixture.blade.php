@php
    use App\Enums\CompetitionStatus;

    [$statusState, $statusLabel] = match ($competition->status) {
        CompetitionStatus::Registration => ['registration', __('Registration open')],
        CompetitionStatus::Drawn => ['drawn', __('Draw done')],
        CompetitionStatus::InProgress => ['live', __('Live')],
        default => ['done', __('Finished')],
    };

    $live = $competition->status === CompetitionStatus::InProgress;
@endphp

<x-arena.card :variant="$live ? 'live' : 'default'" class="relative flex items-center gap-3.5 p-3.5" data-test="competition-card" wire:key="competition-{{ $competition->id }}">
    <div aria-hidden="true" @class([
        'flex w-16 shrink-0 -skew-x-8 flex-col items-center justify-center py-1.5',
        'bg-arena-volt text-(--arena-live-ink)' => $live,
        'bg-arena-ink text-arena-bg' => ! $live,
    ])>
        <span class="font-display text-[38px] font-black leading-[.9]">{{ $competition->date->format('d') }}</span>
        <span class="font-mono text-xs font-bold uppercase">{{ $competition->date->translatedFormat('M') }}</span>
    </div>

    <div class="min-w-0">
        <a href="{{ route('public.competitions.show', $competition) }}" wire:navigate class="font-display text-[26px] font-black uppercase leading-[.95] text-arena-ink after:absolute after:inset-0 after:content-['']">{{ $competition->name }}</a>

        <div class="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px] text-arena-muted">
            <span class="sr-only">{{ $competition->date->format('d.m.Y.') }}</span>
            <span>{{ $competition->venue }}</span>
            @if ($competition->organizer)
                <span>{{ $competition->organizer }}</span>
            @endif
            <span class="font-mono tabular-nums">{{ __(':count entries', ['count' => $competition->entries_count]) }}</span>

            @if ($live)
                <a href="{{ route('public.competitions.timetable', $competition) }}" wire:navigate class="relative z-10 inline-flex min-h-11 items-center" data-test="live-badge">
                    <x-arena.status state="live" :label="__('Live').': '.__('Time table')" />
                </a>
            @else
                <x-arena.status :state="$statusState" :label="$statusLabel" data-test="status-badge" />
            @endif
        </div>
    </div>
</x-arena.card>
