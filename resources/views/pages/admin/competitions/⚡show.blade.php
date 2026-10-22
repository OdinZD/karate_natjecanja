<?php

use App\Enums\CompetitionStatus;
use App\Enums\UserRole;
use App\Models\Competition;
use App\Models\Tatami;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Competition')] class extends Component {
    public Competition $competition;

    public string $name = '';
    public string $date = '';
    public string $start_time = '';
    public string $venue = '';
    public string $organizer = '';
    public string $registration_deadline = '';
    public string $status = '';

    public ?int $tatamiNumber = null;
    public string $tatamiName = '';

    public ?int $assigningTatamiId = null;

    /** @var array<int, int|string> */
    public array $operatorIds = [];

    public function mount(Competition $competition): void
    {
        $this->competition = $competition;
        $this->name = $competition->name;
        $this->date = $competition->date->format('Y-m-d');
        $this->start_time = substr($competition->start_time, 0, 5);
        $this->venue = $competition->venue;
        $this->organizer = $competition->organizer ?? '';
        $this->registration_deadline = $competition->registration_deadline?->format('Y-m-d\TH:i') ?? '';
        $this->status = $competition->status->value;
        $this->tatamiNumber = $this->nextTatamiNumber();
    }

    /**
     * @return Collection<int, Tatami>
     */
    #[Computed]
    public function tatamis(): Collection
    {
        return $this->competition->tatamis()->with('operators')->orderBy('number')->get();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function operators(): Collection
    {
        return User::where('role', UserRole::Operator)->orderBy('name')->get();
    }

    public function update(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'venue' => ['required', 'string', 'max:255'],
            'organizer' => ['nullable', 'string', 'max:255'],
            'registration_deadline' => ['nullable', 'date'],
            'status' => ['required', Rule::enum(CompetitionStatus::class)],
        ]);

        $this->competition->update([
            ...$validated,
            'organizer' => $validated['organizer'] ?: null,
            'registration_deadline' => $validated['registration_deadline'] ?: null,
        ]);

        Flux::toast(variant: 'success', text: __('Competition updated.'));
    }

    public function addTatami(): void
    {
        $validated = $this->validate([
            'tatamiNumber' => [
                'required', 'integer', 'min:1', 'max:255',
                Rule::unique('tatamis', 'number')->where('competition_id', $this->competition->id),
            ],
            'tatamiName' => ['nullable', 'string', 'max:255'],
        ]);

        $this->competition->tatamis()->create([
            'number' => $validated['tatamiNumber'],
            'name' => $validated['tatamiName'] ?: null,
        ]);

        unset($this->tatamis);
        $this->tatamiName = '';
        $this->tatamiNumber = $this->nextTatamiNumber();
    }

    public function deleteTatami(int $id): void
    {
        $this->competition->tatamis()->findOrFail($id)->delete();

        unset($this->tatamis);
        $this->tatamiNumber = $this->nextTatamiNumber();
    }

    public function assignOperators(int $tatamiId): void
    {
        $tatami = $this->competition->tatamis()->with('operators')->findOrFail($tatamiId);

        $this->assigningTatamiId = $tatami->id;
        $this->operatorIds = $tatami->operators->pluck('id')->all();

        Flux::modal('tatami-operators')->show();
    }

    public function saveOperators(): void
    {
        $tatami = $this->competition->tatamis()->findOrFail($this->assigningTatamiId);

        $validated = $this->validate([
            'operatorIds' => ['array'],
            'operatorIds.*' => ['integer', Rule::exists('users', 'id')->where('role', UserRole::Operator->value)],
        ]);

        $tatami->operators()->sync($validated['operatorIds']);

        unset($this->tatamis);
        Flux::modal('tatami-operators')->close();
        Flux::toast(variant: 'success', text: __('Operators updated.'));
    }

    public function deleteCompetition(): void
    {
        $this->competition->delete();

        $this->redirectRoute('admin.competitions.index', navigate: true);
    }

    private function nextTatamiNumber(): int
    {
        return ((int) $this->competition->tatamis()->max('number')) + 1;
    }
}; ?>

