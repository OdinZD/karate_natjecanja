<?php

namespace App\Actions\Entries;

final readonly class UndoClubImportResult
{
    public function __construct(
        public int $entriesRemoved = 0,
        public int $competitorsRemoved = 0,
        public ?string $error = null,
    ) {}

    public function failed(): bool
    {
        return $this->error !== null;
    }
}
