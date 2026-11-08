<?php

use App\Actions\Categories\ImportOfficialCategories;
use App\Enums\CategorySet;
use App\Enums\Discipline;
use App\Enums\Gender;
use App\Models\Category;
use App\Models\Competition;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Categories')] class extends Component {
    public Competition $competition;

    public ?int $editingId = null;
    public ?int $deletingId = null;

    public string $name = '';
    public string $discipline = 'kumite';
    public string $gender = '';
    public string $birth_year_from = '';
    public string $birth_year_to = '';
    public string $weight_min = '';
    public string $weight_max = '';
    public string $bout_duration_seconds = '120';

    public string $sourceId = '';
    public string $officialSet = '';

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
        return $this->competition->categories()->withCount(['entries' => fn ($entries) => $entries->present()])->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Competition>
     */
    #[Computed]
    public function sources(): Collection
    {
        return Competition::query()
            ->whereKeyNot($this->competition->id)
            ->whereHas('categories')
            ->withCount('categories')
            ->orderByDesc('date')
            ->get();
    }

    public function create(): void
    {
        $this->resetForm();

        Flux::modal('category-form')->show();
    }

    public function edit(int $id): void
    {
        $category = $this->competition->categories()->findOrFail($id);

        $this->resetForm();
        $this->editingId = $category->id;
        $this->name = $category->name;
        $this->discipline = $category->discipline->value;
        $this->gender = $category->gender?->value ?? '';
        $this->birth_year_from = (string) $category->birth_year_from;
        $this->birth_year_to = (string) $category->birth_year_to;
        $this->weight_min = $category->weight_min !== null ? (string) (float) $category->weight_min : '';
        $this->weight_max = $category->weight_max !== null ? (string) (float) $category->weight_max : '';
        $this->bout_duration_seconds = (string) $category->bout_duration_seconds;

        Flux::modal('category-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('categories', 'name')
                    ->where('competition_id', $this->competition->id)
                    ->ignore($this->editingId),
            ],
            'discipline' => ['required', Rule::enum(Discipline::class)],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'birth_year_from' => ['required', 'integer', 'between:1900,2100'],
            'birth_year_to' => ['required', 'integer', 'between:1900,2100', 'gte:birth_year_from'],
            'weight_min' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'weight_max' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'bout_duration_seconds' => ['required', 'integer', 'between:30,3600'],
        ]);

        if ($validated['weight_min'] !== '' && $validated['weight_max'] !== ''
            && (float) $validated['weight_max'] < (float) $validated['weight_min']) {
            $this->addError('weight_max', __('The maximum weight must not be lower than the minimum weight.'));

            return;
        }

        $attributes = [
            ...$validated,
            'gender' => $validated['gender'] ?: null,
            'weight_min' => $validated['weight_min'] !== '' ? $validated['weight_min'] : null,
            'weight_max' => $validated['weight_max'] !== '' ? $validated['weight_max'] : null,
        ];

        if ($this->editingId) {
            $category = $this->competition->categories()->findOrFail($this->editingId);

            if ($category->hasDraw()) {
                $this->addError('name', __('This category has a draw. Delete the draw before changing the category.'));

                return;
            }

            $category->update($attributes);
        } else {
            $this->competition->categories()->create($attributes);
        }

        unset($this->categories);
        Flux::modal('category-form')->close();
        Flux::toast(variant: 'success', text: __('Category saved.'));
        $this->resetForm();
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $this->competition->categories()->findOrFail($id)->id;

        Flux::modal('category-delete')->show();
    }

    public function delete(): void
    {
        $this->competition->categories()->findOrFail($this->deletingId)->delete();

        unset($this->categories);
        $this->deletingId = null;
        Flux::modal('category-delete')->close();
        Flux::toast(variant: 'success', text: __('Category deleted.'));
    }

    public function openCopy(): void
    {
        $this->reset('sourceId');
        $this->resetValidation();

        Flux::modal('category-copy')->show();
    }

    public function copy(): void
    {
        $this->validate([
            'sourceId' => ['required', 'integer', Rule::exists('competitions', 'id')->whereNot('id', $this->competition->id)],
        ]);

        $source = Competition::findOrFail($this->sourceId);
        $existing = $this->competition->categories()->pluck('name')->all();

        $copied = 0;
        $skipped = 0;

        foreach ($source->categories()->orderBy('sort_order')->orderBy('id')->get() as $category) {
            if (in_array($category->name, $existing, true)) {
                $skipped++;

                continue;
            }

            $this->competition->categories()->create($category->only([
                'name', 'discipline', 'gender', 'birth_year_from', 'birth_year_to',
                'weight_min', 'weight_max', 'bout_duration_seconds', 'sort_order',
            ]));
            $copied++;
        }

        unset($this->categories);
        Flux::modal('category-copy')->close();
        Flux::toast(
            variant: 'success',
            text: __(':copied categories copied, :skipped skipped (name already exists).', ['copied' => $copied, 'skipped' => $skipped]),
        );
    }

    public function openImportOfficial(): void
    {
        $this->reset('officialSet');
        $this->resetValidation();

        Flux::modal('category-import-official')->show();
    }

    public function importOfficial(ImportOfficialCategories $import): void
    {
        $this->validate([
            'officialSet' => ['required', Rule::enum(CategorySet::class)],
        ]);

        $result = $import->handle($this->competition, CategorySet::from($this->officialSet));

        unset($this->categories);
        Flux::modal('category-import-official')->close();
        Flux::toast(
            variant: 'success',
            text: __('Imported: :created, skipped: :skipped.', $result),
        );
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'gender', 'birth_year_from', 'birth_year_to', 'weight_min', 'weight_max']);
        $this->discipline = Discipline::Kumite->value;
        $this->bout_duration_seconds = '120';
        $this->resetValidation();
    }
}; ?>