<section class="w-full space-y-10">
    <div class="flex items-center justify-between">
        <div>
            <flux:link :href="route('admin.competitions.index')" wire:navigate class="text-sm">
                &larr; {{ __('Competitions') }}
            </flux:link>
            <flux:heading size="xl" level="1">{{ $competition->name }}</flux:heading>
        </div>
        <div class="flex items-center gap-2">
            <flux:button :href="route('admin.competitions.categories', $competition)" icon="squares-2x2" wire:navigate data-test="categories-link">
                {{ __('Categories') }}
            </flux:button>
            <flux:button :href="route('admin.competitions.entries', $competition)" icon="clipboard-document-list" wire:navigate data-test="entries-link">
                {{ __('Entries') }}
            </flux:button>
            <flux:button :href="route('admin.competitions.draw', $competition)" icon="sparkles" wire:navigate data-test="draw-link">
                {{ __('Draw') }}
            </flux:button>
            <flux:button :href="route('admin.competitions.timetable', $competition)" icon="clock" wire:navigate data-test="timetable-link">
                {{ __('Time table') }}
            </flux:button>
            <flux:modal.trigger name="competition-delete">
                <flux:button variant="danger" icon="trash">{{ __('Delete competition') }}</flux:button>
            </flux:modal.trigger>
        </div>
    </div>

    <form wire:submit="update" class="max-w-xl space-y-6">
        <flux:heading size="lg">{{ __('Details') }}</flux:heading>

        <flux:input wire:model="name" :label="__('Name')" type="text" required />

        <div class="grid grid-cols-2 gap-4">
            <flux:input wire:model="date" :label="__('Date')" type="date" required />
            <flux:input wire:model="start_time" :label="__('Start time')" type="time" required />
        </div>

        <flux:input wire:model="venue" :label="__('Venue')" type="text" required />
        <flux:input wire:model="organizer" :label="__('Organizer')" type="text" />
        <flux:input wire:model="registration_deadline" :label="__('Registration deadline')" type="datetime-local" />

        <flux:select wire:model="status" :label="__('Status')">
            @foreach (CompetitionStatus::cases() as $case)
                <flux:select.option :value="$case->value">{{ __(Str::headline($case->value)) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:button type="submit" variant="primary" data-test="update-competition-button">{{ __('Save') }}</flux:button>
    </form>

    <div class="max-w-xl space-y-4">
        <flux:heading size="lg">{{ __('Tatamis') }}</flux:heading>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('No.') }}</flux:table.column>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Operators') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->tatamis as $tatami)
                    <flux:table.row :key="$tatami->id">
                        <flux:table.cell>{{ $tatami->number }}</flux:table.cell>
                        <flux:table.cell>{{ $tatami->name }}</flux:table.cell>
                        <flux:table.cell>{{ $tatami->operators->pluck('name')->join(', ') ?: '—' }}</flux:table.cell>
                        <flux:table.cell class="text-end">
                            <flux:button size="sm" variant="ghost" icon="user-plus" wire:click="assignOperators({{ $tatami->id }})" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteTatami({{ $tatami->id }})" wire:confirm="{{ __('Delete this tatami?') }}" />
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <form wire:submit="addTatami" class="flex items-end gap-4">
            <flux:input wire:model="tatamiNumber" :label="__('No.')" type="number" min="1" class="w-24" />
            <flux:input wire:model="tatamiName" :label="__('Name')" type="text" />
            <flux:button type="submit" icon="plus" data-test="add-tatami-button">{{ __('Add tatami') }}</flux:button>
        </form>
    </div>

    <flux:modal name="tatami-operators" class="md:w-96">
        <form wire:submit="saveOperators" class="space-y-6">
            <flux:heading size="lg">{{ __('Assign operators') }}</flux:heading>

            <flux:checkbox.group wire:model="operatorIds">
                @forelse ($this->operators as $operator)
                    <flux:checkbox :value="$operator->id" :label="$operator->name" :description="$operator->email" />
                @empty
                    <flux:text>{{ __('No operators yet. Create them under Users.') }}</flux:text>
                @endforelse
            </flux:checkbox.group>

            <div class="flex">
                <flux:spacer />
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="competition-delete" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete competition?') }}</flux:heading>
                <flux:text class="mt-2">{{ __('All categories, entries and results of this competition will be deleted. This action cannot be undone.') }}</flux:text>
            </div>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="deleteCompetition" data-test="confirm-delete-competition-button">
                    {{ __('Delete') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
