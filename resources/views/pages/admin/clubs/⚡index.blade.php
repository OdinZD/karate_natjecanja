<?php

use App\Models\Club;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Clubs')] class extends Component {
    use WithPagination;

    public string $search = '';

    public ?int $editingId = null;
    public ?int $deletingId = null;

    public string $name = '';
    public string $city = '';
    public string $country = 'HR';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Club>
     */
    #[Computed]
    public function clubs(): LengthAwarePaginator
    {
        return Club::query()
            ->withCount('competitors')
            ->when($this->search !== '', fn ($query) => $query->where('name', 'like', '%'.$this->search.'%'))
            ->orderBy('name')
            ->paginate(15);
    }

    public function create(): void
    {
        $this->resetForm();

        Flux::modal('club-form')->show();
    }

    public function edit(int $id): void
    {
        $club = Club::findOrFail($id);

        $this->resetForm();
        $this->editingId = $club->id;
        $this->name = $club->name;
        $this->city = $club->city ?? '';
        $this->country = $club->country;

        Flux::modal('club-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('clubs', 'name')->where('city', $this->city ?: null)->ignore($this->editingId),
            ],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['required', 'string', 'alpha', 'size:2'],
        ]);

        $attributes = [
            ...$validated,
            'city' => $validated['city'] ?: null,
            'country' => strtoupper($validated['country']),
        ];

        if ($this->editingId) {
            Club::findOrFail($this->editingId)->update($attributes);
        } else {
            Club::create($attributes);
        }

        Flux::modal('club-form')->close();
        Flux::toast(variant: 'success', text: __('Club saved.'));
        $this->resetForm();
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;

        Flux::modal('club-delete')->show();
    }

    public function delete(): void
    {
        $club = Club::findOrFail($this->deletingId);

        Flux::modal('club-delete')->close();

        if ($club->competitors()->exists()) {
            Flux::toast(variant: 'danger', text: __('This club has competitors and cannot be deleted.'));

            return;
        }

        $club->delete();

        $this->deletingId = null;
        Flux::toast(variant: 'success', text: __('Club deleted.'));
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'city']);
        $this->country = 'HR';
        $this->resetValidation();
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Clubs') }}</flux:heading>
        <div class="flex items-center gap-2">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search clubs')" />
            <flux:button variant="primary" icon="plus" wire:click="create" data-test="create-club-button">
                {{ __('New club') }}
            </flux:button>
        </div>
    </div>

    <flux:table :paginate="$this->clubs">
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('City') }}</flux:table.column>
            <flux:table.column>{{ __('Country') }}</flux:table.column>
            <flux:table.column>{{ __('Competitors') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->clubs as $club)
                <flux:table.row :key="$club->id">
                    <flux:table.cell>{{ $club->name }}</flux:table.cell>
                    <flux:table.cell>{{ $club->city }}</flux:table.cell>
                    <flux:table.cell>{{ $club->country }}</flux:table.cell>
                    <flux:table.cell>{{ $club->competitors_count }}</flux:table.cell>
                    <flux:table.cell class="text-end">
                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $club->id }})" />
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $club->id }})" />
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <flux:modal name="club-form" class="md:w-96">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit club') : __('New club') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" type="text" required />
            <flux:input wire:model="city" :label="__('City')" type="text" />
            <flux:input wire:model="country" :label="__('Country')" type="text" maxlength="2" required :description="__('Two-letter code, e.g. HR')" />

            <div class="flex">
                <flux:spacer />
                <flux:button type="submit" variant="primary" data-test="save-club-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="club-delete" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete club?') }}</flux:heading>
                <flux:text class="mt-2">{{ __('This action cannot be undone.') }}</flux:text>
            </div>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete" data-test="confirm-delete-club-button">
                    {{ __('Delete') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
