<?php

use App\Actions\Draw\DrawException;
use App\Actions\Draw\RecordBoutResult;
use App\Actions\Draw\UndoBoutResult;
use App\Actions\Timetable\ConsoleQueue;
use App\Actions\Timetable\TatamiBouts;
use App\Actions\Timetable\TimetableBuilder;
use App\Enums\BoutBracket;
use App\Enums\BoutDecision;
use App\Enums\BoutSlot;
use App\Enums\Discipline;
use App\Enums\DrawProgress;
use App\Models\Bout;
use App\Models\Category;
use App\Models\Tatami;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Tatami console')] class extends Component {
    public Tatami $tatami;

    public ?int $categoryId = null;

    public string $winner = '';
    public string $decision = 'points';
    public string $akaScore = '';
    public string $aoScore = '';

    public function mount(Tatami $tatami): void
    {
        $this->tatami = $tatami->load('competition');

        $builder = app(TimetableBuilder::class);
        $open = $this->categories->first(fn (Category $category) => in_array($builder->progress($category), [DrawProgress::Pending, DrawProgress::Running], true));

        $this->categoryId = ($open ?? $this->categories->first())?->id;
        $this->resetForm();
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return app(TimetableBuilder::class)
            ->withProgressCounts($this->tatami->categories())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function activeCategory(): ?Category
    {
        return $this->categories->firstWhere('id', $this->categoryId);
    }

    #[Computed]
    public function queue(): ?ConsoleQueue
    {
        return $this->activeCategory ? app(TatamiBouts::class)->handle($this->activeCategory) : null;
    }

    /**
     * The newest result of the whole tatami, the only one that can be undone from the console.
     */
    #[Computed]
    public function latestPlayed(): ?Bout
    {
        return app(TatamiBouts::class)->latestPlayed($this->tatami);
    }

    public function updatedCategoryId(): void
    {
        $this->resetForm();
    }

    public function record(int $boutId, RecordBoutResult $recorder): void
    {
        $category = $this->requireCategory();
        $this->tatamiBout($boutId);
        $current = $this->queue?->current;

        if ($current === null || $current->id !== $boutId) {
            $this->refresh();
            $this->resetForm();
            Flux::toast(variant: 'warning', text: __('That bout is no longer the current bout. The screen was refreshed.'));

            return;
        }

        $validated = $this->validate([
            'winner' => ['required', Rule::in(['aka', 'ao'])],
            'decision' => ['required', Rule::enum(BoutDecision::class), Rule::notIn([BoutDecision::Bye->value])],
            'akaScore' => ['nullable', 'integer', 'min:0', 'max:255'],
            'aoScore' => ['nullable', 'integer', 'min:0', 'max:255'],
        ]);

        try {
            $recorder->handle(
                $current,
                BoutSlot::from($validated['winner']),
                $validated['akaScore'] !== '' ? (int) $validated['akaScore'] : null,
                $validated['aoScore'] !== '' ? (int) $validated['aoScore'] : null,
                BoutDecision::from($validated['decision']),
            );
        } catch (DrawException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        $this->refresh();
        Flux::toast(variant: 'success', text: __('Result recorded.'));

        if (app(TimetableBuilder::class)->progress($this->categories->firstWhere('id', $category->id)) === DrawProgress::Finished) {
            $this->moveToNextCategory($category);
        }

        $this->resetForm();
    }

    public function undo(int $boutId, UndoBoutResult $undoer): void
    {
        $this->tatamiBout($boutId);

        $last = $this->latestPlayed;

        if ($last === null || $last->id !== $boutId) {
            $this->refresh();
            Flux::toast(variant: 'warning', text: __('That is no longer the newest result. The screen was refreshed.'));

            return;
        }

        try {
            $undoer->handle($last);
        } catch (DrawException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        $this->categoryId = $last->category_id;
        $this->refresh();
        $this->resetForm();
        Flux::toast(variant: 'success', text: __('Result undone.'));
    }

    /**
     * A bout of one of this tatami's categories; anything else is a 404.
     */
    private function tatamiBout(int $boutId): Bout
    {
        return Bout::query()
            ->whereIn('category_id', $this->tatami->categories()->select('categories.id'))
            ->findOrFail($boutId);
    }

    private function requireCategory(): Category
    {
        $category = $this->activeCategory;

        abort_if($category === null, 404);

        return $category;
    }

    private function refresh(): void
    {
        unset($this->categories, $this->activeCategory, $this->queue, $this->latestPlayed);
    }

    private function moveToNextCategory(Category $finished): void
    {
        $builder = app(TimetableBuilder::class);
        $categories = $this->categories->values();
        $position = (int) $categories->search(fn (Category $category) => $category->is($finished));
        $isOpen = fn (Category $category) => in_array($builder->progress($category), [DrawProgress::Pending, DrawProgress::Running], true);

        $next = $categories->slice($position + 1)->first($isOpen) ?? $categories->first($isOpen);

        if ($next !== null) {
            $this->categoryId = $next->id;
            unset($this->activeCategory, $this->queue);
        }
    }

    private function resetForm(): void
    {
        $this->reset(['winner', 'akaScore', 'aoScore']);
        $this->decision = $this->activeCategory?->discipline === Discipline::Kata ? 'flags' : 'points';
        $this->resetValidation();
    }

    public function progressColor(DrawProgress $progress): string
    {
        return match ($progress) {
            DrawProgress::NoDraw => 'zinc',
            DrawProgress::Pending => 'blue',
            DrawProgress::Running => 'amber',
            DrawProgress::Finished => 'green',
        };
    }
}; ?>

<section class="mx-auto w-full max-w-2xl space-y-4">
    <div class="space-y-1">
        <a href="{{ route('tatami.index') }}" wire:navigate class="inline-flex min-h-11 items-center gap-1.5 text-[13px] text-arena-muted">
            <flux:icon.chevron-left class="size-4" />{{ __('Tatami') }}
        </a>

        <h1 class="font-display text-[28px] font-black uppercase leading-none tracking-[.04em] text-arena-ink">
            {{ __('Tatami :number', ['number' => $tatami->number]) }}
            @if ($tatami->name)
                <span class="text-arena-muted">· {{ $tatami->name }}</span>
            @endif
        </h1>

        <p class="text-sm text-arena-muted">{{ $tatami->competition->name }} · {{ $tatami->competition->date->format('d.m.Y.') }}</p>
    </div>

    @if ($this->categories->isEmpty())
        <x-arena.card variant="dashed" class="p-5">{{ __('No categories are scheduled on this tatami yet.') }}</x-arena.card>
    @else
        <flux:select wire:model.live="categoryId" :label="__('Category')" data-test="category-select">
            @foreach ($this->categories as $category)
                <flux:select.option :value="$category->id">
                    {{ $category->name }} — {{ match (app(TimetableBuilder::class)->progress($category)) {
                        DrawProgress::NoDraw => __('No draw'),
                        DrawProgress::Pending => __('Drawn'),
                        DrawProgress::Running => __('In progress'),
                        DrawProgress::Finished => __('Finished'),
                    } }}
                </flux:select.option>
            @endforeach
        </flux:select>

        @if ($this->latestPlayed)
            @php($last = $this->latestPlayed)

            <x-arena.card class="flex items-center justify-between gap-3 px-3 py-2 text-sm" data-test="last-result">
                <span class="min-w-0">
                    <span class="text-arena-muted">{{ __('Last result') }} · {{ $last->category->name }}:</span>
                    <span @class(['font-bold' => $last->winner_entry_id === $last->aka_entry_id])>{{ $last->aka->competitor->last_name }}</span>
                    <span class="font-mono tabular-nums">{{ $last->aka_score }}–{{ $last->ao_score }}</span>
                    <span @class(['font-bold' => $last->winner_entry_id === $last->ao_entry_id])>{{ $last->ao->competitor->last_name }}</span>
                </span>
                <x-arena.button variant="secondary" icon="arrow-uturn-left" class="shrink-0" wire:click="undo({{ $last->id }})" wire:confirm="{{ __('Undo the last result?') }}" data-test="undo-button">
                    {{ __('Undo') }}
                </x-arena.button>
            </x-arena.card>
        @endif

        @php($queue = $this->queue)

        @if ($queue === null)
            <x-arena.card variant="dashed" class="p-5">{{ __('Choose a category.') }}</x-arena.card>
        @elseif ($queue->current === null && $queue->recent->isEmpty() && $queue->upcoming->isEmpty())
            <x-arena.empty :title="__('No draw yet')" :text="__('Ask the administrator to generate the draw for this category.')" />
        @else
            @if ($queue->current)
                @php($current = $queue->current)

                <div class="space-y-4" data-test="current-bout">
                    <div class="flex items-center justify-between gap-3">
                        <x-arena.status state="live" :label="__('Now')" />
                        <span class="font-mono text-xs uppercase tracking-[.08em] text-arena-muted">
                            {{ $current->bracket === BoutBracket::Repechage ? __('Repechage') : __('Round :number', ['number' => $current->round]) }}
                        </span>
                    </div>

                    <form
                        wire:submit="record({{ $current->id }})"
                        x-data="{
                            winner: $wire.entangle('winner'),
                            step(side, delta) {
                                const input = this.$refs[side]

                                input.value = Math.min(255, Math.max(0, (parseInt(input.value, 10) || 0) + delta))
                                input.dispatchEvent(new Event('input', { bubbles: true }))
                            },
                        }"
                        class="space-y-4"
                    >
                        <div class="relative">
                            @foreach (['aka' => $current->aka, 'ao' => $current->ao] as $side => $entry)
                                @php($scoreLabel = $side === 'aka' ? __('AKA score') : __('AO score'))

                                <div
                                    x-bind:class="{ 'arena-sweep': winner === '{{ $side }}', 'opacity-45': winner !== '' && winner !== '{{ $side }}' }"
                                    @class([
                                        'relative flex h-[156px] overflow-hidden text-white transition-opacity',
                                        'bg-arena-aka [clip-path:polygon(0_0,100%_0,100%_calc(100%-14px),0_100%)]' => $side === 'aka',
                                        '-mt-1.5 bg-arena-ao [clip-path:polygon(0_14px,100%_0,100%_100%,0_100%)]' => $side === 'ao',
                                    ])
                                >
                                    <button
                                        type="button"
                                        data-test="winner-{{ $side }}"
                                        x-on:click="winner = '{{ $side }}'"
                                        aria-pressed="{{ $winner === $side ? 'true' : 'false' }}"
                                        x-bind:aria-pressed="(winner === '{{ $side }}').toString()"
                                        @class([
                                            'relative flex min-h-11 min-w-0 flex-1 cursor-pointer flex-col justify-center gap-1 ps-4 pe-2 text-start [overflow-wrap:anywhere] focus-visible:-outline-offset-4',
                                            'py-3.5' => $side === 'aka',
                                            'pb-2.5 pt-5' => $side === 'ao',
                                        ])
                                    >
                                        <span class="text-xs font-extrabold uppercase tracking-[.16em]">{{ strtoupper($side) }} · {{ __('tap to pick the winner') }}</span>
                                        <span class="font-display text-4xl font-black uppercase leading-[.95]">{{ $entry->competitor->last_name }} {{ $entry->competitor->first_name }}</span>
                                        <span class="text-[13px]">{{ $entry->competitor->club->name }}</span>
                                        <span x-show="winner === '{{ $side }}'" x-cloak class="mt-1 animate-arena-pop self-start bg-arena-volt px-2.5 py-1 text-xs font-extrabold uppercase tracking-[.12em] text-(--arena-live-ink)">{{ __('Winner') }}</span>
                                    </button>

                                    <div @class(['relative flex flex-col items-center justify-center gap-0.5 pe-2.5', 'pb-3.5' => $side === 'aka', 'pt-2' => $side === 'ao'])>
                                        <button type="button" x-on:click="step('{{ $side }}', 1)" aria-label="{{ $scoreLabel }} +" class="h-11 w-12 cursor-pointer bg-black/20 text-[22px] font-bold">+</button>
                                        <input
                                            x-ref="{{ $side }}"
                                            wire:model="{{ $side }}Score"
                                            type="number"
                                            min="0"
                                            max="255"
                                            inputmode="numeric"
                                            placeholder="0"
                                            aria-label="{{ $scoreLabel }}"
                                            class="w-14 bg-transparent text-center font-display text-[44px] font-black leading-none text-white [appearance:textfield] placeholder:text-white [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                                        >
                                        <button type="button" x-on:click="step('{{ $side }}', -1)" aria-label="{{ $scoreLabel }} −" class="h-11 w-12 cursor-pointer bg-black/20 text-[22px] font-bold">−</button>
                                    </div>
                                </div>
                            @endforeach

                            <span aria-hidden="true" class="absolute left-1/2 top-[150px] z-10 -translate-x-1/2 -skew-x-10 bg-arena-bg px-3 py-0.5 font-display text-lg font-black text-arena-ink">VS</span>
                        </div>
                        <flux:error name="winner" />

                        <fieldset class="space-y-2">
                            <legend class="text-[11px] uppercase tracking-[.12em] text-arena-muted">{{ __('Decision') }}</legend>

                            <div class="grid grid-cols-5 gap-1.5">
                                @foreach (BoutDecision::cases() as $case)
                                    @if ($case !== BoutDecision::Bye)
                                        <label class="relative flex min-h-11 cursor-pointer items-center justify-center border border-arena-line bg-arena-surface px-0.5 text-center text-xs font-bold text-arena-ink has-checked:border-arena-ink has-checked:bg-arena-ink has-checked:text-arena-bg has-focus-visible:outline-[3px] has-focus-visible:outline-offset-2 has-focus-visible:outline-(--arena-focus)">
                                            <input type="radio" name="decision" value="{{ $case->value }}" wire:model.live="decision" class="sr-only">
                                            {{ __(Str::headline($case->value)) }}
                                        </label>
                                    @endif
                                @endforeach
                            </div>
                        </fieldset>

                        <p class="text-sm text-arena-muted">{{ __('Scores are required for points and flags; the winner must have the higher score.') }}</p>
                        <flux:error name="decision" />
                        <flux:error name="akaScore" />
                        <flux:error name="aoScore" />

                        <x-arena.button
                            type="submit"
                            class="w-full aria-disabled:cursor-not-allowed aria-disabled:bg-arena-surface-2! aria-disabled:text-arena-muted!"
                            :aria-disabled="$winner === '' ? 'true' : 'false'"
                            x-on:click="if (winner === '') $event.preventDefault()"
                            x-bind:aria-disabled="(winner === '').toString()"
                            data-test="record-result-button"
                        >
                            <span x-show="winner !== ''" x-cloak>{{ __('Record result') }}</span>
                            <span x-show="winner === ''">{{ __('Pick the winner') }}</span>
                        </x-arena.button>
                    </form>
                </div>
            @elseif ($queue->upcoming->isNotEmpty())
                <x-arena.empty :title="__('Waiting for entrants')" :text="__('No bout is ready. The next bouts need earlier results first.')" />
            @else
                <x-arena.empty :title="__('Category finished')" :text="__('All bouts of this category have been played.')" />
            @endif

            @if ($queue->upcoming->isNotEmpty())
                <div class="space-y-2">
                    <x-arena.section-title>{{ __('Next') }}</x-arena.section-title>

                    <x-arena.card>
                        @foreach ($queue->upcoming as $bout)
                            <div class="flex items-center justify-between gap-3 border-b border-arena-line px-3 py-2.5 text-sm last:border-b-0" wire:key="next-{{ $bout->id }}">
                                <span class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <span class="flex items-center gap-2">
                                        <span aria-hidden="true" class="h-[18px] w-1 shrink-0 bg-arena-aka-text"></span>
                                        <span><span class="sr-only">AKA </span>{{ $bout->aka ? $bout->aka->competitor->last_name.' '.$bout->aka->competitor->first_name : '—' }}</span>
                                    </span>
                                    <span class="flex items-center gap-2">
                                        <span aria-hidden="true" class="h-[18px] w-1 shrink-0 bg-arena-ao-text"></span>
                                        <span><span class="sr-only">AO </span>{{ $bout->ao ? $bout->ao->competitor->last_name.' '.$bout->ao->competitor->first_name : '—' }}</span>
                                    </span>
                                </span>
                                <span class="shrink-0 text-xs text-arena-muted">
                                    {{ $bout->bracket === BoutBracket::Repechage ? __('Repechage') : __('Round :number', ['number' => $bout->round]) }}
                                </span>
                            </div>
                        @endforeach
                    </x-arena.card>
                </div>
            @endif

            @if ($queue->recent->isNotEmpty())
                <div class="space-y-2">
                    <x-arena.section-title>{{ __('Played') }}</x-arena.section-title>

                    <x-arena.card>
                        @foreach ($queue->recent as $bout)
                            <div class="border-b border-arena-line px-3 py-2.5 text-sm last:border-b-0" wire:key="played-{{ $bout->id }}">
                                <span @class(['font-bold' => $bout->winner_entry_id === $bout->aka_entry_id])>{{ $bout->aka->competitor->last_name }} {{ $bout->aka->competitor->first_name }}</span>
                                <span class="font-mono tabular-nums">{{ $bout->aka_score }}–{{ $bout->ao_score }}</span>
                                <span @class(['font-bold' => $bout->winner_entry_id === $bout->ao_entry_id])>{{ $bout->ao->competitor->last_name }} {{ $bout->ao->competitor->first_name }}</span>
                                <span class="text-arena-muted">· {{ __(Str::headline($bout->decision->value)) }}</span>
                            </div>
                        @endforeach
                    </x-arena.card>
                </div>
            @endif
        @endif
    @endif
</section>