<section class="w-full min-w-0 space-y-5">
    <div class="space-y-1">
        <a href="{{ route('admin.competitions.show', $competition) }}" wire:navigate class="inline-flex min-h-11 items-center gap-1.5 text-[13px] text-arena-muted">
            <flux:icon.chevron-left class="size-4" />{{ $competition->name }}
        </a>
        <h1 class="font-display text-4xl font-black uppercase leading-[.95] tracking-[.01em] text-arena-ink md:text-5xl">{{ __('Categories') }}</h1>
    </div>

    <x-arena.steps :competition="$competition" current="categories" />

    <div class="flex flex-wrap items-center gap-2">
        <x-arena.button variant="secondary" icon="arrow-down-tray" wire:click="openImportOfficial" data-test="import-official-button">{{ __('Import official categories') }}</x-arena.button>
        <x-arena.button variant="secondary" icon="document-duplicate" wire:click="openCopy" data-test="copy-categories-button">{{ __('Copy from competition') }}</x-arena.button>
        <x-arena.button icon="plus" wire:click="create" data-test="create-category-button">{{ __('New category') }}</x-arena.button>
    </div>

    @if ($this->categories->isNotEmpty())
        @php($groups = $this->categories->groupBy(fn ($category) => $category->birth_year_from.'–'.$category->birth_year_to))

        <div
            class="space-y-4"
            x-data="{
                q: '',
                f: '',
                match(el) {
                    const d = el.dataset
                    const q = this.q.trim().toLowerCase()

                    if (q !== '' && ! d.search.includes(q)) return false

                    return this.f === '' || d.kind === this.f
                },
            }"
        >
            <flux:input x-model="q" icon="magnifying-glass" type="search" :placeholder="__('Search categories')" :aria-label="__('Search categories')" />

            <div role="radiogroup" aria-label="{{ __('Filter categories') }}" class="flex flex-wrap gap-2">
                @foreach (['' => __('All'), 'kata' => __('Kata'), 'kumite' => __('Kumite')] as $value => $label)
                    <x-arena.chip name="admin-category-filter" :value="$value" :label="$label" x-model="f" />
                @endforeach
            </div>

            @foreach ($groups as $years => $items)
                @php($first = $items->first())

                <details open class="relative border border-arena-line bg-arena-surface" x-bind:hidden="! [...$el.querySelectorAll('[data-cat]')].some((row) => match(row))">
                    <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 bg-arena-bg-2 px-3 [&::-webkit-details-marker]:hidden">
                        <span class="font-display text-xl font-extrabold uppercase tracking-[.04em]">{{ str_contains($first->name, ' - ') ? Str::before($first->name, ' - ') : $years }}</span>
                        <span class="font-mono text-xs tabular-nums text-arena-muted">{{ $years }} · {{ $items->count() }}</span>
                    </summary>

                    <div class="relative overflow-x-auto">
                        <table class="w-full text-start text-sm">
                            <thead>
                                <tr class="border-b border-arena-line text-xs uppercase tracking-[.1em] text-arena-muted">
                                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Name') }}</th>
                                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Discipline') }}</th>
                                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Gender') }}</th>
                                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Weight (kg)') }}</th>
                                    <th scope="col" class="px-4 py-3 font-semibold text-end">{{ __('Entries') }}</th>
                                    <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                                </tr>
                            </thead>

                            <tbody>
                                @foreach ($items as $category)
                                    <tr
                                        class="border-t border-arena-line"
                                        data-cat
                                        data-search="{{ Str::lower($category->name) }}"
                                        data-kind="{{ $category->discipline->value }}"
                                        wire:key="category-row-{{ $category->id }}"
                                        x-bind:hidden="! match($el)"
                                    >
                                        <td class="px-4 py-2 font-bold">{{ $category->name }}</td>
                                        <td class="px-4 py-2">{{ __(Str::headline($category->discipline->value)) }}</td>
                                        <td class="px-4 py-2">{{ $category->gender ? __(Str::headline($category->gender->value)) : __('Mixed') }}</td>
                                        <td class="px-4 py-2 font-mono tabular-nums">
                                            @if ($category->weight_min === null && $category->weight_max === null)
                                                —
                                            @else
                                                {{ $category->weight_min !== null ? (float) $category->weight_min : '' }}–{{ $category->weight_max !== null ? (float) $category->weight_max : '' }}
                                            @endif
                                        </td>
                                        <td @class(['px-4 py-2 text-end font-mono tabular-nums', 'font-bold text-arena-warn' => $category->entries_count === 0])>{{ $category->entries_count }}</td>
                                        <td class="whitespace-nowrap px-2 py-1 text-end">
                                            <x-arena.button variant="ghost" icon="pencil-square" class="min-w-11" :aria-label="__('Edit')" wire:click="edit({{ $category->id }})" />
                                            <x-arena.button variant="ghost" icon="trash" class="min-w-11" :aria-label="__('Delete')" wire:click="confirmDelete({{ $category->id }})" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @endforeach
        </div>
    @endif

    <flux:modal name="category-form" class="md:w-96">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit category') : __('New category') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" type="text" required />

            <div class="grid grid-cols-2 gap-4">
                <flux:select wire:model="discipline" :label="__('Discipline')">
                    @foreach (Discipline::cases() as $case)
                        <flux:select.option :value="$case->value">{{ __(Str::headline($case->value)) }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="gender" :label="__('Gender')">
                    <flux:select.option value="">{{ __('Mixed') }}</flux:select.option>
                    @foreach (Gender::cases() as $case)
                        <flux:select.option :value="$case->value">{{ __(Str::headline($case->value)) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="birth_year_from" :label="__('Born from (year)')" type="number" min="1900" max="2100" required />
                <flux:input wire:model="birth_year_to" :label="__('Born to (year)')" type="number" min="1900" max="2100" required />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="weight_min" :label="__('Weight from (kg)')" type="number" step="0.01" min="0" />
                <flux:input wire:model="weight_max" :label="__('Weight to (kg)')" type="number" step="0.01" min="0" />
            </div>

            <flux:input wire:model="bout_duration_seconds" :label="__('Bout duration (seconds)')" type="number" min="30" max="3600" required />

            <div class="flex">
                <flux:spacer />
                <flux:button type="submit" variant="primary" data-test="save-category-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="category-copy" class="md:w-96">
        <form wire:submit="copy" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Copy categories') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Categories are copied without tatami assignment and entries. Categories whose name already exists here are skipped.') }}</flux:text>
            </div>

            <flux:select wire:model="sourceId" :label="__('Copy from')" :placeholder="__('Choose a competition')">
                @foreach ($this->sources as $source)
                    <flux:select.option :value="$source->id">
                        {{ $source->name }} ({{ $source->date->format('d.m.Y.') }}) – {{ $source->categories_count }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex">
                <flux:spacer />
                <flux:button type="submit" variant="primary" data-test="confirm-copy-button">{{ __('Copy') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="category-import-official" class="md:w-96">
        <form wire:submit="importOfficial" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Import official categories') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Individual kata and kumite categories from the official HKS list for 2026. Birth years are calculated from the competition date. Categories whose name already exists here are skipped.') }}</flux:text>
            </div>

            <flux:select wire:model="officialSet" :label="__('Category set')" :placeholder="__('Choose a category set')">
                @foreach (CategorySet::cases() as $case)
                    <flux:select.option :value="$case->value">{{ __(Str::headline($case->value)) }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex">
                <flux:spacer />
                <flux:button type="submit" variant="primary" data-test="confirm-import-official-button">{{ __('Import') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="category-delete" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete category?') }}</flux:heading>
                <flux:text class="mt-2">{{ __('All entries and results in this category will be deleted too. This action cannot be undone.') }}</flux:text>
            </div>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete" data-test="confirm-delete-category-button">
                    {{ __('Delete') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
