<?php

namespace App\Actions\Timetable;

use App\Models\Category;
use App\Models\Tatami;

final readonly class NowPlayingRow
{
    public function __construct(
        public Tatami $tatami,
        public ?Category $category,
        public ?ConsoleQueue $queue,
    ) {}
}
