<?php

use App\Actions\Entries\RecordWeighIn;
use App\Actions\Entries\SetAttendance;
use App\Actions\Entries\WeighInResult;
use App\Enums\Discipline;
use App\Models\Bout;
use App\Models\Category;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Competitor;
use App\Models\Entry;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Weigh-in')] class extends Component {
    use WithPagination;

    public Competition $competition;

    public string $search = '';
    public string $clubFilter = '';
    public string $statusFilter = '';

    /** @var array<int, string> */
    public array $weights = [];

    public function mount(Competition $competition): void
    {
        $this->competition = $competition;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedClubFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * @return Collection<int, Club>
     */
    #[Computed]
    public function clubs(): Collection
    {
        return Club::query()
            ->whereHas('competitors.entries.category', fn ($query) => $query->where('competition_id', $this->competition->id))
            ->orderByName('name')
            ->orderByName('city')
            ->get();
    }

    /**
     * Ids of the categories that already have a draw, as keys: their entries can no longer change.
     *
     * @return SupportCollection<int, int>
     */
    #[Computed]
    public function drawn(): SupportCollection
    {
        return Bout::query()
            ->whereIn('category_id', $this->competition->categories()->select('id'))
            ->distinct()
            ->pluck('category_id')
            ->flip();
    }

    /**
     * @return array{kumite: int, weighed: int, moved: int, absent: int}
     */
    #[Computed]
    public function summary(): array
    {
        $entries = fn () => Entry::query()->whereHas('category', fn ($query) => $query->where('competition_id', $this->competition->id));
        $kumite = fn () => $entries()->present()->whereHas('category', fn ($query) => $query->where('discipline', Discipline::Kumite->value));

        return [
            'kumite' => $kumite()->count(),
            'weighed' => $kumite()->whereNotNull('weighed_at')->count(),
            'moved' => $entries()->present()->whereNotNull('moved_from_category_id')->count(),
            'absent' => $entries()->whereNotNull('absent_at')->distinct()->count('competitor_id'),
        ];
    }

    /**
     * @return LengthAwarePaginatorContract<int, Competitor>
     */
    #[Computed]
    public function competitors(): LengthAwarePaginatorContract
    {
        $id = $this->competition->id;
        $inCompetition = fn ($query) => $query->where('competition_id', $id);
        $term = trim($this->search);

        $query = Competitor::query()
            ->whereHas('entries.category', $inCompetition)
            ->when(mb_strlen($term) >= 2, function ($query) use ($term) {
                // Every word must match the first or the last name, so "Ana Horvat" finds Horvat Ana.
                foreach (preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY) as $word) {
                    $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';

                    $query->where(fn ($inner) => $inner
                        ->whereRaw("first_name like ? escape '!'", [$like])
                        ->orWhereRaw("last_name like ? escape '!'", [$like]));
                }
            })
            ->when($this->clubFilter !== '', fn ($query) => $query->where('club_id', (int) $this->clubFilter))
            ->with([
                'club:id,name',
                'entries' => fn ($query) => $query
                    ->chaperone()
                    ->whereHas('category', $inCompetition)
                    ->with(['category', 'movedFrom'])
                    ->orderBy('id'),
            ])
            ->orderByName('last_name')
            ->orderByName('first_name');

        if ($this->statusFilter === '') {
            return $query->paginate(25);
        }

        // The status of a competitor is worked out in PHP (absent, problem, moved, waiting, weighed), so the
        // filtered list is paged by hand to stay consistent with the label shown in each row.
        $matching = $query->get()->filter(fn (Competitor $competitor) => $this->statusOf($competitor) === $this->statusFilter)->values();
        $page = Paginator::resolveCurrentPage();

        return new Paginator($matching->forPage($page, 25)->values(), $matching->count(), 25, $page, ['path' => Paginator::resolveCurrentPath()]);
    }

    /**
     * Where a competitor stands at the weigh-in: absent, problem, moved, weighed, waiting or kata (nothing to weigh).
     */
    public function statusOf(Competitor $competitor): string
    {
        $kumite = $competitor->entries->filter(fn (Entry $entry) => $entry->category->discipline === Discipline::Kumite);

        return match (true) {
            $competitor->entries->contains(fn (Entry $entry) => $entry->absent_at !== null) => 'absent',
            $kumite->isEmpty() => 'kata',
            $kumite->contains(fn (Entry $entry) => $this->hasProblem($entry)) => 'problem',
            $kumite->contains(fn (Entry $entry) => $entry->moved_from_category_id !== null) => 'moved',
            $kumite->contains(fn (Entry $entry) => $entry->weighed_at === null) => 'waiting',
            default => 'weighed',
        };
    }

    /**
     * The Kumite categories of the competition, loaded once per request for the problem checks.
     *
     * @return Collection<int, Category>
     */
    #[Computed]
    public function kumiteCategories(): Collection
    {
        return $this->competition->categories()->where('discipline', Discipline::Kumite->value)->get();
    }

    public function hasProblem(Entry $entry): bool
    {
        return app(RecordWeighIn::class)->hasProblem($entry, $this->kumiteCategories);
    }

    public function weigh(int $entryId, RecordWeighIn $weighIn): void
    {
        $entry = $this->entry($entryId);

        $this->weights[$entryId] = str_replace(',', '.', trim($this->weights[$entryId] ?? ''));

        $this->validate([
            'weights.'.$entryId => ['required', 'numeric', 'min:0.1', 'max:999.99'],
        ], attributes: ['weights.'.$entryId => __('Weight (kg)')]);

        $result = $weighIn->handle($entry, $this->weights[$entryId]);
        $name = $entry->competitor->last_name.' '.$entry->competitor->first_name;
        $weight = $this->formatWeight($this->weights[$entryId]);

        $this->announce($result, $name, $weight);

        if (! $result->failed()) {
            unset($this->weights[$entryId]);
        }

        $this->refresh();
    }

    public function revert(int $entryId, RecordWeighIn $weighIn): void
    {
        $entry = $this->entry($entryId);

        $result = $weighIn->revert($entry);

        if ($result->failed() || $result->outcome === WeighInResult::CONFLICT) {
            Flux::toast(variant: 'danger', text: $result->message ?? __(':name is already entered in ":category".', ['name' => $entry->competitor->last_name.' '.$entry->competitor->first_name, 'category' => $result->to->name]));
        } else {
            Flux::toast(variant: 'success', text: __('Weigh-in undone.'));
        }

        $this->refresh();
    }

    public function setAbsent(int $competitorId, bool $absent, SetAttendance $attendance): void
    {
        $competitor = Competitor::query()
            ->whereHas('entries.category', fn ($query) => $query->where('competition_id', $this->competition->id))
            ->findOrFail($competitorId);

        $error = $attendance->handle($this->competition, $competitor, $absent);

        $error === null
            ? Flux::toast(variant: 'success', text: $absent ? __(':name marked absent.', ['name' => $competitor->last_name.' '.$competitor->first_name]) : __(':name marked present.', ['name' => $competitor->last_name.' '.$competitor->first_name]))
            : Flux::toast(variant: 'danger', text: $error);

        $this->refresh();
    }

    public function formatWeight(string|float|null $weight): string
    {
        $separator = app()->getLocale() === 'hr' ? ',' : '.';

        return rtrim(rtrim(number_format((float) $weight, 2, $separator, ''), '0'), $separator);
    }

    private function entry(int $entryId): Entry
    {
        return Entry::query()
            ->whereHas('category', fn ($query) => $query->where('competition_id', $this->competition->id))
            ->with(['category', 'competitor'])
            ->findOrFail($entryId);
    }

    private function announce(WeighInResult $result, string $name, string $weight): void
    {
        match ($result->outcome) {
            WeighInResult::FITS => Flux::toast(variant: 'success', text: __(':name weighed :weight kg: the category fits.', ['name' => $name, 'weight' => $weight])),
            WeighInResult::MOVED => Flux::toast(variant: 'success', text: __(':name weighed :weight kg and moved from ":from" to ":to".', ['name' => $name, 'weight' => $weight, 'from' => $result->from->name, 'to' => $result->to->name])),
            WeighInResult::NO_CATEGORY => Flux::toast(variant: 'danger', text: __('No category fits :name at :weight kg. The entry stays in ":from".', ['name' => $name, 'weight' => $weight, 'from' => $result->from->name])),
            WeighInResult::CONFLICT => Flux::toast(variant: 'danger', text: __(':name belongs in ":to" at :weight kg but is already entered there. The entry stays in ":from".', ['name' => $name, 'weight' => $weight, 'from' => $result->from->name, 'to' => $result->to->name])),
            default => Flux::toast(variant: 'danger', text: $result->message),
        };
    }

    private function refresh(): void
    {
        unset($this->competitors, $this->summary, $this->drawn);
    }
}; ?>

