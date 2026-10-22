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

<section class="w-full space-y-8">
    <div>
        <flux:link :href="route('admin.competitions.show', $competition)" wire:navigate class="text-sm">
            &larr; {{ $competition->name }}
        </flux:link>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <flux:heading size="xl" level="1">{{ __('Time table') }}</flux:heading>

            @if (collect($this->table)->contains(fn ($block) => $block->rows !== []))
                <flux:button icon="document-arrow-down" :href="route('admin.competitions.timetable.pdf', $competition)" target="_blank" data-test="timetable-pdf-link">
                    {{ __('Time table (PDF)') }}
                </flux:button>
            @endif
        </div>
        <flux:text class="mt-1">
            {{ $competition->date->format('d.m.Y.') }}, {{ __('start') }} {{ substr($competition->start_time, 0, 5) }}.
            {{ __('Times are estimates based on the number of entries and the bout duration.') }}
        </flux:text>
    </div>

    @if (count($this->table) === 0)
        <flux:callout icon="information-circle">
            <flux:callout.heading>{{ __('This competition has no tatamis yet.') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Add them on the competition page, then assign categories here.') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($this->unassigned->isNotEmpty() && count($this->table) > 0)
        <div class="space-y-3">
            <flux:heading size="lg">{{ __('Categories without a tatami') }}</flux:heading>

            <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($this->unassigned as $category)
                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3" wire:key="unassigned-{{ $category->id }}">
                        <div>
                            <div class="font-medium">{{ $category->name }}</div>
                            <div class="text-xs text-zinc-500">
                                {{ __(Str::headline($category->discipline->value)) }} · {{ __(':count entries', ['count' => $category->entries_count]) }}
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($this->table as $block)
                                <flux:button size="sm" wire:click="assign({{ $category->id }}, {{ $block->tatami->id }})" data-test="assign-{{ $category->id }}-{{ $block->tatami->id }}">
                                    {{ __('Tatami :number', ['number' => $block->tatami->number]) }}
                                </flux:button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-2">
        @foreach ($this->table as $block)
            <div class="space-y-3" wire:key="tatami-{{ $block->tatami->id }}">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <flux:heading size="lg">
                            {{ __('Tatami :number', ['number' => $block->tatami->number]) }}
                            @if ($block->tatami->name)
                                <span class="font-normal text-zinc-500">· {{ $block->tatami->name }}</span>
                            @endif
                        </flux:heading>
                        <flux:text class="text-sm">
                            {{ $block->tatami->operators->pluck('name')->join(', ') ?: __('No operators assigned') }}
                            @if ($block->rows !== [])
                                · {{ __(':bouts bouts', ['bouts' => $block->totalBouts()]) }}
                                · {{ intdiv($block->durationMinutes(), 60) }} h {{ $block->durationMinutes() % 60 }} min
                                · {{ __('estimated end') }} {{ $block->end->format('H:i') }}
                            @endif
                        </flux:text>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($block->rows !== [])
                            <flux:button size="sm" icon="document-arrow-down" :href="route('admin.competitions.timetable.pdf', [$competition, 'tatami' => $block->tatami->id])" target="_blank" data-test="tatami-pdf-link-{{ $block->tatami->id }}">
                                {{ __('Tatami PDF') }}
                            </flux:button>
                        @endif
                        <flux:button size="sm" icon="play" :href="route('tatami.console', $block->tatami)" wire:navigate>
                            {{ __('Open console') }}
                        </flux:button>
                    </div>
                </div>

                @if ($block->rows === [])
                    <flux:text>{{ __('No categories on this tatami yet.') }}</flux:text>
                @else
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Time') }}</flux:table.column>
                            <flux:table.column>{{ __('Category') }}</flux:table.column>
                            <flux:table.column>{{ __('Entries') }}</flux:table.column>
                            <flux:table.column>{{ __('Bouts') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column></flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($block->rows as $row)
                                <flux:table.row :key="$row->category->id">
                                    <flux:table.cell class="tabular-nums">{{ $row->start->format('H:i') }}–{{ $row->end->format('H:i') }}</flux:table.cell>
                                    <flux:table.cell>
                                        {{ $row->category->name }}
                                        <span class="text-xs text-zinc-500">{{ __(Str::headline($row->category->discipline->value)) }}</span>
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $row->entries }}</flux:table.cell>
                                    <flux:table.cell>{{ $row->bouts }}</flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge size="sm" :color="$this->progressColor($row->progress)">
                                            {{ match ($row->progress) {
                                                DrawProgress::NoDraw => __('No draw'),
                                                DrawProgress::Pending => __('Drawn'),
                                                DrawProgress::Running => __('In progress'),
                                                DrawProgress::Finished => __('Finished'),
                                            } }}
                                        </flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell class="text-end whitespace-nowrap">
                                        <flux:button size="sm" variant="ghost" icon="eye" :href="route('admin.competitions.bracket', [$competition, $row->category])" wire:navigate :tooltip="__('Show bracket')" />
                                        <flux:button size="sm" variant="ghost" icon="chevron-up" wire:click="move({{ $row->category->id }}, 'up')" :tooltip="__('Move up')" />
                                        <flux:button size="sm" variant="ghost" icon="chevron-down" wire:click="move({{ $row->category->id }}, 'down')" :tooltip="__('Move down')" />
                                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="unassign({{ $row->category->id }})" :tooltip="__('Remove from tatami')" />
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            </div>
        @endforeach
    </div>
</section>
