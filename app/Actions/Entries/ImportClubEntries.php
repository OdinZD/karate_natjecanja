<?php

namespace App\Actions\Entries;

use App\Enums\Discipline;
use App\Enums\Gender;
use App\Models\Bout;
use App\Models\Category;
use App\Models\Club;
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
            return $this->read($competition, $club, $path, $extension);
        } catch (OpenSpoutException $exception) {
            report($exception);

            return new ClubImportPreview(fileErrors: [__('The file could not be read. Is it a valid XLSX or CSV file?')]);
        }
    }

    /**
     * Save a preview: every row in "all" mode (refused while any row has a problem), only the rows
     * without problems in "valid" mode.
     */
    public function commit(Club $club, ClubImportPreview $preview, string $mode = self::MODE_ALL): ImportEntriesResult
    {
        if (! in_array($mode, [self::MODE_ALL, self::MODE_VALID], true)) {
            throw new InvalidArgumentException('Unknown import mode "'.$mode.'".');
        }

        if ($preview->fileErrors !== []) {
            return new ImportEntriesResult(errors: $preview->fileErrors);
        }

        if ($mode === self::MODE_ALL && $preview->errorCount() > 0) {
            return new ImportEntriesResult(entriesSkipped: $preview->existingEntryCount(), errors: $preview->errors());
        }

        return DB::transaction(function () use ($club, $preview): ImportEntriesResult {
            $created = 0;
            $skipped = $preview->existingEntryCount();
            $competitorsCreated = 0;

            foreach ($preview->rows as $row) {
                if ($row->failed()) {
                    continue;
                }

                foreach ($row->planned as $item) {
                    if (! $row->competitor->exists) {
                        $row->competitor->club_id = $club->id;
                        $row->competitor->save();
                        $competitorsCreated++;
                    }

                    $entry = Entry::firstOrCreate(
                        ['category_id' => $item['category']->id, 'competitor_id' => $row->competitor->id],
                        ['weight' => $item['weight']],
                    );

                    $entry->wasRecentlyCreated ? $created++ : $skipped++;
                }
            }

            return new ImportEntriesResult($created, $skipped, $competitorsCreated, 0);
        });
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
            ->get(['category_id', 'competitor_id'])
            ->mapWithKeys(fn (Entry $entry) => [$entry->category_id.'|'.$entry->competitor_id => true]);

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
            $item['weight'] = $weight;

            if ($problems !== []) {
                $item['messages'] = [implode(' ', $problems)];
                $parsed[] = $item;

                continue;
            }

            $item['key'] = implode('|', [$row['first_name'], $row['last_name'], $birthDate->format('Y-m-d')]);
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

        return new ClubImportPreview($this->buildRows($parsed, $existingEntries), meta: $meta);
    }

    /**
     * Decide per row what is new and what is already entered. Rows with problems never take a spot, so
     * leaving them out later does not change what the other rows do.
     *
     * @param  array<int, array<string, mixed>>  $parsed
     * @param  Collection<string, bool>  $existingEntries
     * @return array<int, ClubImportRow>
     */
    private function buildRows(array $parsed, Collection $existingEntries): array
    {
        $seen = [];
        $rows = [];

        foreach ($parsed as $item) {
            $failed = $item['messages'] !== [];
            $entries = [];
            $planned = [];

            foreach ($item['candidates'] as $candidate) {
                /** @var Category $category */
                $category = $candidate['category'];
                $key = $category->id.'|'.$item['key'];
                $existing = isset($seen[$key]) || ($item['competitor']->exists && isset($existingEntries[$category->id.'|'.$item['competitor']->id]));

                $entries[] = [
                    'category' => $category->name,
                    'discipline' => $candidate['discipline']->value,
                    'weight' => $candidate['weight'],
                    'state' => $existing ? 'existing' : 'new',
                ];

                if (! $existing && ! $failed) {
                    $seen[$key] = true;
                    $planned[] = ['category' => $category, 'weight' => $candidate['weight']];
                }
            }

            $messages = $item['messages'];

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
