<?php

namespace App\Actions\Entries;

final readonly class ImportEntriesResult
{
    /**
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public int $entriesCreated = 0,
        public int $entriesSkipped = 0,
        public int $competitorsCreated = 0,
        public int $clubsCreated = 0,
        public array $errors = [],
    ) {}

    public function failed(): bool
    {
        return $this->errors !== [];
    }
}
