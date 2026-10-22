<?php

namespace App\Actions\Timetable;

use App\Enums\BoutBracket;
use App\Enums\BoutDecision;
use App\Enums\BoutStatus;
use App\Models\Bout;
use App\Models\Category;
use App\Models\Tatami;

class TatamiBouts
{
    private const LIST_SIZE = 5;

    public function __construct(private readonly BoutOrder $order) {}

    /**
     * What the operator needs for one category: the bout to play now, what follows and what was played last.
     */
    public function handle(Category $category): ConsoleQueue
    {
        $all = $category->bouts()->with(['aka.competitor.club', 'ao.competitor.club'])->get();
        $finalRound = (int) $all->where('bracket', BoutBracket::Main)->max('round');
        $bouts = $all->reject(fn (Bout $bout) => $bout->decision === BoutDecision::Bye);

        $open = $this->order->sort(
            $bouts->filter(fn (Bout $bout) => $bout->status !== BoutStatus::Finished),
            $finalRound,
        );

        $current = $open->first(fn (Bout $bout) => $bout->status === BoutStatus::Ready);

        $upcoming = $open
            ->reject(fn (Bout $bout) => $current !== null && $bout->is($current))
            ->take(self::LIST_SIZE)
            ->values();

        // Bouts decided within the same second keep their play order, the later bout counting as newer.
        $recent = $this->order
            ->sort($bouts->filter(fn (Bout $bout) => $bout->status === BoutStatus::Finished), $finalRound)
            ->reverse()
            ->sortByDesc(fn (Bout $bout) => $bout->finished_at?->getTimestamp() ?? 0)
            ->take(self::LIST_SIZE)
            ->values();

        return new ConsoleQueue($current, $upcoming, $recent);
    }

    /**
     * The newest real result across all categories of a tatami.
     */
    public function latestPlayed(Tatami $tatami): ?Bout
    {
        $finished = Bout::query()
            ->with(['category', 'aka.competitor', 'ao.competitor'])
            ->whereIn('category_id', $tatami->categories()->select('categories.id'))
            ->where('status', BoutStatus::Finished)
            ->where('decision', '!=', BoutDecision::Bye)
            ->get();

        $newest = $finished->max(fn (Bout $bout) => $bout->finished_at?->getTimestamp() ?? 0);

        // Results from the same second: the later category and, within it, the later bout counts as newer.
        return $finished
            ->filter(fn (Bout $bout) => ($bout->finished_at?->getTimestamp() ?? 0) === $newest)
            ->groupBy('category_id')
            ->sortBy(fn ($bouts) => [$bouts->first()->category->sort_order, $bouts->first()->category_id])
            ->map(fn ($bouts) => $this->order->sort($bouts, $bouts->first()->category->finalRound())->last())
            ->last();
    }
}
