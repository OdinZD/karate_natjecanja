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

<section class="w-full min-w-0 space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <h1 class="font-display text-[44px] font-black uppercase leading-[.95] text-arena-ink">{{ __('Competitions') }}</h1>

        <x-arena.button icon="plus" wire:click="create" data-test="create-competition-button">
            {{ __('New competition') }}
        </x-arena.button>
    </div>

    <x-arena.card class="overflow-x-auto">
        <table class="w-full text-start text-sm">
            <thead>
                <tr class="border-b border-arena-line text-xs uppercase tracking-[.1em] text-arena-muted">
                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Name') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Date') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Venue') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Status') }}</th>
                    <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('Open') }}</span></th>
                </tr>
            </thead>

            <tbody>
                @foreach ($this->competitions as $competition)
                    @php
                        [$statusState, $statusLabel] = match ($competition->status) {
                            CompetitionStatus::Registration => ['registration', __('Registration open')],
                            CompetitionStatus::Drawn => ['drawn', __('Draw done')],
                            CompetitionStatus::InProgress => ['live', __('In progress')],
                            CompetitionStatus::Finished => ['done', __('Finished')],
                            default => ['none', __('Draft')],
                        };
                    @endphp

                    <tr class="border-b border-arena-line last:border-b-0" wire:key="competition-row-{{ $competition->id }}">
                        <td class="px-4 py-2">
                            <a href="{{ route('admin.competitions.show', $competition) }}" wire:navigate class="inline-flex min-h-11 items-center text-[15px] font-bold underline-offset-4 hover:underline">{{ $competition->name }}</a>
                        </td>
                        <td class="px-4 py-2 font-mono tabular-nums">{{ $competition->date->format('d.m.Y.') }}</td>
                        <td class="px-4 py-2">{{ $competition->venue }}</td>
                        <td class="px-4 py-2"><x-arena.status :state="$statusState" :label="$statusLabel" /></td>
                        <td class="px-4 py-2 text-end">
                            <a href="{{ route('admin.competitions.show', $competition) }}" wire:navigate class="inline-flex min-h-11 items-center gap-1 font-semibold">
                                {{ __('Open') }}<flux:icon.arrow-right class="size-4" />
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-arena.card>

    <flux:pagination :paginator="$this->competitions" />

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
