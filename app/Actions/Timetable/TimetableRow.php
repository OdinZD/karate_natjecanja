<?php

namespace App\Actions\Timetable;

use App\Enums\DrawProgress;
use App\Models\Category;
use Carbon\CarbonImmutable;

final readonly class TimetableRow
{
    public function __construct(
        public Category $category,
        public int $entries,
        public int $bouts,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public DrawProgress $progress,
    ) {}
}
