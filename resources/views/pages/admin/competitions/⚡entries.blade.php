<?php

use App\Actions\Entries\EntryEligibility;
use App\Actions\Entries\ImportClubEntries;
use App\Actions\Entries\ImportEntries;
use App\Models\Category;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Competitor;
use App\Models\Entry;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('Entries')] class extends Component {
    use WithFileUploads;
    use WithPagination;

    public Competition $competition;

    public string $categoryFilter = '';

    public string $categoryId = '';
    public string $competitorSearch = '';
    public string $competitorId = '';
    public string $weight = '';

    public ?TemporaryUploadedFile $file = null;

    /** @var array<int, string> */
    public array $importErrors = [];
    public int $importErrorCount = 0;

    public string $clubId = '';
    public ?TemporaryUploadedFile $clubFile = null;

    /** @var array<int, string> */
    public array $clubImportErrors = [];
    public int $clubImportErrorCount = 0;

    public function mount(Competition $competition): void
    {
        $this->competition = $competition;
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return $this->competition->categories()->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Club>
     */
    #[Computed]
    public function clubs(): Collection
    {
        return Club::query()->orderBy('name')->orderBy('city')->get();
    }

    /**
     * @return LengthAwarePaginator<int, Entry>
     */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        return Entry::query()
            ->whereHas('category', fn ($query) => $query->where('competition_id', $this->competition->id))
            ->when($this->categoryFilter !== '', fn ($query) => $query->where('category_id', $this->categoryFilter))
            ->with(['competitor.club', 'category'])
            ->orderBy('category_id')
            ->orderBy('id')
            ->paginate(25);
    }

    /**
     * @return Collection<int, Competitor>
     */
    #[Computed]
    public function competitorResults(): Collection
    {
        $term = trim($this->competitorSearch);

        if (mb_strlen($term) < 2) {
            return new Collection;
        }

        return Competitor::query()
            ->with('club')
            ->where(fn ($query) => $query
                ->where('first_name', 'like', '%'.$term.'%')
                ->orWhere('last_name', 'like', '%'.$term.'%'))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(25)
            ->get();
    }

    public function create(): void
    {
        $this->reset(['categoryId', 'competitorSearch', 'competitorId', 'weight']);
        $this->categoryId = $this->categoryFilter;
        $this->resetValidation();

        Flux::modal('entry-form')->show();
    }

    public function save(EntryEligibility $eligibility): void
    {
        $validated = $this->validate([
            'categoryId' => ['required', 'integer', Rule::exists('categories', 'id')->where('competition_id', $this->competition->id)],
            'competitorId' => ['required', 'integer', Rule::exists('competitors', 'id')],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
        ]);

        $category = $this->competition->categories()->findOrFail($validated['categoryId']);
        $competitor = Competitor::findOrFail($validated['competitorId']);
        $weight = $validated['weight'] !== '' ? $validated['weight'] : null;

        if ($category->hasDraw()) {
            $this->addError('categoryId', __('This category already has a draw. Delete the draw first to change its entries.'));

            return;
        }

        if ($category->entries()->where('competitor_id', $competitor->id)->exists()) {
            $this->addError('competitorId', __('The competitor is already entered in this category.'));

            return;
        }

        $violations = $eligibility->violations($category, $competitor, $weight);

        if ($violations !== []) {
            $this->addError('competitorId', implode(' ', $violations));

            return;
        }

        $category->entries()->create([
            'competitor_id' => $competitor->id,
            'weight' => $weight,
        ]);

        unset($this->entries);
        Flux::modal('entry-form')->close();
        Flux::toast(variant: 'success', text: __('Entry saved.'));
        $this->reset(['competitorSearch', 'competitorId', 'weight']);
    }

    public function delete(int $id): void
    {
        $entry = Entry::query()
            ->whereHas('category', fn ($query) => $query->where('competition_id', $this->competition->id))
            ->with('category')
            ->findOrFail($id);

        if ($entry->category->hasDraw()) {
            Flux::toast(variant: 'danger', text: __('This category already has a draw. Delete the draw first to change its entries.'));

            return;
        }

        $entry->delete();

        unset($this->entries);
        Flux::toast(variant: 'success', text: __('Entry deleted.'));
    }

    public function openImport(): void
    {
        $this->reset(['file', 'importErrors', 'importErrorCount']);
        $this->resetValidation();

        Flux::modal('entries-import')->show();
    }

    public function import(ImportEntries $importer): void
    {
        $this->validate([
            'file' => ['required', 'file', 'extensions:xlsx,csv', 'max:5120'],
        ]);

        try {
            $result = $importer->handle($this->competition, $this->file->getRealPath(), $this->file->getClientOriginalExtension());
        } finally {
            $this->file->delete();
            $this->reset('file');
        }

        if ($result->failed()) {
            $this->importErrorCount = count($result->errors);
            $this->importErrors = array_slice($result->errors, 0, 50);

            return;
        }

        $this->reset(['importErrors', 'importErrorCount']);
        unset($this->entries, $this->categories);
        Flux::modal('entries-import')->close();
        Flux::toast(
            variant: 'success',
            text: __(':entries entries imported (:competitors new competitors, :clubs new clubs), :skipped already entered.', [
                'entries' => $result->entriesCreated,
                'competitors' => $result->competitorsCreated,
                'clubs' => $result->clubsCreated,
                'skipped' => $result->entriesSkipped,
            ]),
        );
    }

    public function openClubImport(): void
    {
        $this->reset(['clubId', 'clubFile', 'clubImportErrors', 'clubImportErrorCount']);
        $this->resetValidation();

        Flux::modal('club-entries-import')->show();
    }

    public function importClub(ImportClubEntries $importer): void
    {
        $this->validate([
            'clubId' => ['required', 'integer', Rule::exists('clubs', 'id')],
            'clubFile' => ['required', 'file', 'extensions:xlsx,csv', 'max:5120'],
        ]);

        $club = Club::findOrFail($this->clubId);

        try {
            $result = $importer->handle($this->competition, $club, $this->clubFile->getRealPath(), $this->clubFile->getClientOriginalExtension());
        } finally {
            $this->clubFile->delete();
            $this->reset('clubFile');
        }

        if ($result->failed()) {
            $this->clubImportErrorCount = count($result->errors);
            $this->clubImportErrors = array_slice($result->errors, 0, 50);

            return;
        }

        $this->reset(['clubImportErrors', 'clubImportErrorCount']);
        unset($this->entries, $this->categories);
        Flux::modal('club-entries-import')->close();
        Flux::toast(
            variant: 'success',
            text: __(':entries entries imported (:competitors new competitors), :skipped already entered.', [
                'entries' => $result->entriesCreated,
                'competitors' => $result->competitorsCreated,
                'skipped' => $result->entriesSkipped,
            ]),
        );
    }
}; ?>

