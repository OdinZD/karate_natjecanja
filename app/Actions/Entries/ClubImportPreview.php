<?php

namespace App\Actions\Entries;

/**
 * What importing a club's file would do, worked out without saving anything.
 */
final readonly class ClubImportPreview
{
    /**
     * @param  array<int, ClubImportRow>  $rows
     * @param  array<int, string>  $fileErrors  Problems that stop the whole file, e.g. a missing column.
     * @param  array{club_id: ?int, competition_id: ?int}  $meta  Who the file was prepared for, when the template says so.
     * @param  array<int, string>  $warnings  Things to check about the competition that do not stop the import.
     */
    public function __construct(
        public array $rows = [],
        public array $fileErrors = [],
        public array $meta = ['club_id' => null, 'competition_id' => null],
        public array $warnings = [],
    ) {}

    /**
     * @param  array<int, string>  $warnings
     */
    public function withWarnings(array $warnings): self
    {
        return new self($this->rows, $this->fileErrors, $this->meta, $warnings);
    }

    public function failed(): bool
    {
        return $this->fileErrors !== [] || $this->errorCount() > 0;
    }

    /**
     * @return array<int, ClubImportRow>
     */
    public function errorRows(): array
    {
        return array_values(array_filter($this->rows, fn (ClubImportRow $row) => $row->failed()));
    }

    public function errorCount(): int
    {
        return count($this->errorRows());
    }

    public function validCount(): int
    {
        return count($this->rows) - $this->errorCount();
    }

    public function readyCount(): int
    {
        return count(array_filter($this->rows, fn (ClubImportRow $row) => $row->status === ClubImportRow::OK));
    }

    public function newEntryCount(): int
    {
        return $this->countEntries(['new']);
    }

    public function existingEntryCount(): int
    {
        return $this->countEntries(['existing']);
    }

    /**
     * Entries the club changed: a new weight or a move to another category.
     */
    public function changedEntryCount(): int
    {
        return $this->countEntries(['update', 'replace']);
    }

    /**
     * Every problem as the single-line text the one-step import reports, in file order.
     *
     * @return array<int, string>
     */
    public function errors(): array
    {
        if ($this->fileErrors !== []) {
            return $this->fileErrors;
        }

        $errors = [];

        foreach ($this->errorRows() as $row) {
            foreach ($row->messages as $message) {
                $errors[] = __('Row :row: :problems', ['row' => $row->number, 'problems' => $message]);
            }
        }

        return $errors;
    }

    /**
     * @param  array<int, string>  $states
     */
    private function countEntries(array $states): int
    {
        $count = 0;

        foreach ($this->rows as $row) {
            if ($row->failed()) {
                continue;
            }

            foreach ($row->entries as $entry) {
                $count += in_array($entry['state'], $states, true) ? 1 : 0;
            }
        }

        return $count;
    }
}
