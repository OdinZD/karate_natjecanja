<?php

namespace App\Actions\Timetable;

use App\Models\Bout;
use Illuminate\Support\Collection;

final readonly class ConsoleQueue
{
    /**
     * @param  Collection<int, Bout>  $upcoming  Next unplayed bouts after the current one (ready or still waiting for entrants)
     * @param  Collection<int, Bout>  $recent  Latest played bouts, newest first
     */
    public function __construct(
        public ?Bout $current,
        public Collection $upcoming,
        public Collection $recent,
    ) {}
}
