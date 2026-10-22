<?php

namespace App\Actions\Results;

final readonly class ClubStandingRow
{
    public function __construct(
        public int $rank,
        public int $clubId,
        public string $club,
        public ?string $city,
        public int $gold,
        public int $silver,
        public int $bronze,
        public int $total,
    ) {}
}
