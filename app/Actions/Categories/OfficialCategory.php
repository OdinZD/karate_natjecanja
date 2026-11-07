<?php

namespace App\Actions\Categories;

use App\Enums\Discipline;
use App\Enums\Gender;

final readonly class OfficialCategory
{
    public function __construct(
        public string $name,
        public Discipline $discipline,
        public Gender $gender,
        public int $birthYearFrom,
        public int $birthYearTo,
        public ?int $weightMin,
        public ?int $weightMax,
        public int $boutDurationSeconds,
    ) {}
}
