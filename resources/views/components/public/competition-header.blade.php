@props(['competition', 'active'])

@php
    use App\Enums\CompetitionStatus;

    [$statusState, $statusLabel] = match ($competition->status) {
        CompetitionStatus::Registration => ['registration', __('Registration open')],
        CompetitionStatus::Drawn => ['drawn', __('Draw done')],
        CompetitionStatus::InProgress => ['live', __('Live')],
        CompetitionStatus::Finished => ['done', __('Finished')],
        default => [null, null],
    };

    $full = in_array($active, ['show', 'timetable'], true);
@endphp

<header class="mb-6">
    @if ($full)
        <div class="arena-stripes relative -mx-4 overflow-hidden px-4 pb-6 pt-5 md:mx-0 md:px-8 md:py-8">
            <div aria-hidden="true" class="absolute -right-10 top-0 h-full w-[150px] -skew-x-14 bg-arena-aka opacity-90"></div>
            <div aria-hidden="true" class="absolute -right-[110px] top-0 h-full w-[120px] -skew-x-14 bg-arena-ao"></div>

            <div class="relative flex max-w-[280px] flex-col gap-3 md:max-w-xl">
                <a href="{{ route('home') }}" wire:navigate class="flex min-h-11 items-center gap-1.5 text-[13px] text-arena-muted">
                    <flux:icon.chevron-left class="size-4" />{{ __('All competitions') }}
                </a>

                @if ($statusLabel)
                    <x-arena.status :state="$statusState" :label="$statusLabel" class="self-start" data-test="status-badge" />
                @endif

                <h1 class="animate-arena-in break-words font-display text-[54px] font-black uppercase leading-[.88] tracking-[.01em] text-arena-ink md:text-7xl">{{ $competition->name }}</h1>

                <div class="flex flex-wrap gap-x-3.5 gap-y-1 text-[13px] text-arena-ink">
                    <span class="font-mono">{{ $competition->date->format('d.m.Y.') }}</span>
                    <span>{{ $competition->venue }}</span>
                    @if ($competition->organizer)
                        <span>{{ $competition->organizer }}</span>
                    @endif
                </div>
            </div>
        </div>
    @else
        <div class="-mx-4 flex min-h-14 items-center gap-1.5 border-b border-arena-line px-1 md:mx-0 md:px-0">
            <a href="{{ route('public.competitions.show', $competition) }}" wire:navigate aria-label="{{ __('Back to competition') }}" class="grid size-11 shrink-0 place-items-center">
                <flux:icon.chevron-left class="size-5" />
            </a>

            <h1 class="truncate font-display text-[22px] font-black uppercase tracking-[.03em] text-arena-ink">{{ $competition->name }}</h1>

            @if ($statusLabel)
                <x-arena.status :state="$statusState" :label="$statusLabel" class="ml-auto shrink-0 md:ml-4" data-test="status-badge" />
            @endif
        </div>
    @endif

    <x-arena.tabs :competition="$competition" :active="$active" class="-mx-4 md:mx-0" />
</header>
