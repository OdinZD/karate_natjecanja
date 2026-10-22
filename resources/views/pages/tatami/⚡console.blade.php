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

<section class="mx-auto w-full max-w-4xl space-y-8">
    <div>
        <flux:link :href="route('tatami.index')" wire:navigate class="text-sm">&larr; {{ __('Tatami') }}</flux:link>
        <flux:heading size="xl" level="1">
            {{ __('Tatami :number', ['number' => $tatami->number]) }}
            @if ($tatami->name)
                <span class="font-normal text-zinc-500">· {{ $tatami->name }}</span>
            @endif
        </flux:heading>
        <flux:text>{{ $tatami->competition->name }} · {{ $tatami->competition->date->format('d.m.Y.') }}</flux:text>
    </div>

    @if ($this->categories->isEmpty())
        <flux:text>{{ __('No categories are scheduled on this tatami yet.') }}</flux:text>
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

            <div class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 px-4 py-2 text-sm dark:border-zinc-700" data-test="last-result">
                <span>
                    <span class="text-zinc-500">{{ __('Last result') }} · {{ $last->category->name }}:</span>
                    <span @class(['font-semibold' => $last->winner_entry_id === $last->aka_entry_id])>{{ $last->aka->competitor->last_name }}</span>
                    {{ $last->aka_score }}–{{ $last->ao_score }}
                    <span @class(['font-semibold' => $last->winner_entry_id === $last->ao_entry_id])>{{ $last->ao->competitor->last_name }}</span>
                </span>
                <flux:button size="sm" variant="ghost" icon="arrow-uturn-left" wire:click="undo({{ $last->id }})" wire:confirm="{{ __('Undo the last result?') }}" data-test="undo-button">
                    {{ __('Undo') }}
                </flux:button>
            </div>
        @endif

        @php($queue = $this->queue)

        @if ($queue === null)
            <flux:text>{{ __('Choose a category.') }}</flux:text>
        @elseif ($queue->current === null && $queue->recent->isEmpty() && $queue->upcoming->isEmpty())
            <flux:callout icon="information-circle">
                <flux:callout.heading>{{ __('No draw yet') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Ask the administrator to generate the draw for this category.') }}</flux:callout.text>
            </flux:callout>
        @else
            @if ($queue->current)
                @php($current = $queue->current)

                <div class="space-y-5 rounded-2xl border border-zinc-200 p-5 dark:border-zinc-700" data-test="current-bout">
                    <div class="flex items-baseline justify-between">
                        <flux:heading size="lg">{{ __('Now') }}</flux:heading>
                        <flux:text class="text-sm">
                            {{ $current->bracket === BoutBracket::Repechage ? __('Repechage') : __('Round :number', ['number' => $current->round]) }}
                        </flux:text>
                    </div>

                    <form wire:submit="record({{ $current->id }})" class="space-y-5">
                        <div class="grid grid-cols-2 gap-3">
                            @foreach (['aka' => $current->aka, 'ao' => $current->ao] as $side => $entry)
                                <button
                                    type="button"
                                    wire:click="$set('winner', '{{ $side }}')"
                                    data-test="winner-{{ $side }}"
                                    @class([
                                        'rounded-xl border-2 p-4 text-start transition',
                                        'border-red-500' => $side === 'aka',
                                        'border-blue-500' => $side === 'ao',
                                        'bg-red-50 dark:bg-red-950/40' => $side === 'aka' && $winner === 'aka',
                                        'bg-blue-50 dark:bg-blue-950/40' => $side === 'ao' && $winner === 'ao',
                                        'opacity-60' => $winner !== '' && $winner !== $side,
                                    ])
                                >
                                    <div class="text-xs font-semibold tracking-wide {{ $side === 'aka' ? 'text-red-600' : 'text-blue-600' }}">{{ strtoupper($side) }}</div>
                                    <div class="mt-1 text-lg font-semibold">{{ $entry->competitor->last_name }} {{ $entry->competitor->first_name }}</div>
                                    <div class="text-sm text-zinc-500">{{ $entry->competitor->club->name }}</div>
                                    @if ($winner === $side)
                                        <div class="mt-2 text-sm font-medium">&check; {{ __('Winner') }}</div>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                        <flux:error name="winner" />

                        <div class="grid gap-4 sm:grid-cols-3">
                            <flux:select wire:model.live="decision" :label="__('Decision')">
                                @foreach (BoutDecision::cases() as $case)
                                    @if ($case !== BoutDecision::Bye)
                                        <flux:select.option :value="$case->value">{{ __(Str::headline($case->value)) }}</flux:select.option>
                                    @endif
                                @endforeach
                            </flux:select>
                            <flux:input wire:model="akaScore" type="number" min="0" max="255" inputmode="numeric" :label="__('AKA score')" />
                            <flux:input wire:model="aoScore" type="number" min="0" max="255" inputmode="numeric" :label="__('AO score')" />
                        </div>
                        <flux:text class="text-sm">{{ __('Scores are required for points and flags; the winner must have the higher score.') }}</flux:text>
                        <flux:error name="decision" />
                        <flux:error name="akaScore" />
                        <flux:error name="aoScore" />

                        <flux:button type="submit" variant="primary" class="w-full" data-test="record-result-button">
                            {{ __('Record result') }}
                        </flux:button>
                    </form>
                </div>
            @elseif ($queue->upcoming->isNotEmpty())
                <flux:callout icon="clock">
                    <flux:callout.heading>{{ __('Waiting for entrants') }}</flux:callout.heading>
                    <flux:callout.text>{{ __('No bout is ready. The next bouts need earlier results first.') }}</flux:callout.text>
                </flux:callout>
            @else
                <flux:callout icon="check-circle" variant="success">
                    <flux:callout.heading>{{ __('Category finished') }}</flux:callout.heading>
                    <flux:callout.text>{{ __('All bouts of this category have been played.') }}</flux:callout.text>
                </flux:callout>
            @endif

            @if ($queue->upcoming->isNotEmpty())
                <div class="space-y-2">
                    <flux:heading>{{ __('Next') }}</flux:heading>
                    @foreach ($queue->upcoming as $bout)
                        <div class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 px-4 py-2 text-sm dark:border-zinc-700" wire:key="next-{{ $bout->id }}">
                            <span>
                                {{ $bout->aka ? $bout->aka->competitor->last_name.' '.$bout->aka->competitor->first_name : '—' }}
                                <span class="text-zinc-400">vs</span>
                                {{ $bout->ao ? $bout->ao->competitor->last_name.' '.$bout->ao->competitor->first_name : '—' }}
                            </span>
                            <span class="text-zinc-500">
                                {{ $bout->bracket === BoutBracket::Repechage ? __('Repechage') : __('Round :number', ['number' => $bout->round]) }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($queue->recent->isNotEmpty())
                <div class="space-y-2">
                    <flux:heading>{{ __('Played') }}</flux:heading>
                    @foreach ($queue->recent as $bout)
                        <div class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 px-4 py-2 text-sm dark:border-zinc-700" wire:key="played-{{ $bout->id }}">
                            <span>
                                <span @class(['font-semibold' => $bout->winner_entry_id === $bout->aka_entry_id])>{{ $bout->aka->competitor->last_name }} {{ $bout->aka->competitor->first_name }}</span>
                                {{ $bout->aka_score }}–{{ $bout->ao_score }}
                                <span @class(['font-semibold' => $bout->winner_entry_id === $bout->ao_entry_id])>{{ $bout->ao->competitor->last_name }} {{ $bout->ao->competitor->first_name }}</span>
                                <span class="text-zinc-500">· {{ __(Str::headline($bout->decision->value)) }}</span>
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    @endif
</section>
