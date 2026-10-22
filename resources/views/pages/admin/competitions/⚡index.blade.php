<?php

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Competitions')] class extends Component {
    use WithPagination;

    public string $name = '';
    public string $date = '';
    public string $start_time = '10:00';
    public string $venue = '';
    public string $organizer = '';
    public string $registration_deadline = '';

    /**
     * @return LengthAwarePaginator<int, Competition>
     */
    #[Computed]
    public function competitions(): LengthAwarePaginator
    {
        return Competition::query()->orderByDesc('date')->paginate(15);
    }

    public function create(): void
    {
        $this->reset();
        $this->resetValidation();

        Flux::modal('competition-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'venue' => ['required', 'string', 'max:255'],
            'organizer' => ['nullable', 'string', 'max:255'],
            'registration_deadline' => ['nullable', 'date'],
        ]);

        $competition = Competition::create([
            ...$validated,
            'organizer' => $validated['organizer'] ?: null,
            'registration_deadline' => $validated['registration_deadline'] ?: null,
            'slug' => $this->uniqueSlug($validated['name']),
            'status' => CompetitionStatus::Draft,
        ]);

        $this->redirectRoute('admin.competitions.show', $competition, navigate: true);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'competition';
        $slug = $base;
        $suffix = 2;

        while (Competition::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex items-center justify-between">
        <flux:heading size="xl" level="1">{{ __('Competitions') }}</flux:heading>
        <flux:button variant="primary" icon="plus" wire:click="create" data-test="create-competition-button">
            {{ __('New competition') }}
        </flux:button>
    </div>

    <flux:table :paginate="$this->competitions">
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Date') }}</flux:table.column>
            <flux:table.column>{{ __('Venue') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->competitions as $competition)
                <flux:table.row :key="$competition->id">
                    <flux:table.cell>
                        <flux:link :href="route('admin.competitions.show', $competition)" wire:navigate>
                            {{ $competition->name }}
                        </flux:link>
                    </flux:table.cell>
                    <flux:table.cell>{{ $competition->date->format('d.m.Y.') }}</flux:table.cell>
                    <flux:table.cell>{{ $competition->venue }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm">{{ __(Str::headline($competition->status->value)) }}</flux:badge>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <flux:modal name="competition-form" class="md:w-96">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ __('New competition') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" type="text" required />
            <flux:input wire:model="date" :label="__('Date')" type="date" required />
            <flux:input wire:model="start_time" :label="__('Start time')" type="time" required />
            <flux:input wire:model="venue" :label="__('Venue')" type="text" required />
            <flux:input wire:model="organizer" :label="__('Organizer')" type="text" />
            <flux:input wire:model="registration_deadline" :label="__('Registration deadline')" type="datetime-local" />

            <div class="flex">
                <flux:spacer />
                <flux:button type="submit" variant="primary" data-test="save-competition-button">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
