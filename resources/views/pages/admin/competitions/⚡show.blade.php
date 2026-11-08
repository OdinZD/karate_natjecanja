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

<section class="w-full min-w-0 max-w-5xl space-y-6">
    @php
        [$statusState, $statusLabel] = match ($competition->status) {
            CompetitionStatus::Registration => ['registration', __('Registration open')],
            CompetitionStatus::Drawn => ['drawn', __('Draw done')],
            CompetitionStatus::InProgress => ['live', __('In progress')],
            CompetitionStatus::Finished => ['done', __('Finished')],
            default => ['none', __('Draft')],
        };

        $hasDraws = $competition->categories()->whereHas('bouts')->exists();
        $hasTimetable = $competition->categories()->whereNotNull('tatami_id')->exists();
        $hasResults = $competition->categories()->whereHas('placements')->exists();
    @endphp

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0 space-y-2">
            <a href="{{ route('admin.competitions.index') }}" wire:navigate class="inline-flex min-h-11 items-center gap-1.5 text-[13px] text-arena-muted">
                <flux:icon.chevron-left class="size-4" />{{ __('Competitions') }}
            </a>

            <h1 class="break-words font-display text-4xl font-black uppercase leading-[.95] tracking-[.01em] text-arena-ink md:text-5xl">{{ $competition->name }}</h1>

            <x-arena.status :state="$statusState" :label="$statusLabel" />
        </div>

        <div class="flex items-center gap-2">
            @if ($competition->status !== CompetitionStatus::Draft)
                <x-arena.button variant="secondary" icon="arrow-up-right" :href="route('public.competitions.show', $competition)" target="_blank">{{ __('Public page') }}<span class="sr-only"> ({{ __('Opens in a new tab') }})</span></x-arena.button>
            @endif

            <flux:dropdown align="end">
                <x-arena.button variant="secondary" icon="ellipsis-horizontal" :aria-label="__('More actions')" />

                <flux:menu>
                    @if ($hasDraws)
                        <flux:menu.item icon="document-arrow-down" :href="route('admin.competitions.draw.pdf', $competition)" target="_blank">{{ __('All draws (PDF)') }}<span class="sr-only"> ({{ __('Opens in a new tab') }})</span></flux:menu.item>
                    @endif
                    @if ($hasTimetable)
                        <flux:menu.item icon="document-arrow-down" :href="route('admin.competitions.timetable.pdf', $competition)" target="_blank">{{ __('Time table (PDF)') }}<span class="sr-only"> ({{ __('Opens in a new tab') }})</span></flux:menu.item>
                    @endif
                    @if ($hasResults)
                        <flux:menu.item icon="table-cells" :href="route('admin.competitions.results.export', $competition)" data-test="download-results-button">{{ __('Results (Excel)') }}</flux:menu.item>
                    @endif
                    @if ($hasDraws || $hasTimetable || $hasResults)
                        <flux:menu.separator />
                    @endif
                    <flux:modal.trigger name="competition-delete">
                        <flux:menu.item icon="trash" variant="danger">{{ __('Delete competition') }}</flux:menu.item>
                    </flux:modal.trigger>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    <x-arena.steps :competition="$competition" current="details" />

    <x-arena.card tag="section" class="space-y-5 p-5">
        <x-arena.section-title meta="01">{{ __('Details') }}</x-arena.section-title>

        <form wire:submit="update" class="space-y-5">
            <flux:input wire:model="name" :label="__('Name')" type="text" required />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="date" :label="__('Date')" type="date" required />
                <flux:input wire:model="start_time" :label="__('Start time')" type="time" required />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="venue" :label="__('Venue')" type="text" required />
                <flux:input wire:model="organizer" :label="__('Organizer')" type="text" />
            </div>

            <flux:input wire:model="registration_deadline" :label="__('Registration deadline')" type="datetime-local" class="sm:max-w-xs" />

            <fieldset class="space-y-2">
                <legend class="text-sm font-medium text-arena-ink">{{ __('Status') }}</legend>

                <div class="flex flex-wrap gap-2">
                    @foreach (CompetitionStatus::cases() as $case)
                        <x-arena.chip name="status" :value="$case->value" :label="__(Str::headline($case->value))" wire:model="status" />
                    @endforeach
                </div>

                <flux:error name="status" />
            </fieldset>

            <x-arena.button type="submit" data-test="update-competition-button">{{ __('Save') }}</x-arena.button>
        </form>
    </x-arena.card>

    <x-arena.card tag="section" id="tatami" class="space-y-4 p-5">
        <x-arena.section-title meta="02">{{ __('Tatamis') }}</x-arena.section-title>

        <div class="border border-arena-line">
            @foreach ($this->tatamis as $tatami)
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-arena-line px-3 py-2.5 last:border-b-0" wire:key="tatami-row-{{ $tatami->id }}">
                    <x-arena.tatami :number="$tatami->number" />

                    <div class="min-w-0 grow basis-40 space-y-1.5">
                        @if ($tatami->name)
                            <div class="text-sm font-bold">{{ $tatami->name }}</div>
                        @endif

                        <div class="flex flex-wrap items-center gap-1.5">
                            @forelse ($tatami->operators as $operator)
                                <span class="border border-arena-line-strong px-2 py-0.5 text-xs">{{ $operator->name }}</span>
                            @empty
                                <x-arena.status state="warn" :label="__('No operators')" />
                            @endforelse
                        </div>
                    </div>

                    <div class="flex items-center gap-1">
                        <x-arena.button variant="secondary" icon="user-plus" wire:click="assignOperators({{ $tatami->id }})">{{ __('Operators') }}</x-arena.button>
                        <x-arena.button variant="ghost" icon="trash" class="min-w-11" :aria-label="__('Delete tatami')" wire:click="deleteTatami({{ $tatami->id }})" wire:confirm="{{ __('Delete this tatami?') }}" />
                    </div>
                </div>
            @endforeach
        </div>

        <form wire:submit="addTatami" class="flex flex-wrap items-end gap-4">
            <flux:input wire:model="tatamiNumber" :label="__('No.')" type="number" min="1" class="w-24" />
            <flux:input wire:model="tatamiName" :label="__('Name')" type="text" />
            <x-arena.button type="submit" variant="secondary" icon="plus" data-test="add-tatami-button">{{ __('Add tatami') }}</x-arena.button>
        </form>
    </x-arena.card>

    <div class="flex justify-end">
        <x-arena.button :href="route('admin.competitions.categories', $competition)" wire:navigate>{{ __('Next step: Categories') }} &rarr;</x-arena.button>
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
