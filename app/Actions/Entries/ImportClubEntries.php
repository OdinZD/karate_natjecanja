<?php

namespace App\Actions\Entries;

use App\Enums\CompetitionStatus;
use App\Enums\Discipline;
use App\Enums\Gender;
use App\Models\Bout;
use App\Models\Category;
use App\Models\Club;
use App\Models\ClubImport;
use App\Models\Competition;
use App\Models\Competitor;
use App\Models\Entry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Common\Exception\OpenSpoutException;

/**
 * Imports the sheet a club filled in from the entry template: the club is chosen by the user and the
 * categories are found from each competitor's gender, birth year and weight.
 */
class ImportClubEntries
{
    /**
     * Normalised header names accepted for every column.
     *
     * @var array<string, array<int, string>>
     */
    private const HEADERS = [
        'first_name' => ['ime', 'first name'],
        'last_name' => ['prezime', 'last name'],
        'gender' => ['spol', 'gender'],
        'birth_date' => ['datum rodenja', 'date of birth'],
        'weight' => ['tezina', 'weight'],
        'kata' => ['kata', 'kata da'],
        'kumite' => ['kumite', 'kumite da'],
    ];

    /**
     * Column names shown in error messages.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'first_name' => 'Ime',
        'last_name' => 'Prezime',
        'gender' => 'Spol',
        'birth_date' => 'Datum rođenja',
        'weight' => 'Težina',
        'kata' => 'Kata',
        'kumite' => 'Kumite',
    ];

    private const REQUIRED = ['first_name', 'last_name', 'gender', 'birth_date', 'kata', 'kumite'];

    public const MODE_ALL = 'all';

    public const MODE_VALID = 'valid';

    private const MAX_ROWS = 1000;

    public function __construct(
        private readonly EntrySheet $sheet,
        private readonly CategoryMatcher $matcher,
    ) {}

    /**
     * Import a club's entries from an XLSX or CSV file. Nothing is saved unless every row is valid.
     */
    public function handle(Competition $competition, Club $club, string $path, string $extension): ImportEntriesResult
    {
        return $this->commit($club, $this->analyze($competition, $club, $path, $extension));
    }

    /**
     * Work out what the file would do without saving anything.
     */
    public function analyze(Competition $competition, Club $club, string $path, string $extension): ClubImportPreview
    {
        try {
            return $this->read($competition, $club, $path, $extension)->withWarnings($this->competitionWarnings($competition));
        } catch (OpenSpoutException $exception) {
            report($exception);

            return new ClubImportPreview(fileErrors: [__('The file could not be read. Is it a valid XLSX or CSV file?')]);
        }
    }

    /**
     * What is odd about importing into this competition now, without being a reason to refuse.
     *
     * @return array<int, string>
     */
    private function competitionWarnings(Competition $competition): array
    {
        $warnings = [];

        if ($competition->status !== CompetitionStatus::Registration) {
            $warnings[] = __('The competition is not in the registration phase.');
        }

        if ($competition->registration_deadline !== null && $competition->registration_deadline->isPast()) {
            $warnings[] = __('The registration deadline passed on :date.', ['date' => $competition->registration_deadline->format('d.m.Y. H:i')]);
        }

        return $warnings;
    }

