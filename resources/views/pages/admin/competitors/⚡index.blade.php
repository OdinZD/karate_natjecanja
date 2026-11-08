<?php

use App\Enums\Gender;
use App\Models\Club;
use App\Models\Competitor;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Competitors')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $clubFilter = '';

    public ?int $editingId = null;
    public ?int $deletingId = null;

    public string $first_name = '';
    public string $last_name = '';
    public string $gender = 'male';
    public string $birth_date = '';
    public string $club_id = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedClubFilter(): void
    {
        $this->resetPage();
    }

    /**
     * @return Collection<int, Club>
     */
    #[Computed]
    public function clubs(): Collection
    {
        return Club::orderByName('name')->get();
    }

    /**
     * @return LengthAwarePaginator<int, Competitor>
     */
    #[Computed]
    public function competitors(): LengthAwarePaginator
    {
        return Competitor::query()
            ->with('club')
            ->when($this->search !== '', function ($query) {
                $term = '%'.$this->search.'%';

                $query->where(fn ($q) => $q->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term));
            })
            ->when($this->clubFilter !== '', fn ($query) => $query->where('club_id', $this->clubFilter))
            ->orderByName('last_name')
            ->orderByName('first_name')
            ->paginate(15);
    }

    public function create(): void
    {
        $this->resetForm();

        Flux::modal('competitor-form')->show();
    }

    public function edit(int $id): void
    {
        $competitor = Competitor::findOrFail($id);

        $this->resetForm();
        $this->editingId = $competitor->id;
        $this->first_name = $competitor->first_name;
        $this->last_name = $competitor->last_name;
        $this->gender = $competitor->gender->value;
        $this->birth_date = $competitor->birth_date->format('Y-m-d');
        $this->club_id = (string) $competitor->club_id;

        Flux::modal('competitor-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'gender' => ['required', Rule::enum(Gender::class)],
            'birth_date' => ['required', 'date', 'before:today'],
            'club_id' => ['required', 'integer', Rule::exists('clubs', 'id')],
        ]);

        if ($this->editingId) {
            Competitor::findOrFail($this->editingId)->update($validated);
        } else {
            Competitor::create($validated);
        }

        Flux::modal('competitor-form')->close();
        Flux::toast(variant: 'success', text: __('Competitor saved.'));
        $this->resetForm();
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;

        Flux::modal('competitor-delete')->show();
    }

    public function delete(): void
    {
        $competitor = Competitor::findOrFail($this->deletingId);

        Flux::modal('competitor-delete')->close();

        if ($competitor->entries()->exists()) {
            Flux::toast(variant: 'danger', text: __('This competitor has entries and cannot be deleted.'));

            return;
        }

        $competitor->delete();

        $this->deletingId = null;
        Flux::toast(variant: 'success', text: __('Competitor deleted.'));
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'first_name', 'last_name', 'birth_date', 'club_id']);
        $this->gender = Gender::Male->value;
        $this->resetValidation();
    }
}; ?>

<section class="w-full min-w-0 space-y-5">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <h1 class="font-display text-4xl font-black uppercase leading-[.95] tracking-[.01em] text-arena-ink md:text-5xl">{{ __('Competitors') }}</h1>

        <div class="flex flex-wrap items-center gap-2">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search by name')" :aria-label="__('Search by name')" />
            <flux:select wire:model.live="clubFilter" class="min-w-48" :aria-label="__('Club')">
                <flux:select.option value="">{{ __('All clubs') }}</flux:select.option>
                @foreach ($this->clubs as $club)
                    <flux:select.option :value="$club->id">{{ $club->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <x-arena.button icon="plus" wire:click="create" data-test="create-competitor-button">{{ __('New competitor') }}</x-arena.button>
        </div>
    </div>

    <x-arena.card class="overflow-x-auto">
        <table class="w-full text-start text-sm">
            <thead>
                <tr class="border-b border-arena-line text-xs uppercase tracking-[.1em] text-arena-muted">
                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Last name') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('First name') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Gender') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Date of birth') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Club') }}</th>
                    <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                </tr>
            </thead>

            <tbody>
                @foreach ($this->competitors as $competitor)
                    <tr class="border-b border-arena-line last:border-b-0" wire:key="competitor-row-{{ $competitor->id }}">
                        <td class="px-4 py-2 font-bold">{{ $competitor->last_name }}</td>
                        <td class="px-4 py-2">{{ $competitor->first_name }}</td>
                        <td class="px-4 py-2">{{ $competitor->gender === Gender::Male ? __('Male') : __('Female') }}</td>
                        <td class="px-4 py-2 font-mono tabular-nums">{{ $competitor->birth_date->format('d.m.Y.') }}</td>
                        <td class="px-4 py-2">{{ $competitor->club->name }}</td>
                        <td class="whitespace-nowrap px-2 py-1 text-end">
                            <x-arena.button variant="ghost" icon="pencil-square" class="min-w-11" :aria-label="__('Edit')" wire:click="edit({{ $competitor->id }})" />
                            <x-arena.button variant="ghost" icon="trash" class="min-w-11" :aria-label="__('Delete')" wire:click="confirmDelete({{ $competitor->id }})" />
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-arena.card>

    <flux:pagination :paginator="$this->competitors" />

    <flux:modal name="competitor-form" class="md:w-96">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit competitor') : __('New competitor') }}</flux:heading>

            <flux:input wire:model="first_name" :label="__('First name')" type="text" required />
            <flux:input wire:model="last_name" :label="__('Last name')" type="text" required />

            <flux:select wire:model="gender" :label="__('Gender')">
                <flux:select.option value="male">{{ __('Male') }}</flux:select.option>
                <flux:select.option value="female">{{ __('Female') }}</flux:select.option>
            </flux:select>

            <flux:input wire:model="birth_date" :label="__('Date of birth')" type="date" required />

            <flux:select wire:model="club_id" :label="__('Club')" :placeholder="__('Choose a club')">
                @foreach ($this->clubs as $club)
                    <flux:select.option :value="$club->id">{{ $club->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex">
                <flux:spacer />
                <flux:button type="submit" variant="primary" data-test="save-competitor-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="competitor-delete" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete competitor?') }}</flux:heading>
                <flux:text class="mt-2">{{ __('This action cannot be undone.') }}</flux:text>
            </div>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete" data-test="confirm-delete-competitor-button">
                    {{ __('Delete') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
