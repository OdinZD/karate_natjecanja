<?php

use App\Actions\Timetable\AssignCategoryToTatami;
use App\Actions\Timetable\MoveCategory;
use App\Actions\Timetable\TimetableBuilder;
use App\Actions\Timetable\TimetableTatami;
use App\Enums\DrawProgress;
use App\Models\Category;
use App\Models\Competition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Time table')] class extends Component {
    public Competition $competition;

    public function mount(Competition $competition): void
    {
        $this->competition = $competition;
    }

    /**
     * @return array<int, TimetableTatami>
     */
    #[Computed]
    public function table(): array
    {
        return app(TimetableBuilder::class)->build($this->competition);
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function unassigned(): Collection
    {
        return app(TimetableBuilder::class)->unassigned($this->competition);
    }

    public function assign(int $categoryId, int $tatamiId, AssignCategoryToTatami $assigner): void
    {
        $assigner->handle(
            $this->competition->categories()->findOrFail($categoryId),
            $this->competition->tatamis()->findOrFail($tatamiId),
        );

        $this->refreshTable();
    }

    public function unassign(int $categoryId, AssignCategoryToTatami $assigner): void
    {
        $assigner->handle($this->competition->categories()->findOrFail($categoryId), null);

        $this->refreshTable();
    }

    public function move(int $categoryId, string $direction, MoveCategory $mover): void
    {
        abort_unless(in_array($direction, ['up', 'down'], true), 404);

        $mover->handle($this->competition->categories()->findOrFail($categoryId), $direction);

        $this->refreshTable();
    }

    private function refreshTable(): void
    {
        unset($this->table, $this->unassigned);
    }

    public function progressColor(DrawProgress $progress): string
    {
        return match ($progress) {
            DrawProgress::NoDraw => 'zinc',
            DrawProgress::Pending => 'blue',
            DrawProgress::Running => 'amber',
            DrawProgress::Finished => 'green',
        };
    }
}; ?>

