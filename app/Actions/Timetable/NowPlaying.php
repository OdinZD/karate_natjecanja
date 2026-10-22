<?php

namespace App\Actions\Timetable;

use App\Enums\DrawProgress;

class NowPlaying
{
    public function __construct(private readonly TatamiBouts $bouts) {}

    /**
     * What is being played on each tatami of a time table: the first running category, otherwise the first
     * one that is drawn but not started, together with its current and upcoming bouts.
     *
     * @param  array<int, TimetableTatami>  $table  Result of TimetableBuilder::build()
     * @return array<int, NowPlayingRow>
     */
    public function handle(array $table): array
    {
        return array_map(function (TimetableTatami $block): NowPlayingRow {
            $rows = collect($block->rows);
            $active = $rows->first(fn (TimetableRow $row) => $row->progress === DrawProgress::Running)
                ?? $rows->first(fn (TimetableRow $row) => $row->progress === DrawProgress::Pending);

            if ($active === null) {
                return new NowPlayingRow($block->tatami, null, null);
            }

            return new NowPlayingRow($block->tatami, $active->category, $this->bouts->handle($active->category));
        }, $table);
    }
}
