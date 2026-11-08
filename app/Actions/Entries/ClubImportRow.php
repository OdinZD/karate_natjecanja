<?php

namespace App\Actions\Entries;

use App\Enums\Gender;
use App\Models\Category;
use App\Models\Competitor;
use App\Models\Entry;

/**
 * One row of a club's entry file as understood by ImportClubEntries::analyze().
 */
final readonly class ClubImportRow
{
    public const OK = 'ok';

    public const ERROR = 'error';

    public const SKIPPED = 'skipped';

    /**
     * @param  array<string, string>  $raw  The cell values by field, as they were in the file.
     * @param  array<int, string>  $messages  One text per reported problem or note.
     * @param  array<int, array{category: string, discipline: string, weight: ?string, state: string, replaces: ?string, previous_weight: ?string}>  $entries  What the row enters, state is "new", "existing", "update" (new weight) or "replace" (moved from another category).
     * @param  array<int, array{category: Category, weight: ?string, entry: ?Entry, state: string}>  $planned  What a commit does for this row: create an entry, or change the given one.
     */
    public function __construct(
        public int $number,
        public string $name,
        public ?Gender $gender,
        public ?int $birthYear,
        public ?string $weight,
        public string $status,
        public array $raw = [],
        public array $messages = [],
        public array $entries = [],
        public ?Competitor $competitor = null,
        public array $planned = [],
    ) {}

    public function failed(): bool
    {
        return $this->status === self::ERROR;
    }

    /**
     * The row without the models, small enough to keep in a Livewire property.
     *
     * @return array{number: int, name: string, gender: ?string, birth_year: ?int, weight: ?string, status: string, messages: array<int, string>, entries: array<int, array{category: string, discipline: string, weight: ?string, state: string, replaces: ?string, previous_weight: ?string}>}
     */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'name' => $this->name,
            'gender' => $this->gender?->value,
            'birth_year' => $this->birthYear,
            'weight' => $this->weight,
            'status' => $this->status,
            'messages' => $this->messages,
            'entries' => $this->entries,
        ];
    }
}
