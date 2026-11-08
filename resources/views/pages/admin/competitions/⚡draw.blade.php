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
                'entries' => fn ($query) => $query->present(),
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

        foreach ($this->competition->categories()->withCount(['entries' => fn ($entries) => $entries->present()])->orderBy('sort_order')->orderBy('name')->get() as $category) {
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
        $this->seeds = $category->entries()->present()->get()
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
            ->entries()->present()->with('competitor.club')->get()
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

        $entryCount = $category->entries()->present()->count();

        $this->validate([
            'seeds.*' => ['nullable', 'integer', 'min:1', 'max:'.$entryCount],
        ]);

        $entries = $category->entries()->present()->get();
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

<section class="w-full min-w-0 space-y-5">
    <div class="space-y-1">
        <a href="{{ route('admin.competitions.show', $competition) }}" wire:navigate class="inline-flex min-h-11 items-center gap-1.5 text-[13px] text-arena-muted">
            <flux:icon.chevron-left class="size-4" />{{ $competition->name }}
        </a>
        <h1 class="font-display text-4xl font-black uppercase leading-[.95] tracking-[.01em] text-arena-ink md:text-5xl">{{ __('Draw') }}</h1>
    </div>

    <x-arena.steps :competition="$competition" current="draw" />

    <div class="flex flex-wrap items-center gap-2">
        @if ($this->categories->contains(fn ($category) => $category->any_bouts_count > 0))
            <x-arena.button variant="secondary" icon="document-arrow-down" :href="route('admin.competitions.draw.pdf', $competition)" target="_blank" data-test="draw-pdf-link">{{ __('All draws (PDF)') }}<span class="sr-only"> ({{ __('Opens in a new tab') }})</span></x-arena.button>
        @endif

        <x-arena.button icon="sparkles" wire:click="generateAll" wire:confirm="{{ __('Generate draws for all categories that do not have one yet?') }}" data-test="generate-all-button">{{ __('Generate all') }}</x-arena.button>
    </div>

    <p class="text-sm text-arena-muted">{{ __(':count of :total categories drawn', ['count' => $this->categories->where('any_bouts_count', '>', 0)->count(), 'total' => $this->categories->count()]) }}</p>

    <x-arena.card class="overflow-x-auto">
        <table class="w-full text-start text-sm">
            <thead>
                <tr class="border-b border-arena-line text-xs uppercase tracking-[.1em] text-arena-muted">
                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Category') }}</th>
                    <th scope="col" class="px-4 py-3 font-semibold text-end">{{ __('Entries') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Draw') }}</th>
                    <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                </tr>
            </thead>

            <tbody>
                @foreach ($this->categories as $category)
                    @php
                        $locked = $category->played_bouts_count > 0;

                        $drawState = match (true) {
                            $category->any_bouts_count === 0 => 'none',
                            $category->played_bouts_count === $category->bouts_count => 'done',
                            $category->played_bouts_count > 0 => 'live',
                            default => 'drawn',
                        };
                    @endphp

                    <tr class="border-b border-arena-line last:border-b-0" wire:key="draw-row-{{ $category->id }}">
                        <td class="px-4 py-2">
                            <div class="font-bold">{{ $category->name }}</div>
                            <div class="text-xs text-arena-muted">{{ __(Str::headline($category->discipline->value)) }}</div>
                        </td>
                        <td class="px-4 py-2 text-end font-mono tabular-nums">{{ $category->entries_count }}</td>
                        <td class="px-4 py-2">
                            @if ($category->any_bouts_count === 0)
                                <x-arena.status state="none" :label="__('No draw')" />
                            @else
                                <x-arena.status :state="$drawState" :label="__(':played of :total bouts played', ['played' => $category->played_bouts_count, 'total' => $category->bouts_count])" />
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-2 py-1 text-end">
                            @if ($category->any_bouts_count === 0)
                                <x-arena.button variant="ghost" icon="numbered-list" class="min-w-11" :aria-label="__('Seeds')" wire:click="openSeeds({{ $category->id }})" />
                                <x-arena.button variant="secondary" icon="play" wire:click="generate({{ $category->id }})" data-test="generate-button-{{ $category->id }}">{{ __('Generate draw') }}</x-arena.button>
                            @else
                                <x-arena.button variant="secondary" icon="eye" :href="route('admin.competitions.bracket', [$competition, $category])" wire:navigate>{{ __('Show bracket') }}</x-arena.button>
                                <x-arena.button variant="ghost" icon="document-arrow-down" class="min-w-11" :href="route('admin.competitions.bracket.pdf', [$competition, $category])" target="_blank" :aria-label="__('Print PDF').' ('.__('Opens in a new tab').')'" data-test="bracket-pdf-link-{{ $category->id }}" />

                                <flux:dropdown align="end">
                                    <x-arena.button variant="ghost" icon="ellipsis-horizontal" class="min-w-11" :aria-label="__('More actions')" />

                                    <flux:menu>
                                        <flux:menu.item icon="arrow-path" :disabled="$locked" :aria-describedby="$locked ? 'draw-lock-'.$category->id : null" wire:click="generate({{ $category->id }})" wire:confirm="{{ __('Generate a new draw? The current one will be replaced.') }}">{{ __('Regenerate draw') }}</flux:menu.item>
                                        <flux:menu.item icon="trash" variant="danger" :disabled="$locked" :aria-describedby="$locked ? 'draw-lock-'.$category->id : null" wire:click="delete({{ $category->id }})" wire:confirm="{{ __('Delete the draw of this category?') }}">{{ __('Delete draw') }}</flux:menu.item>

                                        @if ($locked)
                                            <div id="draw-lock-{{ $category->id }}" class="px-3 py-2 text-xs text-arena-muted">{{ __('Not possible: :count bouts have been played', ['count' => $category->played_bouts_count]) }}</div>
                                        @endif
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-arena.card>

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
                            <div class="truncate text-xs text-arena-muted">{{ $entry->competitor->club->name }}</div>
                        </div>
                    </div>
                @endforeach
            </div>

            <flux:error name="seeds" />
            @error('seeds.*')
                <flux:text class="text-sm text-arena-aka-text">{{ $message }}</flux:text>
            @enderror

            <div class="flex">
                <flux:spacer />
                <flux:button type="submit" variant="primary" data-test="save-seeds-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
