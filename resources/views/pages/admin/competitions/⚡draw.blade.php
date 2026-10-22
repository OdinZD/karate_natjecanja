<?php

use App\Actions\Draw\DeleteDraw;
use App\Actions\Draw\DrawException;
use App\Actions\Draw\GenerateDraw;
use App\Enums\BoutDecision;
use App\Enums\BoutStatus;
use App\Models\Category;
use App\Models\Competition;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Draw')] class extends Component {
    public Competition $competition;

    public ?int $seedCategoryId = null;

    /** @var array<int, string> */
    public array $seeds = [];

    public function mount(Competition $competition): void
    {
        $this->competition = $competition;
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return $this->competition->categories()
            ->withCount([
                'entries',
                'bouts as bouts_count' => fn ($query) => $query->where(
                    fn ($inner) => $inner->whereNull('decision')->orWhere('decision', '!=', BoutDecision::Bye),
                ),
                'bouts as played_bouts_count' => fn ($query) => $query
                    ->where('status', BoutStatus::Finished)
                    ->where('decision', '!=', BoutDecision::Bye),
                'bouts as any_bouts_count',
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function generate(int $id, GenerateDraw $generator): void
    {
        $category = $this->competition->categories()->findOrFail($id);

        try {
            $generator->handle($category);
        } catch (DrawException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        unset($this->categories);
        Flux::toast(variant: 'success', text: __('Draw generated.'));
    }

    public function generateAll(GenerateDraw $generator): void
    {
        $generated = 0;
        $skipped = [];

        foreach ($this->competition->categories()->withCount('entries')->orderBy('sort_order')->orderBy('name')->get() as $category) {
            if ($category->hasDraw()) {
                continue;
            }

            if ($category->entries_count < 2) {
                $skipped[] = $category->name.' ('.__('fewer than 2 entries').')';

                continue;
            }

            try {
                $generator->handle($category);
                $generated++;
            } catch (DrawException $exception) {
                $skipped[] = $category->name.' ('.$exception->getMessage().')';
            }
        }

        unset($this->categories);

        Flux::toast(
            variant: $generated > 0 ? 'success' : 'warning',
            text: $skipped === []
                ? __(':count draws generated.', ['count' => $generated])
                : __(':count draws generated. Skipped: :names', ['count' => $generated, 'names' => implode(', ', $skipped)]),
        );
    }

    public function delete(int $id, DeleteDraw $deleter): void
    {
        $category = $this->competition->categories()->findOrFail($id);

        try {
            $deleter->handle($category);
        } catch (DrawException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        unset($this->categories);
        Flux::toast(variant: 'success', text: __('Draw deleted.'));
    }

    public function openSeeds(int $id): void
    {
        $category = $this->competition->categories()->findOrFail($id);

        if ($category->hasDraw()) {
            Flux::toast(variant: 'danger', text: __('This category already has a draw. Delete the draw first to change the seeds.'));

            return;
        }

        $this->seedCategoryId = $category->id;
        $this->seeds = $category->entries()->get()
            ->mapWithKeys(fn ($entry) => [$entry->id => $entry->seed !== null ? (string) $entry->seed : ''])
            ->all();
        $this->resetValidation();

        Flux::modal('draw-seeds')->show();
    }

    #[Computed]
    public function seedEntries(): Collection
    {
        if ($this->seedCategoryId === null) {
            return new Collection;
        }

        return $this->competition->categories()->findOrFail($this->seedCategoryId)
            ->entries()->with('competitor.club')->get()
            ->sortBy(fn ($entry) => $entry->competitor->last_name.' '.$entry->competitor->first_name)
            ->values();
    }

    public function saveSeeds(): void
    {
        $category = $this->competition->categories()->findOrFail($this->seedCategoryId);

        if ($category->hasDraw()) {
            Flux::toast(variant: 'danger', text: __('This category already has a draw. Delete the draw first to change the seeds.'));

            return;
        }

        $entryCount = $category->entries()->count();

        $this->validate([
            'seeds.*' => ['nullable', 'integer', 'min:1', 'max:'.$entryCount],
        ]);

        $entries = $category->entries()->get();
        $seedOf = fn ($entry) => trim((string) ($this->seeds[$entry->id] ?? '')) !== ''
            ? (int) trim((string) $this->seeds[$entry->id])
            : null;

        $given = $entries->map($seedOf)->filter(fn ($seed) => $seed !== null);

        if ($given->count() !== $given->unique()->count()) {
            $this->addError('seeds', __('Each seed number can be used only once.'));

            return;
        }

        foreach ($entries as $entry) {
            $entry->update(['seed' => $seedOf($entry)]);
        }

        Flux::modal('draw-seeds')->close();
        Flux::toast(variant: 'success', text: __('Seeds saved.'));
    }
}; ?>

<section class="w-full">
    <div class="mb-6">
        <flux:link :href="route('admin.competitions.show', $competition)" wire:navigate class="text-sm">
            &larr; {{ $competition->name }}
        </flux:link>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <flux:heading size="xl" level="1">{{ __('Draw') }}</flux:heading>
            <div class="flex flex-wrap items-center gap-2">
                @if ($this->categories->contains(fn ($category) => $category->any_bouts_count > 0))
                    <flux:button icon="document-arrow-down" :href="route('admin.competitions.draw.pdf', $competition)" target="_blank" data-test="draw-pdf-link">
                        {{ __('All draws (PDF)') }}
                    </flux:button>
                @endif
                <flux:button variant="primary" icon="sparkles" wire:click="generateAll" wire:confirm="{{ __('Generate draws for all categories that do not have one yet?') }}" data-test="generate-all-button">
                    {{ __('Generate all') }}
                </flux:button>
            </div>
        </div>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Category') }}</flux:table.column>
            <flux:table.column>{{ __('Discipline') }}</flux:table.column>
            <flux:table.column>{{ __('Entries') }}</flux:table.column>
            <flux:table.column>{{ __('Draw') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->categories as $category)
                <flux:table.row :key="$category->id">
                    <flux:table.cell>{{ $category->name }}</flux:table.cell>
                    <flux:table.cell>{{ __(Str::headline($category->discipline->value)) }}</flux:table.cell>
                    <flux:table.cell>{{ $category->entries_count }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($category->any_bouts_count === 0)
                            <flux:badge size="sm" color="zinc">{{ __('No draw') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="green">
                                {{ __(':played of :total bouts played', ['played' => $category->played_bouts_count, 'total' => $category->bouts_count]) }}
                            </flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-end">
                        @if ($category->any_bouts_count === 0)
                            <flux:button size="sm" variant="ghost" icon="numbered-list" wire:click="openSeeds({{ $category->id }})" :tooltip="__('Seeds')" />
                            <flux:button size="sm" variant="ghost" icon="play" wire:click="generate({{ $category->id }})" :tooltip="__('Generate draw')" data-test="generate-button-{{ $category->id }}" />
                        @else
                            <flux:button size="sm" variant="ghost" icon="eye" :href="route('admin.competitions.bracket', [$competition, $category])" wire:navigate :tooltip="__('Show bracket')" />
                            <flux:button size="sm" variant="ghost" icon="document-arrow-down" :href="route('admin.competitions.bracket.pdf', [$competition, $category])" target="_blank" :tooltip="__('Print PDF')" data-test="bracket-pdf-link-{{ $category->id }}" />
                            <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="generate({{ $category->id }})" wire:confirm="{{ __('Generate a new draw? The current one will be replaced.') }}" :tooltip="__('Regenerate draw')" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $category->id }})" wire:confirm="{{ __('Delete the draw of this category?') }}" :tooltip="__('Delete draw')" />
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <flux:modal name="draw-seeds" class="md:w-[28rem]">
        <form wire:submit="saveSeeds" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Seeds') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Seeded competitors get the best positions and byes. Leave empty for an unseeded draw.') }}</flux:text>
            </div>

            <div class="max-h-96 space-y-3 overflow-y-auto pe-1">
                @foreach ($this->seedEntries as $entry)
                    <div class="flex items-center gap-3" wire:key="seed-{{ $entry->id }}">
                        <flux:input wire:model="seeds.{{ $entry->id }}" type="number" min="1" class="w-20" />
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium">{{ $entry->competitor->last_name }} {{ $entry->competitor->first_name }}</div>
                            <div class="truncate text-xs text-zinc-500">{{ $entry->competitor->club->name }}</div>
                        </div>
                    </div>
                @endforeach
            </div>

            <flux:error name="seeds" />
            @error('seeds.*')
                <flux:text class="text-sm text-red-600">{{ $message }}</flux:text>
            @enderror

            <div class="flex">
                <flux:spacer />
                <flux:button type="submit" variant="primary" data-test="save-seeds-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
