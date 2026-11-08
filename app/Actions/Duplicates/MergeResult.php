<?php

namespace App\Actions\Duplicates;

final readonly class MergeResult
{
    public function __construct(
        public int $moved = 0,
        public int $dropped = 0,
        public ?string $error = null,
    ) {}

    public function failed(): bool
    {
        return $this->error !== null;
    }
}
