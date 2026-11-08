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
            ->orderByName('name')
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

<section class="w-full min-w-0 space-y-5">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <h1 class="font-display text-4xl font-black uppercase leading-[.95] tracking-[.01em] text-arena-ink md:text-5xl">{{ __('Clubs') }}</h1>

        <div class="flex flex-wrap items-center gap-2">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search clubs')" :aria-label="__('Search clubs')" />
            <x-arena.button icon="plus" wire:click="create" data-test="create-club-button">{{ __('New club') }}</x-arena.button>
        </div>
    </div>

    <x-arena.card class="overflow-x-auto">
        <table class="w-full text-start text-sm">
            <thead>
                <tr class="border-b border-arena-line text-xs uppercase tracking-[.1em] text-arena-muted">
                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Name') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('City') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Country') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Competitors') }}</th>
                    <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                </tr>
            </thead>

            <tbody>
                @foreach ($this->clubs as $club)
                    <tr class="border-b border-arena-line last:border-b-0" wire:key="club-row-{{ $club->id }}">
                        <td class="px-4 py-2 font-bold">{{ $club->name }}</td>
                        <td class="px-4 py-2">{{ $club->city }}</td>
                        <td class="px-4 py-2">{{ $club->country }}</td>
                        <td class="px-4 py-2 font-mono tabular-nums">{{ $club->competitors_count }}</td>
                        <td class="whitespace-nowrap px-2 py-1 text-end">
                            <x-arena.button variant="ghost" icon="pencil-square" class="min-w-11" :aria-label="__('Edit')" wire:click="edit({{ $club->id }})" />
                            <x-arena.button variant="ghost" icon="trash" class="min-w-11" :aria-label="__('Delete')" wire:click="confirmDelete({{ $club->id }})" />
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-arena.card>

    <flux:pagination :paginator="$this->clubs" />

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