    /**
     * Save a preview: every row in "all" mode (refused while any row has a problem), only the rows
     * without problems in "valid" mode. With $replace off, entries the club changed (a new weight or a
     * new category) are left as they are. A given $log is filled in and saved, whatever the outcome.
     */
    public function commit(Club $club, ClubImportPreview $preview, string $mode = self::MODE_ALL, bool $replace = true, ?ClubImport $log = null): ImportEntriesResult
    {
        if (! in_array($mode, [self::MODE_ALL, self::MODE_VALID], true)) {
            throw new InvalidArgumentException('Unknown import mode "'.$mode.'".');
        }

        $refusal = match (true) {
            $preview->fileErrors !== [] => new ImportEntriesResult(errors: $preview->fileErrors),
            $mode === self::MODE_ALL && $preview->errorCount() > 0 => new ImportEntriesResult(entriesSkipped: $preview->existingEntryCount(), errors: $preview->errors()),
            default => null,
        };

        if ($refusal !== null) {
            $this->record($log, $club, $preview, $mode, $refusal);

            return $refusal;
        }

        return DB::transaction(function () use ($club, $preview, $mode, $replace, $log): ImportEntriesResult {
            $created = 0;
            $replaced = 0;
            $skipped = $preview->existingEntryCount();
            $competitorsCreated = 0;

            // The log goes in first so the entries and competitors it creates can point back to it.
            $log?->fill(['club_id' => $club->id, 'mode' => $mode, 'status' => ClubImport::IMPORTED])->save();

            foreach ($preview->rows as $row) {
                if ($row->failed()) {
                    continue;
                }

                foreach ($row->planned as $item) {
                    if ($item['entry'] !== null) {
                        if ($replace) {
                            // A move starts the entry afresh in its new category, so the old seed number is dropped.
                            $item['entry']->update([
                                'category_id' => $item['category']->id,
                                'weight' => $item['weight'],
                                ...($item['state'] === 'replace' ? ['seed' => null] : []),
                            ]);
                            $replaced++;
                        } else {
                            $skipped++;
                        }

                        continue;
                    }

                    if (! $row->competitor->exists) {
                        $row->competitor->club_id = $club->id;
                        $row->competitor->created_by_import_id = $log?->id;
                        $row->competitor->save();
                        $competitorsCreated++;
                    }

                    $entry = Entry::firstOrCreate(
                        ['category_id' => $item['category']->id, 'competitor_id' => $row->competitor->id],
                        ['weight' => $item['weight'], 'club_import_id' => $log?->id],
                    );

                    $entry->wasRecentlyCreated ? $created++ : $skipped++;
                }
            }

            $result = new ImportEntriesResult($created, $skipped, $competitorsCreated, 0, entriesReplaced: $replaced);
            $this->record($log, $club, $preview, $mode, $result);

            return $result;
        });
    }

    /**
     * Write the import log and make sure the club is listed for the competition.
     */
    private function record(?ClubImport $log, Club $club, ClubImportPreview $preview, string $mode, ImportEntriesResult $result): void
    {
        if ($log === null) {
            return;
        }

        $errors = $result->failed() ? $result->errors : $preview->errors();

        $log->fill([
            'club_id' => $club->id,
            'mode' => $mode,
            'status' => $result->failed() ? ClubImport::REJECTED : ClubImport::IMPORTED,
            'rows_total' => count($preview->rows),
            'rows_valid' => $preview->validCount(),
            'rows_failed' => $preview->errorCount(),
            'entries_created' => $result->entriesCreated,
            'entries_replaced' => $result->entriesReplaced,
            'entries_skipped' => $result->entriesSkipped,
            'competitors_created' => $result->competitorsCreated,
            'error_count' => count($errors),
            'errors' => array_slice($errors, 0, 50),
        ])->save();

        $log->competition->clubs()->syncWithoutDetaching([$club->id]);
    }

    /**
     * The club and competition a club template was prepared for, empty for a plain file.
     *
     * @return array{club_id: ?int, competition_id: ?int}
     */
    public function meta(string $path, string $extension): array
    {
        try {
            $values = $this->sheet->keyValues($path, $extension, EntryTemplate::META_SHEET);
        } catch (OpenSpoutException $exception) {
            report($exception);

            $values = [];
        }

        $id = fn (string $key): ?int => ctype_digit($values[$key] ?? '') ? (int) $values[$key] : null;

        return ['club_id' => $id('club_id'), 'competition_id' => $id('competition_id')];
    }