<section class="w-full min-w-0 space-y-5">
    <div class="space-y-1">
        <a href="{{ route('admin.competitions.show', $competition) }}" wire:navigate class="inline-flex min-h-11 items-center gap-1.5 text-[13px] text-arena-muted">
            <flux:icon.chevron-left class="size-4" />{{ $competition->name }}
        </a>
        <h1 class="font-display text-4xl font-black uppercase leading-[.95] tracking-[.01em] text-arena-ink md:text-5xl">{{ __('Weigh-in') }}</h1>
    </div>

    <x-arena.steps :competition="$competition" current="weigh-in" />

    <p class="text-sm text-arena-muted" data-test="weigh-in-summary">
        {{ __('Weighed :weighed of :kumite Kumite entries · Moved: :moved · Absent: :absent', [
            'weighed' => $this->summary['weighed'],
            'kumite' => $this->summary['kumite'],
            'moved' => $this->summary['moved'],
            'absent' => $this->summary['absent'],
        ]) }}
    </p>

    <div class="grid gap-2 md:grid-cols-3">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search by name')" :aria-label="__('Search by name')" />

        <flux:select wire:model.live="clubFilter" :aria-label="__('Club')">
            <flux:select.option value="">{{ __('All clubs') }}</flux:select.option>
            @foreach ($this->clubs as $club)
                <flux:select.option :value="$club->id">{{ $club->name }}{{ $club->city ? ' ('.$club->city.')' : '' }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="statusFilter" :aria-label="__('Status')">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            <flux:select.option value="waiting">{{ __('Waiting to be weighed') }}</flux:select.option>
            <flux:select.option value="weighed">{{ __('Weighed') }}</flux:select.option>
            <flux:select.option value="moved">{{ __('Moved') }}</flux:select.option>
            <flux:select.option value="problem">{{ __('Problems') }}</flux:select.option>
            <flux:select.option value="absent">{{ __('Absent') }}</flux:select.option>
        </flux:select>
    </div>

    <x-arena.card>
        @if ($this->competitors->isEmpty())
            <p class="p-4 text-sm text-arena-muted">{{ __('No competitors match.') }}</p>
        @else
            <ul class="divide-y divide-arena-line">
                @foreach ($this->competitors as $competitor)
                    @php($status = $this->statusOf($competitor))
                    @php($locked = $competitor->entries->contains(fn ($entry) => isset($this->drawn[$entry->category_id])))

                    <li class="space-y-3 px-4 py-3" wire:key="weigh-in-{{ $competitor->id }}" data-test="weigh-in-row" data-status="{{ $status }}">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span class="font-bold text-arena-ink">{{ $competitor->last_name }} {{ $competitor->first_name }}</span>
                            <span class="text-sm text-arena-muted">{{ $competitor->club->name }}</span>
                            <span class="font-mono text-xs tabular-nums text-arena-muted">{{ $competitor->birth_date->format('Y') }}</span>

                            @if ($status === 'absent')
                                <x-arena.status state="none" :label="__('Absent')" />
                            @elseif ($status === 'problem')
                                <x-arena.status state="error" :label="__('Problem')" />
                            @elseif ($status === 'moved')
                                <x-arena.status state="warn" :label="__('Moved')" />
                            @elseif ($status === 'weighed')
                                <x-arena.status state="done" :label="__('Weighed')" />
                            @elseif ($status === 'waiting')
                                <x-arena.status state="waiting" :label="__('Waiting to be weighed')" />
                            @else
                                <x-arena.status state="none" :label="__('Nothing to weigh')" />
                            @endif
                        </div>

                        @foreach ($competitor->entries as $entry)
                            @if ($entry->category->discipline === \App\Enums\Discipline::Kumite)
                                <div class="space-y-2" wire:key="weigh-in-entry-{{ $entry->id }}">
                                    <p class="text-sm text-arena-ink">
                                        {{ $entry->category->name }}
                                        @if ($entry->weightLabel() !== null)
                                            <span class="text-arena-muted">({{ __('entered at :weight kg', ['weight' => $entry->weightLabel()]) }})</span>
                                        @endif
                                    </p>

                                    @if ($entry->moved_from_category_id !== null)
                                        <p class="text-sm text-arena-muted">{{ __('Moved from ":category"', ['category' => $entry->movedFrom->name]) }}</p>
                                    @endif

                                    @if ($entry->weighed_at !== null)
                                        <p @class(['font-mono text-sm tabular-nums', 'text-arena-aka-text' => $this->hasProblem($entry), 'text-arena-ink' => ! $this->hasProblem($entry)])>
                                            {{ __('Weighed: :weight kg', ['weight' => $this->formatWeight($entry->weighed_weight)]) }}
                                            @if ($this->hasProblem($entry))
                                                — {{ __('does not fit this category') }}
                                            @endif
                                        </p>
                                    @endif

                                    @if (isset($this->drawn[$entry->category_id]))
                                        <p class="text-sm text-arena-muted">{{ __('A draw already exists for this category.') }}</p>
                                    @elseif ($status !== 'absent')
                                        <form wire:submit="weigh({{ $entry->id }})" class="flex flex-wrap items-start gap-2">
                                            <div>
                                                <input type="text" inputmode="decimal" autocomplete="off" wire:model="weights.{{ $entry->id }}" aria-label="{{ __('Weight (kg)') }}: {{ $competitor->last_name }} {{ $competitor->first_name }}" placeholder="{{ __('Weight (kg)') }}" class="h-11 w-32 border border-arena-line-strong bg-arena-surface px-3 font-mono text-arena-ink placeholder:text-arena-muted" @error('weights.'.$entry->id) aria-invalid="true" aria-describedby="weigh-in-error-{{ $entry->id }}" @enderror data-test="weigh-in-input" />
                                                @error('weights.'.$entry->id)
                                                    <p class="mt-1 text-sm text-arena-aka-text" id="weigh-in-error-{{ $entry->id }}">{{ $message }}</p>
                                                @enderror
                                            </div>
                                            <x-arena.button type="submit" variant="secondary" data-test="weigh-in-save">{{ __('Save weight') }}</x-arena.button>

                                            @if ($entry->weighed_at !== null)
                                                <x-arena.button variant="ghost" icon="arrow-uturn-left" wire:click="revert({{ $entry->id }})" :aria-label="__('Undo the weigh-in of :name', ['name' => $competitor->last_name.' '.$competitor->first_name])" data-test="weigh-in-revert">{{ __('Undo') }}</x-arena.button>
                                            @endif
                                        </form>
                                    @endif
                                </div>
                            @else
                                <p class="text-sm text-arena-muted" wire:key="weigh-in-entry-{{ $entry->id }}">{{ $entry->category->name }}</p>
                            @endif
                        @endforeach

                        @if ($locked)
                            <p class="text-sm text-arena-muted">{{ __('A draw already exists, so attendance can no longer change.') }}</p>
                        @elseif ($status === 'absent')
                            <x-arena.button variant="secondary" wire:click="setAbsent({{ $competitor->id }}, false)" data-test="weigh-in-present">{{ __('Mark present') }}</x-arena.button>
                        @else
                            <x-arena.button variant="ghost" wire:click="setAbsent({{ $competitor->id }}, true)" wire:confirm="{{ __('Mark :name absent? They will be left out of the draw.', ['name' => $competitor->last_name.' '.$competitor->first_name]) }}" data-test="weigh-in-absent">{{ __('Mark absent') }}</x-arena.button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-arena.card>

    <flux:pagination :paginator="$this->competitors" />
</section>
