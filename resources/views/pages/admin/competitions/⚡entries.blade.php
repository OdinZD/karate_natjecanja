<?php

use App\Actions\Entries\EntryEligibility;
use App\Actions\Entries\ImportEntries;
use App\Models\Category;
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
}; ?>

<section class="w-full">
    <div class="mb-6">
        <flux:link :href="route('admin.competitions.show', $competition)" wire:navigate class="text-sm">
            &larr; {{ $competition->name }}
        </flux:link>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <flux:heading size="xl" level="1">{{ __('Entries') }}</flux:heading>
            <div class="flex flex-wrap items-center gap-2">
                <flux:select wire:model.live="categoryFilter" class="min-w-56">
                    <flux:select.option value="">{{ __('All categories') }}</flux:select.option>
                    @foreach ($this->categories as $category)
                        <flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:button icon="arrow-up-tray" wire:click="openImport" data-test="import-entries-button">
                    {{ __('Import from Excel') }}
                </flux:button>
                <flux:button variant="primary" icon="plus" wire:click="create" data-test="create-entry-button">
                    {{ __('New entry') }}
                </flux:button>
            </div>
        </div>
    </div>

    <flux:table :paginate="$this->entries">
        <flux:table.columns>
            <flux:table.column>{{ __('Competitor') }}</flux:table.column>
            <flux:table.column>{{ __('Club') }}</flux:table.column>
            <flux:table.column>{{ __('Category') }}</flux:table.column>
            <flux:table.column>{{ __('Weight (kg)') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->entries as $entry)
                <flux:table.row :key="$entry->id">
                    <flux:table.cell>{{ $entry->competitor->last_name }} {{ $entry->competitor->first_name }}</flux:table.cell>
                    <flux:table.cell>{{ $entry->competitor->club->name }}</flux:table.cell>
                    <flux:table.cell>{{ $entry->category->name }}</flux:table.cell>
                    <flux:table.cell>{{ $entry->weight !== null ? (float) $entry->weight : '—' }}</flux:table.cell>
                    <flux:table.cell class="text-end">
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $entry->id }})" wire:confirm="{{ __('Delete this entry?') }}" />
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

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
</section>