    private function read(Competition $competition, Club $club, string $path, string $extension): ClubImportPreview
    {
        $meta = $this->meta($path, $extension);
        $prepared = $meta['club_id'] !== null;

        $categories = $competition->categories()->get();

        $drawn = Bout::query()
            ->whereIn('category_id', $categories->pluck('id'))
            ->distinct()
            ->pluck('category_id')
            ->flip();

        $existingEntries = Entry::query()
            ->whereIn('category_id', $categories->pluck('id'))
            ->get(['id', 'category_id', 'competitor_id', 'weight', 'weighed_at']);

        /** @var array<string, int>|null $columns */
        $columns = null;
        /** @var array<string, Competitor> $competitors */
        $competitors = [];
        /** @var array<int, array<string, mixed>> $parsed */
        $parsed = [];

        foreach ($this->sheet->rows($path, $extension) as $number => $cells) {
            $values = $this->sheet->values($cells);

            if (implode('', $values) === '') {
                continue;
            }

            if ($columns === null) {
                $columns = $this->sheet->mapColumns($values, self::HEADERS);
                $missing = array_diff(self::REQUIRED, array_keys($columns));

                if ($missing !== []) {
                    return new ClubImportPreview(fileErrors: [
                        __('Missing required column(s): :columns.', ['columns' => implode(', ', array_map(fn (string $field) => self::LABELS[$field], $missing))]),
                    ], meta: $meta);
                }

                continue;
            }

            if (count($parsed) >= self::MAX_ROWS) {
                return new ClubImportPreview(fileErrors: [__('The file has more than :max rows. Split it into smaller files.', ['max' => self::MAX_ROWS])], meta: $meta);
            }

            $row = [];
            foreach (self::HEADERS as $field => $aliases) {
                $row[$field] = isset($columns[$field]) ? ($values[$columns[$field]] ?? '') : '';
            }

            $item = [
                'number' => $number,
                'raw' => $row,
                'name' => trim($row['first_name'].' '.$row['last_name']),
                'gender' => null,
                'year' => null,
                'date' => null,
                'weight' => null,
                'messages' => [],
                'idle' => false,
                'competitor' => null,
                'key' => null,
                'candidates' => [],
            ];

            $problems = [];

            foreach (['first_name', 'last_name'] as $field) {
                if ($row[$field] === '') {
                    $problems[] = __('The :field is empty.', ['field' => self::LABELS[$field]]);
                }
            }

            $gender = $this->sheet->parseGender($row['gender']);
            if ($gender === null) {
                $problems[] = __('Unknown gender ":value".', ['value' => $row['gender']]);
            }

            $birthDate = $this->sheet->parseDate($row['birth_date']);
            if ($birthDate === null) {
                $problems[] = __('Invalid date of birth ":value".', ['value' => $row['birth_date']]);
            }

            $weight = null;
            if ($row['weight'] !== '') {
                $weight = $this->sheet->parseWeight($row['weight']);

                if ($weight === null) {
                    $problems[] = __('Invalid weight ":value".', ['value' => $row['weight']]);
                }
            }

            $disciplines = [];
            foreach ([Discipline::Kata, Discipline::Kumite] as $discipline) {
                $marked = $this->parseFlag($row[$discipline->value]);

                if ($marked === null) {
                    $problems[] = __('Invalid value ":value" in column :column. Use DA or leave it empty.', [
                        'value' => $row[$discipline->value],
                        'column' => self::LABELS[$discipline->value],
                    ]);
                } elseif ($marked) {
                    $disciplines[] = $discipline;
                }
            }

            // A club template lists every known child, so a child with nothing marked is simply not entered.
            if ($disciplines === [] && ! $this->hasFlagProblem($row)) {
                if ($prepared && $row['weight'] === '') {
                    $item['idle'] = true;
                } else {
                    $problems[] = __('Neither Kata nor Kumite is marked.');
                }
            }

            if (in_array(Discipline::Kumite, $disciplines, true) && $row['weight'] === '') {
                $problems[] = __('Enter the weight for Kumite.');
            }

            $item['gender'] = $gender;
            $item['year'] = $birthDate?->year;
            $item['date'] = $birthDate?->format('Y-m-d');
            $item['weight'] = $weight;

            if ($problems !== []) {
                $item['messages'] = [implode(' ', $problems)];
                $parsed[] = $item;

                continue;
            }

            $item['key'] = implode('|', [$this->sheet->normalizeName($row['first_name']), $this->sheet->normalizeName($row['last_name']), $birthDate->format('Y-m-d')]);
            $competitor = $competitors[$item['key']] ??= $this->sheet->findOrBuildCompetitor($club, $row['first_name'], $row['last_name'], $gender, $birthDate);
            $item['competitor'] = $competitor;

            if ($competitor->gender !== $gender) {
                $item['messages'] = [__('Gender ":value" differs from the gender already recorded for this competitor.', ['value' => $row['gender']])];
                $parsed[] = $item;

                continue;
            }

            foreach ($disciplines as $discipline) {
                $entryWeight = $discipline === Discipline::Kumite ? $weight : null;
                $category = $this->matcher->match($categories, $discipline, $gender, $birthDate->year, $entryWeight !== null ? (float) $entryWeight : null);

                if ($category === null) {
                    $item['messages'][] = $this->noCategory($discipline, $gender, $birthDate->year, $entryWeight);

                    continue;
                }

                if (isset($drawn[$category->id])) {
                    $item['messages'][] = __('Category ":value" already has a draw.', ['value' => $category->name]);

                    continue;
                }

                $item['candidates'][] = ['discipline' => $discipline, 'category' => $category, 'weight' => $entryWeight];
            }

            $parsed[] = $item;
        }

        if ($columns === null) {
            return new ClubImportPreview(fileErrors: [__('The file is empty.')], meta: $meta);
        }

        return new ClubImportPreview($this->buildRows($parsed, $existingEntries, $categories, $drawn, $this->namesInOtherClubs($club, $parsed)), meta: $meta);
    }