<section class="w-full min-w-0 space-y-5">
    <div class="space-y-1">
        <a href="{{ route('admin.competitions.show', $competition) }}" wire:navigate class="inline-flex min-h-11 items-center gap-1.5 text-[13px] text-arena-muted">
            <flux:icon.chevron-left class="size-4" />{{ $competition->name }}
        </a>
        <h1 class="font-display text-4xl font-black uppercase leading-[.95] tracking-[.01em] text-arena-ink md:text-5xl">{{ __('Time table') }}</h1>

        <p class="text-sm text-arena-muted">
            {{ $competition->date->format('d.m.Y.') }}, {{ __('start') }} {{ substr($competition->start_time, 0, 5) }}.
            {{ __('Times are estimates based on the number of entries and the bout duration.') }}
        </p>
    </div>

    <x-arena.steps :competition="$competition" current="timetable" />

    @if (collect($this->table)->contains(fn ($block) => $block->rows !== []))
        <div>
            <x-arena.button variant="secondary" icon="document-arrow-down" :href="route('admin.competitions.timetable.pdf', $competition)" target="_blank" data-test="timetable-pdf-link">{{ __('Time table (PDF)') }}<span class="sr-only"> ({{ __('Opens in a new tab') }})</span></x-arena.button>
        </div>
    @endif

    @if (count($this->table) === 0)
        <x-arena.card variant="dashed" class="space-y-1 p-5">
            <div class="font-bold text-arena-ink">{{ __('This competition has no tatamis yet.') }}</div>
            <div class="text-sm">{{ __('Add them on the competition page, then assign categories here.') }}</div>
        </x-arena.card>
    @endif

    @if ($this->unassigned->isNotEmpty() && count($this->table) > 0)
        <x-arena.card variant="alert" class="space-y-3 p-4">
            <x-arena.section-title :meta="$this->unassigned->count()">{{ __('Categories without a tatami') }}</x-arena.section-title>

            <div class="border border-arena-line">
                @foreach ($this->unassigned as $category)
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-arena-line px-3 py-2.5 last:border-b-0" wire:key="unassigned-{{ $category->id }}">
                        <div>
                            <div class="font-bold">{{ $category->name }}</div>
                            <div class="text-xs text-arena-muted">
                                {{ __(Str::headline($category->discipline->value)) }} · {{ __(':count entries', ['count' => $category->entries_count]) }}
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($this->table as $block)
                                <x-arena.button variant="secondary" wire:click="assign({{ $category->id }}, {{ $block->tatami->id }})" data-test="assign-{{ $category->id }}-{{ $block->tatami->id }}">{{ __('Tatami :number', ['number' => $block->tatami->number]) }}</x-arena.button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </x-arena.card>
    @endif

    <div class="grid gap-5 2xl:grid-cols-2">
        @foreach ($this->table as $block)
            <x-arena.card tag="section" class="min-w-0 space-y-3 p-4" wire:key="tatami-{{ $block->tatami->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <x-arena.tatami :number="$block->tatami->number" size="lg" />

                        <div class="space-y-1">
                            @if ($block->tatami->name)
                                <div class="font-display text-xl font-extrabold uppercase tracking-[.04em]">{{ $block->tatami->name }}</div>
                            @endif

                            <div class="text-sm text-arena-muted">
                                {{ $block->tatami->operators->pluck('name')->join(', ') ?: __('No operators assigned') }}
                                @if ($block->rows !== [])
                                    · {{ __(':bouts bouts', ['bouts' => $block->totalBouts()]) }}
                                    · {{ intdiv($block->durationMinutes(), 60) }} h {{ $block->durationMinutes() % 60 }} min
                                    · {{ __('estimated end') }} {{ $block->end->format('H:i') }}
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        @if ($block->rows !== [])
                            <x-arena.button variant="secondary" icon="document-arrow-down" :href="route('admin.competitions.timetable.pdf', [$competition, 'tatami' => $block->tatami->id])" target="_blank" data-test="tatami-pdf-link-{{ $block->tatami->id }}">{{ __('Tatami PDF') }}<span class="sr-only"> ({{ __('Opens in a new tab') }})</span></x-arena.button>
                        @endif
                        <x-arena.button variant="secondary" icon="play" :href="route('tatami.console', $block->tatami)" wire:navigate>{{ __('Open console') }}</x-arena.button>
                    </div>
                </div>

                @if ($block->rows === [])
                    <p class="text-sm text-arena-muted">{{ __('No categories on this tatami yet.') }}</p>
                @else
                    <div class="relative overflow-x-auto border border-arena-line">
                        <table class="w-full text-start text-sm">
                            <thead>
                                <tr class="border-b border-arena-line text-xs uppercase tracking-[.1em] text-arena-muted">
                                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Time') }}</th>
                                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Category') }}</th>
                                    <th scope="col" class="px-4 py-3 font-semibold text-end">{{ __('Entries') }}</th>
                                    <th scope="col" class="px-4 py-3 font-semibold text-end">{{ __('Bouts') }}</th>
                                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Status') }}</th>
                                    <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                                </tr>
                            </thead>

                            <tbody>
                                @foreach ($block->rows as $row)
                                    <tr @class([
                                        'border-b border-arena-line last:border-b-0',
                                        'bg-arena-surface-2 shadow-[inset_4px_0_0_var(--arena-focus)]' => $row->progress === DrawProgress::Running,
                                        'text-arena-muted' => $row->progress === DrawProgress::Finished,
                                    ]) wire:key="row-{{ $row->category->id }}">
                                        <td class="whitespace-nowrap px-4 py-2 font-mono tabular-nums">{{ $row->start->format('H:i') }}–{{ $row->end->format('H:i') }}</td>
                                        <td class="px-4 py-2">
                                            <span class="font-bold">{{ $row->category->name }}</span>
                                            <span class="text-xs text-arena-muted">{{ __(Str::headline($row->category->discipline->value)) }}</span>
                                        </td>
                                        <td class="px-4 py-2 text-end font-mono tabular-nums">{{ $row->entries }}</td>
                                        <td class="px-4 py-2 text-end font-mono tabular-nums">{{ $row->bouts }}</td>
                                        <td class="px-4 py-2">
                                            <x-public.progress :progress="$row->progress" />
                                        </td>
                                        <td class="whitespace-nowrap px-2 py-1 text-end">
                                            <x-arena.button variant="ghost" icon="eye" class="min-w-11" :aria-label="__('Show bracket')" :href="route('admin.competitions.bracket', [$competition, $row->category])" wire:navigate />
                                            <x-arena.button variant="ghost" icon="chevron-up" class="min-w-11" :aria-label="__('Move up')" wire:click="move({{ $row->category->id }}, 'up')" />
                                            <x-arena.button variant="ghost" icon="chevron-down" class="min-w-11" :aria-label="__('Move down')" wire:click="move({{ $row->category->id }}, 'down')" />
                                            <x-arena.button variant="ghost" icon="x-mark" class="min-w-11" :aria-label="__('Remove from tatami')" wire:click="unassign({{ $row->category->id }})" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-arena.card>
        @endforeach
    </div>
</section>
