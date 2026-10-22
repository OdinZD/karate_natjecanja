<?php

namespace App\Actions\Timetable;

use App\Models\Tatami;
use Carbon\CarbonImmutable;

final readonly class TimetableTatami
{
    /**
     * @param  array<int, TimetableRow>  $rows
     */
    public function __construct(
        public Tatami $tatami,
        public array $rows,
        public CarbonImmutable $end,
    ) {}

    public function totalBouts(): int
    {
        return array_sum(array_map(fn (TimetableRow $row) => $row->bouts, $this->rows));
    }

    public function durationMinutes(): int
    {
        return $this->rows === [] ? 0 : (int) round($this->rows[0]->start->diffInSeconds($this->end, true) / 60);
    }
}