    /**
     * Competitors of other clubs born on the same dates as the file's children, by normalised name and date,
     * with the name of their club.
     *
     * @param  array<int, array<string, mixed>>  $parsed
     * @return array<string, string>
     */
    private function namesInOtherClubs(Club $club, array $parsed): array
    {
        $dates = array_values(array_unique(array_filter(array_column($parsed, 'date'))));

        $found = [];

        foreach (array_chunk($dates, 200) as $chunk) {
            Competitor::query()
                ->with('club')
                ->where(function ($query) use ($chunk): void {
                    foreach ($chunk as $date) {
                        $query->orWhereDate('birth_date', $date);
                    }
                })
                ->when($club->exists, fn ($query) => $query->where('club_id', '!=', $club->id))
                ->get()
                ->each(function (Competitor $competitor) use (&$found): void {
                    $key = implode('|', [$this->sheet->normalizeName($competitor->first_name), $this->sheet->normalizeName($competitor->last_name), $competitor->birth_date->format('Y-m-d')]);
                    $found[$key] ??= $competitor->club->name;
                });
        }

        return $found;
    }

    /**
     * Decide per row what is new, what is already entered and what the club changed. Rows with problems
     * never take a spot, so leaving them out later does not change what the other rows do.
     *
     * An entry the competitor already has in the same category only gets its weight updated. One in
     * another category of the same discipline is moved to the new category, provided it is the only one
     * and its category has no draw.
     *
     * @param  array<int, array<string, mixed>>  $parsed
     * @param  Collection<int, Entry>  $existingEntries
     * @param  Collection<int, Category>  $categories
     * @param  Collection<int, int>  $drawn  Category ids with a draw, as keys.
     * @param  array<string, string>  $elsewhere  Club names of competitors with the same name and birth date in other clubs.
     * @return array<int, ClubImportRow>
     */
    private function buildRows(array $parsed, Collection $existingEntries, Collection $categories, Collection $drawn, array $elsewhere): array
    {
        $categories = $categories->keyBy('id');
        $byKey = $existingEntries->keyBy(fn (Entry $entry) => $entry->category_id.'|'.$entry->competitor_id);
        $byDiscipline = $existingEntries->groupBy(fn (Entry $entry) => $entry->competitor_id.'|'.$categories[$entry->category_id]->discipline->value);

        $seen = [];
        $claimed = [];
        $rows = [];

        foreach ($parsed as $item) {
            $problems = $item['messages'];
            $notes = [];
            $resolved = [];

            foreach ($item['candidates'] as $candidate) {
                /** @var Category $category */
                $category = $candidate['category'];
                $competitor = $item['competitor'];
                $key = $category->id.'|'.$item['key'];
                $state = 'new';
                $target = null;

                if (isset($seen[$key])) {
                    $state = 'existing';
                } elseif ($competitor->exists) {
                    $same = $byKey[$category->id.'|'.$competitor->id] ?? null;
                    $others = $byDiscipline[$competitor->id.'|'.$candidate['discipline']->value] ?? collect();
                    $others = $others->reject(fn (Entry $entry) => $entry->category_id === $category->id);

                    if ($same !== null) {
                        // A weighed entry is final: a later import with the weight the club entered must not undo the weigh-in.
                        $state = isset($claimed[$same->id]) || $same->weighed_at !== null || ! $this->weightChanged($same->weight, $candidate['weight']) ? 'existing' : 'update';
                        $target = $state === 'update' ? $same : null;
                    } elseif ($others->count() === 1 && ! isset($claimed[$others->first()->id])) {
                        $old = $others->first();

                        if ($old->weighed_at !== null) {
                            // Final, like a weighed entry in the same category.
                            $state = 'existing';
                        } elseif (isset($drawn[$old->category_id])) {
                            // A change that would touch a drawn category is a problem of the row even when changes are
                            // switched off in the preview: the file contradicts a draw that has already been made.
                            $problems[] = __('Already entered in ":value", which has a draw, so that entry cannot be changed.', ['value' => $categories[$old->category_id]->name]);
                        } else {
                            $state = 'replace';
                            $target = $old;
                        }
                    } elseif ($others->count() > 1) {
                        $notes[] = __('Already entered in several :discipline categories, so the new entry is added next to them.', ['discipline' => __(Str::headline($candidate['discipline']->value))]);
                    }
                }

                $resolved[] = ['candidate' => $candidate, 'key' => $key, 'state' => $state, 'target' => $target];
            }

            if ($item['competitor'] !== null && ! $item['competitor']->exists && isset($elsewhere[$item['key']])) {
                $notes[] = __('A competitor with this name and date of birth already exists in :club. Has the child changed clubs?', ['club' => $elsewhere[$item['key']]]);
            }

            $failed = $problems !== [];
            $messages = [...$problems, ...$notes];
            $entries = [];
            $planned = [];

            foreach ($resolved as $entry) {
                /** @var Category $category */
                $category = $entry['candidate']['category'];
                $target = $entry['target'];

                $entries[] = [
                    'category' => $category->name,
                    'discipline' => $entry['candidate']['discipline']->value,
                    'weight' => $entry['candidate']['weight'],
                    'state' => $entry['state'],
                    'replaces' => $entry['state'] === 'replace' ? $categories[$target->category_id]->name : null,
                    'previous_weight' => $entry['state'] === 'update' && $target->weight !== null ? (string) (float) $target->weight : null,
                ];

                if ($entry['state'] !== 'existing' && ! $failed) {
                    $seen[$entry['key']] = true;

                    if ($target !== null) {
                        $claimed[$target->id] = true;
                    }

                    $planned[] = ['category' => $category, 'weight' => $entry['candidate']['weight'], 'entry' => $target, 'state' => $entry['state']];
                }
            }

            if ($failed) {
                $status = ClubImportRow::ERROR;
            } elseif ($item['idle']) {
                $status = ClubImportRow::SKIPPED;
                $messages = [__('No discipline is marked, so the row is left out.')];
            } else {
                $status = $planned === [] ? ClubImportRow::SKIPPED : ClubImportRow::OK;
            }

            $rows[] = new ClubImportRow(
                $item['number'],
                $item['name'],
                $item['gender'],
                $item['year'],
                $item['weight'],
                $status,
                $item['raw'],
                $messages,
                $entries,
                $item['competitor'],
                $planned,
            );
        }

        return $rows;
    }