<section class="w-full min-w-0 space-y-5">
    <div class="space-y-1">
        <a href="{{ route('admin.competitions.show', $competition) }}" wire:navigate class="inline-flex min-h-11 items-center gap-1.5 text-[13px] text-arena-muted">
            <flux:icon.chevron-left class="size-4" />{{ $competition->name }}
        </a>
        <h1 class="font-display text-4xl font-black uppercase leading-[.95] tracking-[.01em] text-arena-ink md:text-5xl">{{ __('Entries') }}</h1>
    </div>

    <x-arena.steps :competition="$competition" current="entries" />

    <div class="flex flex-wrap items-center gap-2">
        <flux:select wire:model.live="categoryFilter" class="min-w-56" :aria-label="__('Category')">
            <flux:select.option value="">{{ __('All categories') }}</flux:select.option>
            @foreach ($this->categories as $category)
                <flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <x-arena.button variant="secondary" icon="arrow-down-tray" :href="route('admin.competitions.entries.template', $competition)" data-test="download-template-button">{{ __('Template for clubs') }}</x-arena.button>

        <flux:dropdown align="end">
            <x-arena.button variant="secondary" icon="arrow-up-tray">{{ __('Import') }}</x-arena.button>

            <flux:menu>
                <flux:menu.item icon="user-group" wire:click="openClubImport" data-test="import-club-entries-button">{{ __('Import club entries') }}</flux:menu.item>
                <flux:menu.item icon="arrow-up-tray" wire:click="openImport" data-test="import-entries-button">{{ __('Import from Excel') }}</flux:menu.item>
            </flux:menu>
        </flux:dropdown>

        <x-arena.button icon="plus" wire:click="create" data-test="create-entry-button">{{ __('New entry') }}</x-arena.button>
    </div>

    <x-arena.card class="overflow-x-auto">
        <table class="w-full text-start text-sm">
            <thead>
                <tr class="border-b border-arena-line text-xs uppercase tracking-[.1em] text-arena-muted">
                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Competitor') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Club') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Category') }}</th>
                    <th scope="col" class="px-4 py-3 font-semibold text-end">{{ __('Weight (kg)') }}</th>
                    <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('Delete') }}</span></th>
                </tr>
            </thead>

            <tbody>
                @foreach ($this->entries as $entry)
                    <tr class="border-b border-arena-line last:border-b-0" wire:key="entry-row-{{ $entry->id }}">
                        <td class="px-4 py-2 font-bold">{{ $entry->competitor->last_name }} {{ $entry->competitor->first_name }}</td>
                        <td class="px-4 py-2">{{ $entry->competitor->club->name }}</td>
                        <td class="px-4 py-2">{{ $entry->category->name }}</td>
                        <td class="px-4 py-2 text-end font-mono tabular-nums">{{ $entry->weight !== null ? (float) $entry->weight : '—' }}</td>
                        <td class="whitespace-nowrap px-2 py-1 text-end">
                            <x-arena.button variant="ghost" icon="trash" class="min-w-11" :aria-label="__('Delete')" wire:click="delete({{ $entry->id }})" wire:confirm="{{ __('Delete this entry?') }}" />
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-arena.card>

    <flux:pagination :paginator="$this->entries" />

    <flux:modal name="entry-form" class="md:w-96">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ __('New entry') }}</flux:heading>

            <flux:select wire:model="categoryId" :label="__('Category')" :placeholder="__('Choose a category')">
                @foreach ($this->categories as $category)
                    <flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model.live.debounce.300ms="competitorSearch" icon="magnifying-glass" :label="__('Find competitor')" :placeholder="__('Type at least 2 letters of the name')" />

            <flux:select wire:model="competitorId" :label="__('Competitor')" :placeholder="__('Choose a competitor')">
                @foreach ($this->competitorResults as $competitor)
                    <flux:select.option :value="$competitor->id">
                        {{ $competitor->last_name }} {{ $competitor->first_name }} ({{ $competitor->birth_date->format('Y') }}, {{ $competitor->club->name }})
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="weight" :label="__('Weight (kg)')" type="number" step="0.01" min="0" :description="__('Optional. If entered, it must fit the weight range of the category.')" />

            <div class="flex">
                <flux:spacer />
                <flux:button type="submit" variant="primary" data-test="save-entry-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="entries-import" class="md:w-[32rem]">
        <form wire:submit="import" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Import from Excel') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Upload an XLSX or CSV file. The first row must contain the column names: Klub, Grad (optional), Ime, Prezime, Spol, Datum rođenja, Kategorija, Težina (optional). If any row is invalid, nothing is imported.') }}
                </flux:text>
            </div>

            <flux:input wire:model="file" type="file" accept=".xlsx,.csv" :label="__('File')" />

            @if ($importErrors !== [])
                <flux:callout variant="danger" icon="exclamation-triangle" data-test="import-errors">
                    <flux:callout.heading>{{ __('Nothing was imported. Fix these rows and try again:') }}</flux:callout.heading>
                    <flux:callout.text>
                        <ul class="list-disc ps-5">
                            @foreach ($importErrors as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                        @if ($importErrorCount > count($importErrors))
                            <p class="mt-2">{{ __('and :count more', ['count' => $importErrorCount - count($importErrors)]) }}</p>
                        @endif
                    </flux:callout.text>
                </flux:callout>
            @endif

            <div class="flex">
                <flux:spacer />
                <flux:button type="submit" variant="primary" data-test="run-import-button">{{ __('Import') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="club-entries-import" class="md:w-[32rem]">
        <form wire:submit="importClub" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Import club entries') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Upload the template filled in by the club. Categories are assigned automatically from gender, birth year and weight. If any row is invalid, nothing is imported.') }}
                </flux:text>
            </div>

            <flux:select wire:model="clubId" :label="__('Club')" :placeholder="__('Choose a club')">
                @foreach ($this->clubs as $club)
                    <flux:select.option :value="$club->id">{{ $club->name }}{{ $club->city ? ' ('.$club->city.')' : '' }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="clubFile" type="file" accept=".xlsx,.csv" :label="__('File')" />

            @if ($clubImportErrors !== [])
                <flux:callout variant="danger" icon="exclamation-triangle" data-test="club-import-errors">
                    <flux:callout.heading>{{ __('Nothing was imported. Fix these rows and try again:') }}</flux:callout.heading>
                    <flux:callout.text>
                        <ul class="list-disc ps-5">
                            @foreach ($clubImportErrors as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                        @if ($clubImportErrorCount > count($clubImportErrors))
                            <p class="mt-2">{{ __('and :count more', ['count' => $clubImportErrorCount - count($clubImportErrors)]) }}</p>
                        @endif
                    </flux:callout.text>
                </flux:callout>
            @endif

            <div class="flex">
                <flux:spacer />
                <flux:button type="submit" variant="primary" data-test="run-club-import-button">{{ __('Import') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