    private function weightChanged(?string $stored, ?string $entered): bool
    {
        // Only Kumite carries a weight in the file, so an entry without one never changes a stored weight.
        if ($entered === null) {
            return false;
        }

        if ($stored === null) {
            return true;
        }

        return abs((float) $stored - (float) $entered) > 0.001;
    }

    /**
     * Whether the Kata or Kumite cell holds something other than DA/NE, which is reported on its own.
     *
     * @param  array<string, string>  $row
     */
    private function hasFlagProblem(array $row): bool
    {
        return $this->parseFlag($row['kata']) === null || $this->parseFlag($row['kumite']) === null;
    }

    /**
     * True for DA, false for an empty cell or NE, null for anything else.
     */
    private function parseFlag(string $value): ?bool
    {
        return match (Str::lower(Str::ascii($value))) {
            'da', 'd', 'x', 'yes', '1' => true,
            '', 'ne', 'n', 'no', '0' => false,
            default => null,
        };
    }

    private function noCategory(Discipline $discipline, Gender $gender, int $birthYear, ?string $weight): string
    {
        $replace = [
            'discipline' => __(Str::headline($discipline->value)),
            'gender' => __(Str::headline($gender->value)),
            'year' => $birthYear,
        ];

        return $weight === null
            ? __('No :discipline category fits: :gender, born :year.', $replace)
            : __('No :discipline category fits: :gender, born :year, :weight kg.', [...$replace, 'weight' => $weight]);
    }
}
